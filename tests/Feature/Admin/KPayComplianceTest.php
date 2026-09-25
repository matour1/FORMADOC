<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\CreditTransaction;
use App\Models\KpayPayment;
use App\Models\PaymentLink;
use App\Models\User;
use App\Services\Billing\KPayService;
use App\Services\Billing\PaymentLinkService;
use App\Services\Settings\SettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Conformité des flux de paiement avec la documentation KPay.
 *
 * **Pourquoi ces tests existent alors que le service est en maintenance.** KPay
 * était entièrement indisponible pendant l'écriture de ces flux — impossible donc
 * d'exercer le moindre appel réseau. C'est précisément ce qui rend ces tests
 * nécessaires : ils vérifient le CODE et ses invariants, pas la disponibilité du
 * service. Quand KPay rouvrira, c'est la disponibilité qui variera, pas les règles
 * documentées ici.
 *
 * **Les deux défauts que ces tests rendent impossibles.** Tous deux étaient
 * silencieux — aucun n'aurait produit d'erreur, seulement un comportement faux :
 *
 *  1. L'horodatage du retour passerelle est en MILLISECONDES (`ts=1747245600000`
 *     dans la documentation), alors que `time()` est en SECONDES. Comparer les deux
 *     rejetait TOUT retour légitime : le client était débité, et la page annonçait
 *     un échec de vérification.
 *  2. Le purpose `payment_link` était émis à l'initiation mais jamais traité par le
 *     webhook. Un lien réglé en ligne tombait alors dans le chemin « achat de
 *     crédits », qui créditait le montant SANS marquer le lien comme payé — donc
 *     payable une seconde fois, et sans lien avec la transaction d'origine.
 */
class KPayComplianceTest extends TestCase
{
    use RefreshDatabase;

    private function kpay(): KPayService
    {
        return app(KPayService::class);
    }

    /**
     * Signature de retour, comme KPay la calcule : HMAC-SHA256 hex de
     * « status|reference|externalId|ts » avec la clé secrète.
     *
     * @return array<string, string>
     */
    private function retourSigne(string $status, string $reference, string $externalId, int $ts): array
    {
        $chaine = $status.'|'.$reference.'|'.$externalId.'|'.$ts;

        return [
            'status' => $status,
            'reference' => $reference,
            'externalId' => $externalId,
            'ts' => (string) $ts,
            'sig' => hash_hmac('sha256', $chaine, (string) config('kpay.secret_key')),
        ];
    }

    // -------------------------------------------------------------------------
    // Signature du retour passerelle
    // -------------------------------------------------------------------------

    /**
     * **Le défaut le plus coûteux.** L'horodatage est en millisecondes.
     *
     * La documentation ne le dit qu'implicitement — par l'exemple
     * `ts=1747245600000`, treize chiffres. Le code comparait cette valeur à
     * `time()` (secondes), donc l'écart valait ~1,7 × 10¹² au lieu de quelques
     * dizaines, et la fenêtre anti-rejeu de 10 minutes rejetait tout.
     */
    public function test_un_retour_signe_en_millisecondes_est_accepte(): void
    {
        $ts = (int) (microtime(true) * 1000);

        $this->assertTrue(
            $this->kpay()->verifyReturnSignature($this->retourSigne('COMPLETED', 'KPAY-1', 'ORDER-1', $ts)),
            'Un retour passerelle légitime doit être accepté. La documentation KPay fournit '
            .'ts=1747245600000, soit des MILLISECONDES : comparer cet horodatage à time() '
            .'(secondes) rejette tout retour, donc TOUT paiement réussi est annoncé en échec.'
        );
    }

    /**
     * Tolérance aux secondes.
     *
     * Si KPay normalisait un jour son horodatage, le code ne doit pas casser. La
     * conversion repose sur un seuil de chiffres, fiable pour des décennies.
     */
    public function test_un_retour_signe_en_secondes_est_accepte_aussi(): void
    {
        $ts = time();

        $this->assertTrue($this->kpay()->verifyReturnSignature($this->retourSigne('COMPLETED', 'KPAY-1', 'ORDER-1', $ts)));
    }

    public function test_un_retour_de_plus_de_dix_minutes_est_refuse(): void
    {
        // Règle explicite de la documentation : « Rejetez si ts a plus de 10 minutes ».
        $ts = (int) ((microtime(true) - 700) * 1000);

        $this->assertFalse($this->kpay()->verifyReturnSignature($this->retourSigne('COMPLETED', 'KPAY-1', 'ORDER-1', $ts)));
    }

