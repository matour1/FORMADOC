<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\PaymentLink;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\PaymentLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Webhook GeniusPay — signature sur corps BRUT, statut, idempotence.
 *
 * **Pourquoi ce fichier existe séparément du test unitaire du service.** Le
 * service sait calculer une signature ; c'est le CONTRÔLEUR qui doit lui passer
 * la bonne chaîne. Un contrôleur qui lit `$request->all()` puis `json_encode()`
 * produit une autre chaîne, et la signature ne correspond plus — le test unitaire
 * du service ne peut pas le voir, puisqu'il appelle la méthode directement.
 *
 * Le test envoie donc un **corps brut** par HTTP et vérifie le résultat observable :
 * crédit ou non.
 */
class GeniusPayWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_test_webhook';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'geniuspay.api_key' => 'pk_sandbox_test',
            'geniuspay.secret_key' => 'sk_sandbox_test',
            'geniuspay.webhook_secret' => self::SECRET,
            'geniuspay.webhook_tolerance_seconds' => 300,
            'geniuspay.currency' => 'XOF',
            'geniuspay.xaf_to_xof_rate' => 1.0,
            'billing.credit_packs' => [
                ['montant' => 1000, 'credits' => 1000, 'libelle' => 'Découverte', 'service' => null],
                ['montant' => 5000, 'credits' => 5000, 'libelle' => 'Projet', 'service' => null],
            ],
        ]);
    }

    private function utilisateur(): User
    {
        return User::factory()->create(['credits_balance' => 0]);
    }

    /**
     * Construit le corps brut et les en-têtes signés.
     *
     * **Le corps est une chaîne, pas un tableau.** C'est le point du test : on
     * signe EXACTEMENT ce qui sera envoyé.
     *
     * La structure suit la documentation officielle du SDK : `event`,
     * `timestamp`, `environment` à la racine, puis `data` qui porte DIRECTEMENT
     * les champs de la transaction (`reference`, `amount`, `status`,
     * `metadata`). Il n'y a PAS de niveau `data.transaction` — la première
     * version du contrôleur l'inventait, et tous les webhooks auraient été
     * rejetés.
     *
     * @return array{0: string, 1: array<string, string>}
     */
    private function payloadSigne(int $userId, int $montant, string $reference, string $statut = 'completed', ?int $timestamp = null): array
    {
        $corps = json_encode([
            'id' => 'evt_01HXY2K3M4N5P6Q7R8S',
            'event' => $statut === 'completed' ? 'payment.success' : 'payment.failed',
            'timestamp' => 1715000000,
            'environment' => 'sandbox',
            'data' => [
                'reference' => $reference,
                'amount' => $montant,
                'currency' => 'XOF',
                'status' => $statut,
                'payment_method' => 'wave',
                'metadata' => ['user_id' => $userId],
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $ts = (string) ($timestamp ?? time());
        $signature = hash_hmac('sha256', $ts.'.'.$corps, self::SECRET);

        return [$corps, [
            'X-Webhook-Signature' => $signature,
            'X-Webhook-Timestamp' => $ts,
            'X-Webhook-Event' => 'payment.success',
            'X-Webhook-Environment' => 'sandbox',
            'Content-Type' => 'application/json',
        ]];
    }

    // -------------------------------------------------------------------------
    // Contrôle positif
    // -------------------------------------------------------------------------

    /**
     * **Un webhook `completed` valide crédite le compte.**
     */
    public function test_un_webhook_valide_credite_le_compte(): void
    {
        $utilisateur = $this->utilisateur();
        [$corps, $entetes] = $this->payloadSigne($utilisateur->id, 1000, 'MTX-TEST-1');

        $this->call('POST', route('credits.notify.geniuspay'), [], [], [], $this->entetesServeur($entetes), $corps)
            ->assertOk();

        $this->assertSame(1000, $utilisateur->fresh()->credits_balance);
    }

    // -------------------------------------------------------------------------
    // Signature
    // -------------------------------------------------------------------------

    /**
     * **Une signature invalide ne crédite rien.**
     */
    public function test_une_signature_invalide_ne_credite_rien(): void
    {
        $utilisateur = $this->utilisateur();
        [$corps, $entetes] = $this->payloadSigne($utilisateur->id, 1000, 'MTX-TEST-2');
        $entetes['X-Webhook-Signature'] = 'signature-forgee';

        $this->call('POST', route('credits.notify.geniuspay'), [], [], [], $this->entetesServeur($entetes), $corps)
            ->assertOk();

        $this->assertSame(0, $utilisateur->fresh()->credits_balance);
    }

    /**
     * **La signature est calculée sur le corps BRUT, pas sur une re-sérialisation.**
     *
     * C'est le défaut central de cette intégration : le contrôleur doit lire
     * `php://input`. Si le jour où il lit `$request->all()` et re-sérialise, la
     * signature ne correspond plus et TOUS les webhooks échouent silencieusement.
     *
     * Le test signe un corps dont l'ordre des clés diffère de ce que produirait
     * `json_encode($request->all())` : si le contrôleur re-sérialisait, la
     * vérification échouerait.
     */
    public function test_la_signature_porte_sur_le_corps_brut(): void
    {
        $utilisateur = $this->utilisateur();

        // Ordre volontairement inversé (status avant reference) pour qu'une
        // re-sérialisation produise une chaîne différente.
        $corps = '{"event":"payment.success","environment":"sandbox","data":{'
            .'"status":"completed","reference":"MTX-BRUT-1","amount":1000,'
            .'"metadata":{"user_id":'.$utilisateur->id.'}}}';

        $ts = (string) time();
        $signature = hash_hmac('sha256', $ts.'.'.$corps, self::SECRET);

        $this->call('POST', route('credits.notify.geniuspay'), [], [], [], [
            'HTTP_X_WEBHOOK_SIGNATURE' => $signature,
            'HTTP_X_WEBHOOK_TIMESTAMP' => $ts,
            'CONTENT_TYPE' => 'application/json',
        ], $corps)->assertOk();

        $this->assertSame(1000, $utilisateur->fresh()->credits_balance);
    }

    /**
     * **Un horodatage périmé est refusé, même bien signé.**
     */
    public function test_un_horodatage_perime_ne_credite_rien(): void
    {
        $utilisateur = $this->utilisateur();
        [$corps, $entetes] = $this->payloadSigne(
            $utilisateur->id,
            1000,
            'MTX-VIEUX',
            'completed',
            time() - 3600
        );

        $this->call('POST', route('credits.notify.geniuspay'), [], [], [], $this->entetesServeur($entetes), $corps)
            ->assertOk();

        $this->assertSame(0, $utilisateur->fresh()->credits_balance);
    }

    // -------------------------------------------------------------------------
    // Statut et montant
    // -------------------------------------------------------------------------

    /**
     * **Un statut transitoire ne crédite rien.**
     *
     * `processing` n'est pas un succès : le paiement peut encore échouer.
     */
    public function test_un_statut_transitoire_ne_credite_rien(): void
    {
        $utilisateur = $this->utilisateur();
        [$corps, $entetes] = $this->payloadSigne($utilisateur->id, 1000, 'MTX-PROCESS', 'processing');

        $this->call('POST', route('credits.notify.geniuspay'), [], [], [], $this->entetesServeur($entetes), $corps)
            ->assertOk();

        $this->assertSame(0, $utilisateur->fresh()->credits_balance);
    }

    public function test_un_paiement_echoue_ne_credite_rien(): void
    {
        $utilisateur = $this->utilisateur();
        [$corps, $entetes] = $this->payloadSigne($utilisateur->id, 1000, 'MTX-FAIL', 'failed');

        $this->call('POST', route('credits.notify.geniuspay'), [], [], [], $this->entetesServeur($entetes), $corps)
            ->assertOk();

        $this->assertSame(0, $utilisateur->fresh()->credits_balance);
    }

    /**
     * **Le crédit suit le PALIER déclaré, pas le montant brut.**
     *
     * Le catalogue fait foi : un palier peut offrir des crédits bonus.
     */
    public function test_le_credit_suit_le_palier_du_catalogue(): void
    {
        config([
            'billing.credit_packs' => [
                ['montant' => 1000, 'credits' => 1200, 'libelle' => 'Bonus', 'service' => null],
            ],
        ]);

        $utilisateur = $this->utilisateur();
        [$corps, $entetes] = $this->payloadSigne($utilisateur->id, 1000, 'MTX-BONUS');

        $this->call('POST', route('credits.notify.geniuspay'), [], [], [], $this->entetesServeur($entetes), $corps)
            ->assertOk();

        $this->assertSame(1200, $utilisateur->fresh()->credits_balance);
    }

    // -------------------------------------------------------------------------
    // Corrélation et idempotence
    // -------------------------------------------------------------------------

    /**
     * **Sans `metadata.user_id`, rien n'est crédité.**
     */
    public function test_sans_correlation_rien_n_est_credite(): void
    {
        $this->utilisateur();

        $corps = '{"event":"payment.success","environment":"sandbox","data":{'
            .'"reference":"MTX-NOUSER","amount":1000,"status":"completed","metadata":{}}}';

        $ts = (string) time();
        $signature = hash_hmac('sha256', $ts.'.'.$corps, self::SECRET);

        $this->call('POST', route('credits.notify.geniuspay'), [], [], [], [
            'HTTP_X_WEBHOOK_SIGNATURE' => $signature,
            'HTTP_X_WEBHOOK_TIMESTAMP' => $ts,
            'CONTENT_TYPE' => 'application/json',
        ], $corps)->assertOk();

        $this->assertSame(0, User::sum('credits_balance'));
    }

    /**
     * **Un rejeu ne crédite jamais deux fois.**
     *
     * La signature ne porte pas de nonce : le rejeu immédiat reste valide. C'est
     * l'idempotence (`creditIfNotProcessed`) qui protège, et c'est le seul
     * mécanisme qui le peut.
     */
    public function test_un_rejeu_ne_credite_pas_deux_fois(): void
    {
        $utilisateur = $this->utilisateur();
        [$corps, $entetes] = $this->payloadSigne($utilisateur->id, 1000, 'MTX-REPLAY');

        $this->call('POST', route('credits.notify.geniuspay'), [], [], [], $this->entetesServeur($entetes), $corps)
            ->assertOk();
        $this->call('POST', route('credits.notify.geniuspay'), [], [], [], $this->entetesServeur($entetes), $corps)
            ->assertOk();

        $this->assertSame(1000, $utilisateur->fresh()->credits_balance, 'Un rejeu ne doit jamais doubler le versement.');
    }

    // -------------------------------------------------------------------------
    // CSRF
    // -------------------------------------------------------------------------

    /**
     * **La route est exemptée du CSRF.**
     *
     * Test d'architecture obligatoire : en environnement de test, Laravel
     * court-circuite `VerifyCsrfToken`, donc un POST sans jeton passerait même
     * sans exemption. La seule preuve valable est de lire la DÉCLARATION.
     */
    public function test_la_route_de_webhook_est_exemptee_du_csrf(): void
    {
        $bootstrap = (string) file_get_contents(base_path('bootstrap/app.php'));

        $this->assertMatchesRegularExpression(
            '/validateCsrfTokens\(except:\s*\[[^\]]*credits\/notify\/geniuspay/s',
            $bootstrap,
            'Sans exemption CSRF, Laravel répondrait 419 et le paiement ne serait jamais constaté.'
        );
    }

    // -------------------------------------------------------------------------
    // Abonnements et liens de paiement (D-006 / L2 : crédits ET abonnements)
    // -------------------------------------------------------------------------

    /**
     * **Un webhook GeniusPay `completed` avec `purpose=subscription` active l'abonnement et crée la facture.**
     */
    public function test_un_webhook_d_abonnement_active_le_plan_et_cree_la_facture(): void
    {
        $utilisateur = $this->utilisateur();
        $plan = Plan::factory()->create([
            'slug' => 'standard',
            'name' => 'Standard',
            'price_fcfa' => 3000,
            'is_active' => true,
        ]);

        $corps = (string) json_encode([
            'id' => 'evt_sub_1',
            'event' => 'payment.success',
            'timestamp' => time(),
            'environment' => 'sandbox',
            'data' => [
                'reference' => 'MTX-SUB-1',
                'amount' => 3000,
                'currency' => 'XOF',
                'status' => 'completed',
                'metadata' => [
                    'user_id' => $utilisateur->id,
                    'purpose' => 'subscription',
                    'plan_id' => $plan->id,
                    'plan_slug' => $plan->slug,
                    'amount_fcfa' => 3000,
                    'payment_method' => 'geniuspay',
                    'currency' => 'XAF',
                    'external_id' => 'SUB-'.$utilisateur->id.'-uuid',
                ],
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $ts = (string) time();
        $signature = hash_hmac('sha256', $ts.'.'.$corps, self::SECRET);

        $this->call('POST', route('credits.notify.geniuspay'), [], [], [], [
            'HTTP_X_WEBHOOK_SIGNATURE' => $signature,
            'HTTP_X_WEBHOOK_TIMESTAMP' => $ts,
            'CONTENT_TYPE' => 'application/json',
        ], $corps)->assertOk();

        $abonnement = Subscription::where('user_id', $utilisateur->id)->first();
        $this->assertNotNull($abonnement);
        $this->assertSame('active', $abonnement->status);
        $this->assertSame('geniuspay', $abonnement->payment_method);
        $this->assertSame($plan->id, $abonnement->plan_id);

        $facture = Invoice::where('user_id', $utilisateur->id)->first();
        $this->assertNotNull($facture);
        $this->assertSame(3000, $facture->amount);
        $this->assertSame('paid', $facture->status);
    }

    /**
     * **Un webhook GeniusPay `completed` avec `purpose=payment_link` règle le lien et verse les crédits.**
     */
    public function test_un_webhook_de_lien_de_paiement_regle_le_lien(): void
    {
        $destinataire = $this->utilisateur();
        $admin = User::factory()->create(['is_admin' => true]);

        $lien = app(PaymentLinkService::class)->creer(
            donnees: [
                'amount_fcfa' => 2500,
                'mode' => PaymentLink::MODE_EN_LIGNE,
                'label' => 'Lien GeniusPay',
                'user_id' => $destinataire->id,
            ],
            auteur: $admin,
        );

        $corps = (string) json_encode([
            'id' => 'evt_link_1',
            'event' => 'payment.success',
            'timestamp' => time(),
            'environment' => 'sandbox',
            'data' => [
                'reference' => 'MTX-LINK-1',
                'amount' => 2500,
                'currency' => 'XOF',
                'status' => 'completed',
                'metadata' => [
                    'payment_link_id' => $lien->id,
                    'user_id' => $destinataire->id,
                    'purpose' => 'payment_link',
                    'credits' => 2500,
                ],
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $ts = (string) time();
        $signature = hash_hmac('sha256', $ts.'.'.$corps, self::SECRET);

        $this->call('POST', route('credits.notify.geniuspay'), [], [], [], [
            'HTTP_X_WEBHOOK_SIGNATURE' => $signature,
            'HTTP_X_WEBHOOK_TIMESTAMP' => $ts,
            'CONTENT_TYPE' => 'application/json',
        ], $corps)->assertOk();

        $this->assertSame(PaymentLink::STATUT_PAYE, $lien->fresh()->status);
        $this->assertSame(2500, $destinataire->fresh()->credits_balance);
    }

    /**
     * Convertit les en-têtes nommés en variables serveur pour `call()`.
     *
     * @param  array<string, string>  $entetes
     * @return array<string, string>
     */
    private function entetesServeur(array $entetes): array
    {
        $serveur = [];

        foreach ($entetes as $nom => $valeur) {
            if (mb_strtolower($nom) === 'content-type') {
                $serveur['CONTENT_TYPE'] = $valeur;

                continue;
            }

            $serveur['HTTP_'.mb_strtoupper(str_replace('-', '_', $nom))] = $valeur;
        }

        return $serveur;
    }
}
