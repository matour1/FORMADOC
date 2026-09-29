<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PaymentLink;
use App\Models\User;
use App\Services\Billing\MonetbilService;
use App\Services\Billing\PaymentGatewayRegistry;
use App\Services\Billing\PaymentLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Choix du moyen de paiement et notification Monetbil.
 *
 * **Ce que ces tests protègent.** Le choix du moyen arrive par un FORMULAIRE, donc il
 * est manipulable. Trois défauts sont possibles :
 *
 *  1. **Un moyen non proposable imposé par le client.** Le formulaire pourrait
 *     demander une passerelle désactivée, masquée ou sans clé. Sans revalidation
 *     serveur, le client obtiendrait une redirection vers un fournisseur coupé.
 *  2. **Un versement déclenché par une notification non vérifiée.** N'importe qui
 *     pourrait annoncer un paiement en POSTant sur l'URL de notification.
 *  3. **Un versement déclenché par le statut ANNONCÉ plutôt que vérifié.** La
 *     signature Monetbil ne porte pas d'horodatage : une notification capturée est
 *     rejouable. Seul l'appel à l'API de vérification fait foi.
 */
class PaymentGatewayChoiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'kpay.api_key' => 'kpay_cle',
            'kpay.secret_key' => 'kpay_secret',
            'monetbil.service_key' => 'mnb_cle',
            'monetbil.service_secret' => 'mnb_secret',
            'payments.gateways.kpay.actif' => true,
            'payments.gateways.kpay.visible' => true,
            'payments.gateways.monetbil.actif' => true,
            'payments.gateways.monetbil.visible' => true,
        ]);
    }

    private function lienEnLigne(): PaymentLink
    {
        $destinataire = User::factory()->create(['credits_balance' => 0]);

        return app(PaymentLinkService::class)->creer(
            donnees: [
                'amount_fcfa' => 2500,
                'mode' => PaymentLink::MODE_EN_LIGNE,
                'label' => 'Test passerelle',
                'user_id' => $destinataire->id,
            ],
            auteur: User::factory()->create(['is_admin' => true]),
        );
    }

    // -------------------------------------------------------------------------
    // Choix du moyen
    // -------------------------------------------------------------------------

    /**
     * La page de règlement propose les moyens disponibles.
     */
    public function test_la_page_propose_les_moyens_disponibles(): void
    {
        $lien = $this->lienEnLigne();

        $this->get(route('payment-link.show', $lien->token))
            ->assertOk()
            ->assertSee('Mobile Money et carte (KPay)')
            ->assertSee('Mobile Money (Monetbil)');
    }

    /**
     * Un moyen désactivé n'apparaît pas sur la page.
     *
     * L'exploitant a coupé Monetbil : le client ne doit pas pouvoir le choisir, et ne
     * doit même pas le voir — un moyen affiché mais inutilisable est une source
     * d'appels au support.
     */
    public function test_un_moyen_desactive_n_apparait_pas(): void
    {
        config(['payments.gateways.monetbil.actif' => false]);

        $lien = $this->lienEnLigne();

        $this->get(route('payment-link.show', $lien->token))
            ->assertOk()
            ->assertSee('Mobile Money et carte (KPay)')
            ->assertDontSee('Mobile Money (Monetbil)');
    }

    /**
     * **Un moyen non proposable est REFUSÉ même s'il est demandé dans le formulaire.**
     *
     * C'est le test le plus important de cette classe. Le champ `gateway` vient du
     * client : le modifier est trivial. Sans revalidation serveur, un client pourrait
     * obtenir une redirection vers une passerelle que l'exploitant a explicitement
     * coupée — et lui faire croire qu'il peut payer alors que non.
     */
    public function test_un_moyen_non_proposable_est_refuse_meme_demande(): void
    {
        config(['payments.gateways.monetbil.actif' => false]);

        $lien = $this->lienEnLigne();

        Http::fake();

        $this->post(route('payment-link.payer', $lien->token), [
            'gateway' => PaymentGatewayRegistry::MONETBIL,
        ])->assertRedirect(route('payment-link.show', $lien->token))
            ->assertSessionHas('error');

        // Aucun appel réseau : le refus est prononcé AVANT toute tentative.
        Http::assertNothingSent();
    }

    /**
     * Un moyen INCONNU est refusé.
     *
     * Le registre ne le connaît pas, donc `estProposable()` retourne `false`. Sans ce
     * comportement, une passerelle non implémentée pourrait être demandée et le code
     * tomberait dans la branche KPay par défaut — un client choisissant « autre »
     * serait envoyé vers KPay.
     */
    public function test_un_moyen_inconnu_est_refuse(): void
    {
        $lien = $this->lienEnLigne();

        Http::fake();

        $this->post(route('payment-link.payer', $lien->token), ['gateway' => 'paypal'])
            ->assertRedirect(route('payment-link.show', $lien->token))
            ->assertSessionHas('error');

        Http::assertNothingSent();
    }

    /**
     * **Le choix de Monetbil est routé vers Monetbil, pas vers KPay.**
     *
     * Le test vérifie l'URL appelée, et pas seulement la redirection : une erreur de
     * routage enverrait le client chez le mauvais fournisseur, et le paiement
     * aboutirait sur une transaction que personne ne rapprocherait.
     */
    public function test_le_choix_monetbil_est_route_vers_monetbil(): void
    {
        $lien = $this->lienEnLigne();

        Http::fake([
            'www.monetbil.com/*' => Http::response(
                ['payment_url' => 'https://www.monetbil.com/pay/v2.1/xyz'],
                200,
            ),
        ]);

        $this->post(route('payment-link.payer', $lien->token), [
            'gateway' => PaymentGatewayRegistry::MONETBIL,
        ])->assertRedirect('https://www.monetbil.com/pay/v2.1/xyz');

        // La passerelle est FIGÉE sur le lien : la synchronisation de secours en
        // dépend, et un lien réglé par Monetbil doit le rester même si la
        // configuration change ensuite.
        $this->assertSame(
            PaymentGatewayRegistry::MONETBIL,
            $lien->fresh()->gateway,
        );
    }

    /**
     * Le choix de KPay est routé vers KPay.
     */
    public function test_le_choix_kpay_est_route_vers_kpay(): void
    {
        $lien = $this->lienEnLigne();

        Http::fake([
            'admin.kpay.site/*' => Http::response([
                'gatewayUrl' => 'https://admin.kpay.site/pay/abc',
                'paymentId' => 'pay_123',
                'externalId' => 'LINK'.$lien->id,
            ], 201),
        ]);

        $this->post(route('payment-link.payer', $lien->token), [
            'gateway' => PaymentGatewayRegistry::KPAY,
        ])->assertRedirect('https://admin.kpay.site/pay/abc');

        $this->assertSame(
            PaymentGatewayRegistry::KPAY,
            $lien->fresh()->gateway,
        );
    }

    // -------------------------------------------------------------------------
    // Notification Monetbil
    // -------------------------------------------------------------------------

    /**
     * **Une notification sans signature valide ne verse RIEN.**
     *
     * L'URL de notification est publique — Monetbil doit pouvoir l'appeler sans
     * session. Sans contrôle de signature, n'importe qui pourrait annoncer un paiement
     * en POSTant sur cette URL, et obtenir des crédits gratuits.
     */
    public function test_une_notification_sans_signature_ne_verse_rien(): void
    {
        $lien = $this->lienEnLigne();

        Http::fake();

        $this->post(route('payment-link.notify', $lien->token), [
            'transaction_id' => 'tx_1',
            'status' => 1,
            'amount' => 2500,
            // Pas de `sign`.
        ])->assertOk()->assertSee('received');

        $this->assertSame(PaymentLink::STATUT_EN_ATTENTE, $lien->fresh()->status);
        Http::assertNothingSent();
    }

    /**
     * **Une signature valide mais un statut NON CONFIRMÉ par l'API ne verse rien.**
     *
     * C'est la protection contre le rejeu. La signature Monetbil ne porte pas
     * d'horodatage : une notification capturée reste rejouable indéfiniment. Le
     * statut annoncé dans le corps ne doit donc avoir AUCUN effet — seul l'appel à
     * l'API de vérification décide.
     *
     * Ici la signature est correcte et le corps annonce `status = 1`, mais l'API
     * répond que la transaction est échouée (0). Le lien doit rester en attente.
     */
    public function test_une_signature_valide_ne_suffit_pas_si_l_api_dement(): void
    {
        $lien = $this->lienEnLigne();

        Http::fake([
            'api.monetbil.com/*' => Http::response([
                'transaction' => ['status' => 0, 'testmode' => false],
            ], 200),
        ]);

        $service = app(MonetbilService::class);

        $parametres = [
            'transaction_id' => 'tx_1',
            'status' => 1,
            'amount' => 2500,
        ];

        $parametres['sign'] = $service->signature($parametres);

        $this->post(route('payment-link.notify', $lien->token), $parametres)
            ->assertOk()
            ->assertSee('received');

        $this->assertSame(
            PaymentLink::STATUT_EN_ATTENTE,
            $lien->fresh()->status,
            'Le statut ANNONCÉ ne doit avoir aucun effet : seul l\'appel de '
            .'vérification décide. C\'est la protection contre le rejeu, la signature '
            .'Monetbil ne portant aucun horodatage.',
        );
    }

    /**
     * **Une notification vérifiée règle le lien et verse les crédits.**
     *
     * Contrôle positif : sans lui, on ne saurait pas si le refus des cas précédents
     * vient du contrôle de sécurité ou d'un chemin cassé.
     */
    public function test_une_notification_verifiee_verse_les_credits(): void
    {
        $lien = $this->lienEnLigne();

        Http::fake([
            'api.monetbil.com/*' => Http::response([
                'transaction' => ['status' => 1, 'testmode' => false, 'amount' => 2500],
            ], 200),
        ]);

        $service = app(MonetbilService::class);

        $parametres = [
            'transaction_id' => 'tx_ok',
            'status' => 1,
            'amount' => 2500,
        ];

        $parametres['sign'] = $service->signature($parametres);

        $this->post(route('payment-link.notify', $lien->token), $parametres)
            ->assertOk()
            ->assertSee('received');

        $lienFrai = $lien->fresh();

        $this->assertSame(PaymentLink::STATUT_PAYE, $lienFrai->status);
        $this->assertSame('tx_ok', $lienFrai->payment_reference);

        $this->assertSame(
            2500,
            User::find($lien->user_id)->credits_balance,
            'Le règlement doit verser les crédits du lien : 1 crédit = 1 FCFA.',
        );
    }

    /**
     * **Une notification rejouée ne verse qu'une fois.**
     *
     * Le scénario est nominal, pas théorique : Monetbil réémet une notification tant
     * qu'il n'a pas reçu `received`, et une notification capturée peut être rejouée.
     * L'idempotence de `regler()` est la SEULE protection — la signature ne l'assure
     * pas, faute d'horodatage.
     */
    public function test_une_notification_rejouee_ne_verse_qu_une_fois(): void
    {
        $lien = $this->lienEnLigne();

        Http::fake([
            'api.monetbil.com/*' => Http::response([
                'transaction' => ['status' => 1, 'testmode' => false, 'amount' => 2500],
            ], 200),
        ]);

        $service = app(MonetbilService::class);

        $parametres = ['transaction_id' => 'tx_double', 'status' => 1, 'amount' => 2500];
        $parametres['sign'] = $service->signature($parametres);

        // Deux envois IDENTIQUES : c'est le rejeu de la même notification.
        $this->post(route('payment-link.notify', $lien->token), $parametres)->assertOk();
        $this->post(route('payment-link.notify', $lien->token), $parametres)->assertOk();

        $this->assertSame(
            2500,
            User::find($lien->user_id)->credits_balance,
            'Un rejeu ne doit PAS verser deux fois : sinon le solde du client '
            .'deviendrait faux sans qu\'aucune erreur ne soit levée.',
        );
    }

    /**
     * Une notification pour un jeton inconnu répond `received` sans erreur.
     *
     * Répondre autre chose ferait réémettre indéfiniment une notification qu'on ne
     * pourra jamais rattacher à un lien.
     */
    public function test_une_notification_pour_un_jeton_inconnu_est_acquittee(): void
    {
        Http::fake();

        $this->post(route('payment-link.notify', 'jeton-inexistant'), [
            'transaction_id' => 'tx_1',
            'status' => 1,
        ])->assertOk()->assertSee('received');

        Http::assertNothingSent();
    }
}