    public function test_une_signature_invalide_est_refusee(): void
    {
        $query = $this->retourSigne('COMPLETED', 'KPAY-1', 'ORDER-1', (int) (microtime(true) * 1000));
        $query['sig'] = 'deadbeef';

        $this->assertFalse($this->kpay()->verifyReturnSignature($query));
    }

    public function test_un_statut_modifie_invalide_la_signature(): void
    {
        // Le statut fait partie de la chaîne signée : le modifier doit invalider la
        // signature, sinon un statut pourrait être changé en transit.
        $query = $this->retourSigne('FAILED', 'KPAY-1', 'ORDER-1', (int) (microtime(true) * 1000));
        $query['status'] = 'COMPLETED';

        $this->assertFalse($this->kpay()->verifyReturnSignature($query));
    }

    public function test_un_retour_sans_secret_configure_est_refuse(): void
    {
        config(['kpay.secret_key' => '']);

        $query = $this->retourSigne('COMPLETED', 'KPAY-1', 'ORDER-1', (int) (microtime(true) * 1000));

        $this->assertFalse(
            $this->kpay()->verifyReturnSignature($query),
            'Sans secret, la vérification doit ÉCHOUER et non réussir : un défaut permissif '
            .'transformerait une configuration incomplète en faille.'
        );
    }

    // -------------------------------------------------------------------------
    // Signature du webhook
    // -------------------------------------------------------------------------

    public function test_la_signature_du_webhook_porte_sur_le_corps_brut(): void
    {
        $corps = '{"event":"payment.completed","status":"COMPLETED"}';
        $signature = hash_hmac('sha256', $corps, (string) config('kpay.webhook_secret'));

        $this->assertTrue($this->kpay()->verifyWebhookSignature($corps, $signature));

        // Le corps modifié doit invalider la signature : c'est tout l'intérêt de
        // signer le corps BRUT plutôt qu'un tableau re-sérialisé.
        $this->assertFalse($this->kpay()->verifyWebhookSignature($corps.' ', $signature));
    }

    public function test_la_signature_du_webhook_est_distincte_de_celle_du_retour(): void
    {
        // La documentation insiste : « Cette signature est distincte de la signature
        // de retour passerelle. » Les deux secrets ne doivent pas être interchangeables.
        config([
            'kpay.webhook_secret' => 'secret_webhook',
            'kpay.secret_key' => 'secret_retour',
        ]);

        $corps = '{}';
        $sigWebhook = hash_hmac('sha256', $corps, 'secret_webhook');
        $sigRetour = hash_hmac('sha256', $corps, 'secret_retour');

        $this->assertTrue($this->kpay()->verifyWebhookSignature($corps, $sigWebhook));
        $this->assertFalse(
            $this->kpay()->verifyWebhookSignature($corps, $sigRetour),
            'Le secret du retour passerelle ne doit pas valider un webhook : les deux rôles '
            .'ont des secrets distincts, précisément pour qu\'une fuite de l\'un ne compromette pas l\'autre.'
        );
    }

    // -------------------------------------------------------------------------
    // Webhook : lien de paiement
    // -------------------------------------------------------------------------

    /**
     * **Le second défaut.** Le purpose `payment_link` doit être traité.
     *
     * Sans cette branche, un lien réglé en ligne tombait dans le chemin « achat de
     * crédits » : le montant était crédité, mais le lien restait « en attente » —
     * donc payable une seconde fois — et le versement n'était relié à aucun lien.
     */
    public function test_un_webhook_de_lien_de_paiement_marque_le_lien_paye(): void
    {
        $destinataire = User::factory()->create(['credits_balance' => 0]);
        $admin = User::factory()->create(['is_admin' => true]);

        $lien = app(PaymentLinkService::class)->creer(
            donnees: [
                'amount_fcfa' => 1500,
                'mode' => PaymentLink::MODE_EN_LIGNE,
                'user_id' => $destinataire->id,
            ],
            auteur: $admin,
        );

        KpayPayment::create([
            'user_id' => $destinataire->id,
            'payment_id' => 'pay_link_1',
            'external_id' => 'LINK-1-abc',
            'status' => 'PENDING',
            'purpose' => 'payment_link',
            'amount_fcfa' => 1500,
            'currency' => 'XAF',
            'metadata' => ['payment_link_id' => $lien->id],
        ]);

        $payload = [
            'event' => 'payment.completed',
            'paymentId' => 'pay_link_1',
            'status' => 'COMPLETED',
            'amount' => 1500,
            'externalId' => 'LINK-1-abc',
            'metadata' => [
                'payment_link_id' => $lien->id,
                'purpose' => 'payment_link',
                'credits' => 1500,
            ],
        ];

        $corps = json_encode($payload, JSON_UNESCAPED_SLASHES);

        $this->postJson(route('kpay.webhook'), $payload, [
            'X-KPAY-Signature' => hash_hmac('sha256', $corps, (string) config('kpay.webhook_secret')),
            'X-KPAY-Event' => 'payment.completed',
        ])->assertOk();

        $lien->refresh();

        $this->assertSame(PaymentLink::STATUT_PAYE, $lien->status,
            'Le lien doit être marqué payé. Sans cette branche, il restait « en attente » '
            .'alors que le montant avait été crédité — donc payable une seconde fois.');
        $this->assertSame(1500, $destinataire->fresh()->credits_balance);
        $this->assertSame('pay_link_1', $lien->payment_reference);
        $this->assertNotNull($lien->kpay_payment_id, 'Le lien doit référencer le paiement KPay.');
    }

