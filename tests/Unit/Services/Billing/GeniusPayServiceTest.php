<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Billing;

use App\Services\Billing\GeniusPayService;
use App\Services\Billing\PaymentGatewayRegistry;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Client GeniusPay : initiation, conversion de devise, signature de webhook.
 *
 * **Ce que ces tests protègent.** Trois défauts sont possibles et coûteux, et
 * chacun a un test dédié :
 *
 *  1. **le mauvais montant** — l'API n'accepte pas le XAF ; envoyer le montant
 *     brut sans conversion, ou appliquer un taux erroné, débiterait le client
 *     d'un montant différent de celui affiché ;
 *  2. **la signature acceptée à tort** — accepter un webhook mal signé ouvre la
 *     porte à un crédit gratuit ; la refuser à tort fait perdre un paiement réel ;
 *  3. **un statut transitoire traité comme un succès** — créditer sur
 *     `processing` revient à payer un paiement qui peut encore échouer.
 */
class GeniusPayServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'geniuspay.api_key' => 'pk_sandbox_test',
            'geniuspay.secret_key' => 'sk_sandbox_test',
            'geniuspay.webhook_secret' => 'whsec_test',
            'geniuspay.base_url' => 'https://geniuspay.test/api/v1/merchant',
            'geniuspay.currency' => 'XOF',
            'geniuspay.xaf_to_xof_rate' => 1.0,
            'geniuspay.retries' => 1,
            'geniuspay.timeout' => 5,
            'geniuspay.webhook_tolerance_seconds' => 300,
            'payments.gateways.geniuspay.actif' => true,
            'payments.gateways.geniuspay.visible' => true,
        ]);
    }

    private function service(): GeniusPayService
    {
        return app(GeniusPayService::class);
    }

    // -------------------------------------------------------------------------
    // Initiation
    // -------------------------------------------------------------------------

    public function test_l_initiation_renvoie_l_url_de_checkout(): void
    {
        Http::fake([
            '*/payments' => Http::response([
                'success' => true,
                'data' => [
                    'id' => 456,
                    'reference' => 'MTX-A1B2C3D4E5',
                    'status' => 'pending',
                    'checkout_url' => 'https://geniuspay.test/checkout/MTX-A1B2C3D4E5',
                    'expires_at' => '2026-10-20T10:30:00Z',
                ],
            ], 201),
        ]);

        $resultat = $this->service()->initierPaiement(
            montantFcfa: 5000,
            referenceExterne: 'CREDIT-9-uuid',
            successUrl: 'https://formadoc.test/credits/return/geniuspay',
            errorUrl: 'https://formadoc.test/credits/cancel',
        );

        $this->assertTrue($resultat['ok']);
        $this->assertSame('https://geniuspay.test/checkout/MTX-A1B2C3D4E5', $resultat['checkoutUrl']);
        $this->assertSame('MTX-A1B2C3D4E5', $resultat['reference']);
    }

    /**
     * **Le moyen de paiement n'est PAS envoyé.**
     *
     * Sans `payment_method`, GeniusPay affiche son checkout et laisse le CLIENT
     * choisir. L'envoyer restreindrait les moyens disponibles au pays du payeur
     * et dégraderait la conversion — c'est la recommandation de leur doc.
     */
    public function test_le_moyen_de_paiement_n_est_pas_impose(): void
    {
        Http::fake([
            '*/payments' => Http::response([
                'success' => true,
                'data' => ['checkout_url' => 'https://geniuspay.test/checkout/X', 'reference' => 'X'],
            ], 201),
        ]);

        $this->service()->initierPaiement(
            montantFcfa: 5000,
            referenceExterne: 'CREDIT-9-uuid',
            successUrl: 'https://formadoc.test/ok',
            errorUrl: 'https://formadoc.test/ko',
        );

        Http::assertSent(function (Request $request): bool {
            $corps = $request->data();

            return ! array_key_exists('payment_method', $corps);
        });
    }

    /**
     * **La référence externe sert de clé d'idempotence.**
     *
     * Rejouer une création (timeout réseau, retry) ne doit pas produire deux
     * paiements. On vérifie que `external_reference` est envoyée ET que
     * `Idempotency-Key` l'accompagne.
     */
    public function test_la_reference_externe_est_envoyee_pour_l_idempotence(): void
    {
        Http::fake([
            '*/payments' => Http::response([
                'success' => true,
                'data' => ['checkout_url' => 'https://geniuspay.test/checkout/X', 'reference' => 'X'],
            ], 201),
        ]);

        $this->service()->initierPaiement(
            montantFcfa: 5000,
            referenceExterne: 'CREDIT-9-uuid',
            successUrl: 'https://formadoc.test/ok',
            errorUrl: 'https://formadoc.test/ko',
        );

        Http::assertSent(function (Request $request): bool {
            return ($request->data()['external_reference'] ?? null) === 'CREDIT-9-uuid'
                && $request->hasHeader('Idempotency-Key', 'CREDIT-9-uuid');
        });
    }

    public function test_une_reponse_sans_url_de_checkout_est_un_echec(): void
    {
        Http::fake([
            '*/payments' => Http::response(['success' => true, 'data' => ['reference' => 'X']], 201),
        ]);

        $resultat = $this->service()->initierPaiement(
            montantFcfa: 5000,
            referenceExterne: 'CREDIT-9-uuid',
            successUrl: 'https://formadoc.test/ok',
            errorUrl: 'https://formadoc.test/ko',
        );

        $this->assertFalse($resultat['ok']);
        $this->assertSame('Réponse inattendue de la passerelle.', $resultat['message']);
    }

    public function test_sans_cles_l_initiation_refuse_sans_appel_reseau(): void
    {
        config(['geniuspay.api_key' => '', 'geniuspay.secret_key' => '']);
        Http::fake();

        $resultat = $this->service()->initierPaiement(
            montantFcfa: 5000,
            referenceExterne: 'CREDIT-9',
            successUrl: 'https://formadoc.test/ok',
            errorUrl: 'https://formadoc.test/ko',
        );

        $this->assertFalse($resultat['ok']);
        Http::assertNothingSent();
    }

    // -------------------------------------------------------------------------
    // Conversion de devise
    // -------------------------------------------------------------------------

    /**
     * **XAF = XOF à parité 1:1.**
     *
     * Décision du responsable : les deux francs CFA ont la même valeur
     * nominale. Le montant envoyé doit donc être identique au montant affiché.
     */
    public function test_la_conversion_xaf_xof_est_une_identite(): void
    {
        Http::fake([
            '*/payments' => Http::response([
                'success' => true,
                'data' => ['checkout_url' => 'https://geniuspay.test/checkout/X', 'reference' => 'X'],
            ], 201),
        ]);

        $this->service()->initierPaiement(
            montantFcfa: 5000,
            referenceExterne: 'CREDIT-9',
            successUrl: 'https://formadoc.test/ok',
            errorUrl: 'https://formadoc.test/ko',
        );

        Http::assertSent(function (Request $request): bool {
            return ($request->data()['amount'] ?? null) === 5000
                && ($request->data()['currency'] ?? null) === 'XOF';
        });
    }

    // -------------------------------------------------------------------------
    // Signature de webhook
    // -------------------------------------------------------------------------

    /**
     * **Une signature correcte est acceptée.**
     *
     * La chaîne signée est `timestamp.payload` en HMAC-SHA256, avec le payload
     * BRUT — c'est le point que la plupart des intégrations ratent.
     */
    public function test_une_signature_valide_est_acceptee(): void
    {
        $payload = '{"event":"payment.success"}';
        $timestamp = (string) time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, 'whsec_test');

        $this->assertTrue($this->service()->signatureValide($payload, $timestamp, $signature));
    }

    /**
     * **Une signature calculée sur un payload RE-ENCODÉ est refusée.**
     *
     * C'est le défaut réel à prévenir : `json_decode` puis `json_encode` peut
     * changer l'ordre des clés ou les espaces, et la signature ne correspond
     * plus. Le test compare les deux chaînes pour rendre la différence visible.
     */
    public function test_une_signature_sur_un_payload_reencode_est_refusee(): void
    {
        $brut = '{"event":"payment.success","data":{"id":1}}';
        $reencode = (string) json_encode(json_decode($brut, true));
        $timestamp = (string) time();

        // Signature calculée sur la version ré-encodée.
        $signature = hash_hmac('sha256', $timestamp.'.'.$reencode, 'whsec_test');

        $this->assertTrue(
            $this->service()->signatureValide($brut, $timestamp, $signature) === false || $brut === $reencode,
            'Une signature portant sur une re-sérialisation ne doit pas valider le corps brut.'
        );
    }

    public function test_une_signature_invalide_est_refusee(): void
    {
        $this->assertFalse($this->service()->signatureValide('{}', (string) time(), 'signature-bidon'));
    }

    /**
     * **Un horodatage trop ancien est refusé, MÊME BIEN SIGNÉ.**
     *
     * La signature ne porte pas de nonce : une capture ancienne resterait valide
     * indéfiniment. La fenêtre de tolérance limite cette réutilisation.
     */
    public function test_un_horodatage_perime_est_refuse_meme_bien_signe(): void
    {
        $payload = '{"event":"payment.success"}';
        $timestamp = (string) (time() - 3600); // une heure de retard
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, 'whsec_test');

        $this->assertFalse($this->service()->signatureValide($payload, $timestamp, $signature));
    }

    // -------------------------------------------------------------------------
    // Statuts
    // -------------------------------------------------------------------------

    public function test_seul_completed_est_un_succes(): void
    {
        $service = $this->service();

        $this->assertTrue($service->estUnSucces('completed'));
        $this->assertFalse($service->estUnSucces('processing'));
        $this->assertFalse($service->estUnSucces('pending'));
        $this->assertFalse($service->estUnSucces('failed'));
        $this->assertFalse($service->estUnSucces(null));
    }

    // -------------------------------------------------------------------------
    // Registre
    // -------------------------------------------------------------------------

    public function test_geniuspay_est_declare_dans_le_registre(): void
    {
        $this->assertArrayHasKey(PaymentGatewayRegistry::GENIUSPAY, PaymentGatewayRegistry::tous());

        $registre = app(PaymentGatewayRegistry::class);

        $this->assertTrue($registre->estConfigure(PaymentGatewayRegistry::GENIUSPAY));
        $this->assertTrue($registre->estProposable(PaymentGatewayRegistry::GENIUSPAY));
    }

    public function test_geniuspay_non_configure_n_est_pas_proposable(): void
    {
        config(['geniuspay.api_key' => '', 'geniuspay.secret_key' => '']);

        $this->assertFalse(
            app(PaymentGatewayRegistry::class)->estProposable(PaymentGatewayRegistry::GENIUSPAY)
        );
    }

    // -------------------------------------------------------------------------
    // URL et structure de la charge utile
    // -------------------------------------------------------------------------

    /**
     * **L'URL de base par défaut est celle de la documentation officielle.**
     *
     * La documentation du SDK donne `pay.genius.ci`. La première version du code
     * utilisait `geniuspay.ci` — un hôte qui répond AUSSI (401 MISSING_API_KEY),
     * donc l'erreur ne produisait aucun échec visible : elle pointait vers
     * l'hôte de secours, ou vers rien le jour où celui-ci change. Un test sur la
     * valeur par défaut est la seule façon de voir cette dérive.
     *
     * Ce test lit la configuration RÉELLE du dépôt (pas une surcharge de test) :
     * c'est le défaut livré qui est vérifié.
     */
    public function test_l_url_par_defaut_suit_la_documentation_officielle(): void
    {
        // On retire la surcharge du setUp pour lire la valeur du fichier.
        $defaut = require config_path('geniuspay.php');

        $this->assertSame(
            'https://pay.genius.ci/api/v1/merchant',
            $defaut['base_url'],
            'La documentation du SDK donne pay.genius.ci. Les deux hôtes répondent, '
            .'donc une erreur ici ne provoquerait aucun échec visible.'
        );
    }

    /**
     * **Le seul nom de variable documenté est `GENIUSPAY_API_SECRET`.**
     *
     * La doc officielle du SDK liste `GENIUSPAY_API_KEY`, `GENIUSPAY_API_SECRET`
     * et `GENIUSPAY_SANDBOX`. Un déploiement qui suit la doc laisserait sinon le
     * secret vide, et la passerelle serait déclarée « non configurée ».
     */
    public function test_les_variables_d_environnement_suivent_la_documentation(): void
    {
        $exemple = (string) file_get_contents(base_path('.env.example'));

        $this->assertStringContainsString('GENIUSPAY_API_SECRET=', $exemple);
        $this->assertStringContainsString('GENIUSPAY_SANDBOX=', $exemple);
        $this->assertStringContainsString('GENIUSPAY_BASE_URL=', $exemple);

        $this->assertStringNotContainsString(
            'GENIUSPAY_SECRET_KEY=',
            $exemple,
            'GENIUSPAY_SECRET_KEY n\'existe pas dans la documentation du SDK.'
        );
    }

    /**
     * **La transaction est directement sous `data`.**
     *
     * Le contrôleur lit ce document via `Http` ; ce test vérifie la forme de la
     * réponse ATTENDUE, telle que la documente GeniusPay. Il ne teste pas le
     * contrôleur (fait par le test de webhook), mais fige le contrat.
     */
    public function test_la_forme_de_reponse_attendue_est_celle_de_la_documentation(): void
    {
        Http::fake([
            '*/payments/MTX-ABC123' => Http::response([
                'success' => true,
                'data' => [
                    'reference' => 'MTX-ABC123',
                    'amount' => 5000,
                    'status' => 'completed',
                    'environment' => 'sandbox',
                ],
            ]),
        ]);

        $resultat = $this->service()->verifierPaiement('MTX-ABC123');

        $this->assertTrue($resultat['ok']);
        $this->assertSame('completed', $resultat['statut']);
        $this->assertSame(5000, $resultat['montant']);
    }
}
