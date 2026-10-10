<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\Billing\MonetbilService;
use App\Services\Billing\MonetbilServiceRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Recharge de crédits via Monetbil : un service par palier.
 *
 * **Ce que ces tests protègent.** Monetbil est un moyen de paiement par SERVICE :
 * chaque offre (palier de recharge) a ses PROPRES identifiants. Trois défauts sont
 * possibles et coûteux :
 *
 *  1. **Signer avec un couple global.** Le second palier deviendrait inencaissable,
 *     et sa notification serait vérifiée avec le mauvais secret — le client serait
 *     débité et jamais crédité, sans erreur visible.
 *  2. **Créditer sur une notification non vérifiée.** La signature Monetbil ne
 *     porte aucun horodatage : elle est rejouable. La protection repose sur
 *     l'idempotence du crédit (`creditIfNotProcessed`), pas sur la signature.
 *  3. **Créditer le mauvais compte.** Le secret peut être partagé entre services :
 *     la corrélation (`user`/`item_ref`) est indispensable.
 *
 * **Pourquoi un test sur les paramètres réellement envoyés.** Le défaut « couple
 * global » ne se voit PAS dans le code de la notification : il se voit à
 * l'initiation, quand l'URL du widget est construite avec la clé du mauvais
 * service. On vérifie donc la clé de service présente dans l'URL appelée.
 */
class MonetbilCreditPurchaseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'billing.credit_packs' => [
                ['montant' => 1000, 'credits' => 1000, 'libelle' => 'Découverte', 'service' => 'pack_1000'],
                ['montant' => 3000, 'credits' => 3000, 'libelle' => 'Mémoire', 'service' => 'pack_3000'],
            ],
            'monetbil.services' => [
                'pack_1000' => ['id' => 'svc_1000', 'key' => 'cle_1000', 'secret' => 'secret_1000'],
                'pack_3000' => ['id' => 'svc_3000', 'key' => 'cle_3000', 'secret' => 'secret_3000'],
            ],
            // Aucun couple global : c'est le cas réaliste, les identifiants vivent
            // par service.
            'monetbil.service_key' => '',
            'monetbil.service_secret' => '',
            'payments.gateways.kpay.actif' => false,
            'payments.gateways.monetbil.actif' => true,
            'payments.gateways.monetbil.visible' => true,
            'monetbil.currency' => 'XAF',
            'monetbil.locale' => 'fr',
        ]);
    }

    private function utilisateur(): User
    {
        return User::factory()->create(['credits_balance' => 0]);
    }

    // -------------------------------------------------------------------------
    // Initiation : un service par palier
    // -------------------------------------------------------------------------

    /**
     * **Chaque palier signe avec la clé de SON service.**
     *
     * C'est la propriété centrale de l'option « un service par pack ». On vérifie la
     * clé de service présente dans l'URL du widget : si les deux paliers appelaient
     * la même URL, le second service serait ignoré — donc inencaissable.
     */
    public function test_chaque_palier_utilise_le_service_de_son_palier(): void
    {
        Http::fake([
            '*/widget/*/cle_1000' => Http::response(['payment_url' => 'https://monetbil.test/pay/1000'], 200),
            '*/widget/*/cle_3000' => Http::response(['payment_url' => 'https://monetbil.test/pay/3000'], 200),
        ]);

        $this->actingAs($this->utilisateur())
            ->post(route('credits.purchase'), ['amount' => 1000, 'gateway' => 'monetbil'])
            ->assertRedirect('https://monetbil.test/pay/1000');

        $this->actingAs($this->utilisateur())
            ->post(route('credits.purchase'), ['amount' => 3000, 'gateway' => 'monetbil'])
            ->assertRedirect('https://monetbil.test/pay/3000');

        // Les deux URLs ont bien été appelées, chacune avec SA clé de service.
        Http::assertSent(fn ($request) => str_contains($request->url(), '/widget/') && str_contains($request->url(), 'cle_1000'));
        Http::assertSent(fn ($request) => str_contains($request->url(), '/widget/') && str_contains($request->url(), 'cle_3000'));
    }

    /**
     * **Un palier dont le service n'est pas exploitable est refusé.**
     *
     * Un service à moitié renseigné (clé sans secret) ne peut pas signer : le
     * proposer mènerait à une erreur de passerelle après l'engagement du client.
     */
    public function test_un_service_incomplet_refuse_le_paiement(): void
    {
        config([
            'monetbil.services' => [
                'pack_1000' => ['id' => 'svc_1000', 'key' => 'cle_1000', 'secret' => ''],
            ],
        ]);

        Http::fake();

        $this->actingAs($this->utilisateur())
            ->post(route('credits.purchase'), ['amount' => 1000, 'gateway' => 'monetbil'])
            ->assertSessionHas('error');

        Http::assertNothingSent();
    }

    // -------------------------------------------------------------------------
    // Notification : corrélation, signature, idempotence
    // -------------------------------------------------------------------------

    /**
     * Fabrique une notification signée avec le SECRET DU SERVICE du palier.
     *
     * @param  array<string, mixed>  $surcharge
     * @return array<string, mixed>
     */
    private function notification(int $montant, int $userId, string $transactionId, string $secret, array $surcharge = []): array
    {
        $parametres = array_merge([
            'amount' => $montant,
            'currency' => 'XAF',
            'status' => 1,
            'transaction_id' => $transactionId,
            'user' => $userId,
            'item_ref' => 'CREDIT'.$userId,
        ], $surcharge);

        $parametres['sign'] = app(MonetbilService::class)->signature($parametres, $secret);

        return $parametres;
    }

    /**
     * **Une notification valide crédite le compte, une seule fois.**
     *
     * Contrôle positif, avec le statut RÉEL confirmé à l'API (source d'autorité).
     */
    public function test_une_notification_valide_credite_le_compte(): void
    {
        Http::fake([
            '*' => Http::response([
                'transaction' => ['status' => 1, 'testmode' => false, 'amount' => 1000],
            ], 200),
        ]);

        $utilisateur = $this->utilisateur();

        $this->post(route('credits.notify'), $this->notification(1000, $utilisateur->id, 'tx_ok_1', 'secret_1000'))
            ->assertSee('received');

        $this->assertSame(1000, $utilisateur->fresh()->credits_balance);
    }

    /**
     * **Un rejeu de la même notification ne crédite PAS deux fois.**
     *
     * La signature Monetbil ne porte aucun horodatage : la notification est
     * rejouable telle quelle. L'idempotence (`creditIfNotProcessed`, référence =
     * `transaction_id`) est donc la SEULE protection contre le double versement.
     */
    public function test_un_rejeu_ne_credite_pas_deux_fois(): void
    {
        Http::fake([
            '*' => Http::response([
                'transaction' => ['status' => 1, 'testmode' => false, 'amount' => 1000],
            ], 200),
        ]);

        $utilisateur = $this->utilisateur();
        $notification = $this->notification(1000, $utilisateur->id, 'tx_replay', 'secret_1000');

        $this->post(route('credits.notify'), $notification)->assertSee('received');
        $this->post(route('credits.notify'), $notification)->assertSee('received');

        $this->assertSame(1000, $utilisateur->fresh()->credits_balance,
            'Un rejeu de notification ne doit jamais doubler le versement.');
    }

    /**
     * **Une signature signée avec le secret d'un AUTRE service est refusée.**
     *
     * C'est le cas d'un rejeu vers un palier de montant différent : le secret du
     * service du palier 1000 ne doit pas valider une notification annonçant 3000.
     */
    public function test_une_signature_d_un_autre_service_est_refusee(): void
    {
        Http::fake([
            '*' => Http::response([
                'transaction' => ['status' => 1, 'testmode' => false, 'amount' => 3000],
            ], 200),
        ]);

        $utilisateur = $this->utilisateur();

        // Montant 3000 (service pack_3000) mais signé avec le secret du pack_1000.
        $this->post(route('credits.notify'), $this->notification(3000, $utilisateur->id, 'tx_mauvais_secret', 'secret_1000'))
            ->assertSee('received');

        $this->assertSame(0, $utilisateur->fresh()->credits_balance,
            'Une signature produite avec le secret d\'un autre service ne doit pas créditer.');
    }

    /**
     * **Sans corrélation (ni `user` ni `item_ref`), rien n'est crédité.**
     *
     * Le secret pouvant être partagé entre services, une signature valide ne prouve
     * PAS que la notification concerne CE compte. Sans identifiant de compte, on
     * refuse plutôt que de créditer au hasard.
     */
    public function test_sans_correlation_rien_n_est_credite(): void
    {
        Http::fake([
            '*' => Http::response([
                'transaction' => ['status' => 1, 'testmode' => false, 'amount' => 1000],
            ], 200),
        ]);

        $utilisateur = $this->utilisateur();

        $parametres = [
            'amount' => 1000,
            'currency' => 'XAF',
            'status' => 1,
            'transaction_id' => 'tx_sans_correlation',
        ];
        $parametres['sign'] = app(MonetbilService::class)->signature($parametres, 'secret_1000');

        $this->post(route('credits.notify'), $parametres)->assertSee('received');

        $this->assertSame(0, $utilisateur->fresh()->credits_balance,
            'Sans corrélation, le compte destinataire est inconnu : aucun versement.');
    }

    /**
     * Le statut ANNONCÉ ne suffit pas : l'API doit confirmer.
     *
     * La notification n'est qu'un signal d'arrivée. Si l'API ne confirme pas le
     * succès, aucun crédit n'est versé.
     */
    public function test_le_statut_annonce_ne_suffit_pas(): void
    {
        Http::fake([
            '*' => Http::response([
                'transaction' => ['status' => 0, 'testmode' => false, 'amount' => 1000],
            ], 200),
        ]);

        $utilisateur = $this->utilisateur();

        $this->post(route('credits.notify'), $this->notification(1000, $utilisateur->id, 'tx_non_confirme', 'secret_1000'))
            ->assertSee('received');

        $this->assertSame(0, $utilisateur->fresh()->credits_balance,
            'Seul checkPayment() fait foi : un statut annoncé sans confirmation ne crédite pas.');
    }

    // -------------------------------------------------------------------------
    // Résolveur de services
    // -------------------------------------------------------------------------

    /**
     * Le résolveur accepte la CLÉ de configuration et l'IDENTIFIANT Monetbil.
     *
     * Les deux formes désignent le même service : accepter les deux évite qu'un
     * `.env` existant, qui portait l'identifiant brut, cesse de fonctionner.
     */
    public function test_le_resolveur_accepte_cle_et_identifiant(): void
    {
        $registre = app(MonetbilServiceRegistry::class);

        $parCle = $registre->pourReference('pack_1000');
        $parId = $registre->pourReference('svc_1000');

        $this->assertSame($parCle, $parId);
        $this->assertSame('cle_1000', $parCle['key']);
    }

    /**
     * Une référence inconnue ne rend AUCUN service (pas de défaut permissif).
     */
    public function test_une_reference_inconnue_ne_rend_aucun_service(): void
    {
        $this->assertNull(app(MonetbilServiceRegistry::class)->pourReference('inexistant'));
        $this->assertNull(app(MonetbilServiceRegistry::class)->pourReference(''));
        $this->assertNull(app(MonetbilServiceRegistry::class)->pourReference(null));
    }

    // -------------------------------------------------------------------------
    // Retour Monetbil : honnête, jamais un faux échec
    // -------------------------------------------------------------------------

    /**
     * **Le retour Monetbil n'affiche PAS un échec.**
     *
     * Monetbil ne signe pas son retour et n'y transmet pas de statut exploitable.
     * Le handler KPay, lui, attend `status=COMPLETED` signé et retombe sinon sur
     * « paiement annulé ou échoué » — un message FAUX sur un paiement réussi, qui
     * enverrait le client chercher un problème inexistant. Le retour Monetbil a donc
     * son propre handler, qui annonce l'attente de confirmation.
     *
     * La route est dans le groupe `auth` (comme le retour KPay) : le client a
     * initié l'achat dans son navigateur, donc sa session est active au retour.
     */
    public function test_le_retour_monetbil_n_affiche_pas_un_echec(): void
    {
        $this->actingAs($this->utilisateur())
            ->get(route('credits.return.monetbil', ['transaction_id' => 'tx_123']))
            ->assertRedirect(route('account.index'))
            ->assertSessionHas('info')
            ->assertSessionMissing('error');
    }
}