    public function test_un_webhook_de_lien_duplique_ne_verse_qu_une_fois(): void
    {
        // KPay réessaie (3 tentatives, backoff 1s/2s/4s) : le doublon est nominal.
        $destinataire = User::factory()->create(['credits_balance' => 0]);
        $admin = User::factory()->create(['is_admin' => true]);

        $lien = app(PaymentLinkService::class)->creer(
            donnees: ['amount_fcfa' => 800, 'mode' => PaymentLink::MODE_EN_LIGNE, 'user_id' => $destinataire->id],
            auteur: $admin,
        );

        $payload = [
            'event' => 'payment.completed',
            'paymentId' => 'pay_dup',
            'status' => 'COMPLETED',
            'amount' => 800,
            'externalId' => 'LINK-DUP',
            'metadata' => ['payment_link_id' => $lien->id, 'purpose' => 'payment_link', 'credits' => 800],
        ];

        $corps = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $entetes = ['X-KPAY-Signature' => hash_hmac('sha256', $corps, (string) config('kpay.webhook_secret'))];

        $this->postJson(route('kpay.webhook'), $payload, $entetes)->assertOk();
        $this->postJson(route('kpay.webhook'), $payload, $entetes)->assertOk();

        $this->assertSame(800, $destinataire->fresh()->credits_balance, 'Un webhook réessayé ne doit pas doubler le solde.');
    }

    public function test_un_webhook_non_signe_est_refuse(): void
    {
        $this->postJson(route('kpay.webhook'), ['event' => 'payment.completed'], [
            'X-KPAY-Signature' => 'fausse',
        ])->assertStatus(401);
    }

    public function test_un_webhook_de_statut_non_terminal_est_ignore(): void
    {
        // Règle explicite : « ne marquez jamais une commande payée sur leur seule
        // réception » — payment.initiated et payment.processing sont informatifs.
        $payload = [
            'event' => 'payment.processing',
            'paymentId' => 'pay_x',
            'status' => 'PROCESSING',
            'externalId' => 'ORDER-X',
            'metadata' => [],
        ];

        $corps = json_encode($payload, JSON_UNESCAPED_SLASHES);

        $this->postJson(route('kpay.webhook'), $payload, [
            'X-KPAY-Signature' => hash_hmac('sha256', $corps, (string) config('kpay.webhook_secret')),
        ])
            ->assertOk()
            ->assertJson(['status' => 'ignored_non_terminal']);

        $this->assertSame(0, CreditTransaction::count());
    }

    // -------------------------------------------------------------------------
    // Réglage de la fenêtre de validité
    // -------------------------------------------------------------------------

    /**
     * La durée de validité des liens vient du réglage, pas d'une constante.
     *
     * C'est ce qui relie la configuration ajoutée depuis l'administration au
     * comportement réel — sans ce test, le réglage pourrait n'avoir aucun effet.
     */
    public function test_la_duree_de_validite_des_liens_suit_le_reglage(): void
    {
        app(SettingsRepository::class)->set('payments.link_ttl_days', 21, 'int');

        $lien = app(PaymentLinkService::class)->creer(
            donnees: ['amount_fcfa' => 600, 'mode' => PaymentLink::MODE_HORS_LIGNE, 'user_id' => null],
            auteur: User::factory()->create(['is_admin' => true]),
        );

        $this->assertSame(
            21,
            (int) $lien->created_at->diffInDays($lien->expires_at),
            'Le réglage « Validité d\'un lien de paiement » doit piloter la durée réelle.'
        );
    }
}
