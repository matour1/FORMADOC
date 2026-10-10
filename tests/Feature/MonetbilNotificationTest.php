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
 * Notification Monetbil : exemption CSRF et contrôle du MONTANT.
 *
 * **Deux défauts réels, trouvés par un audit du 2026-10-05, pas par relecture.**
 *
 * 1. **Le CSRF rejetait TOUTE notification.** La route `paiement/{token}/notify`
 *    est déclarée dans un groupe `web`, donc soumise à `VerifyCsrfToken`. Or une
 *    notification vient d'un serveur : pas de session, pas de cookie, donc aucun
 *    jeton possible. Prouvé en envoyant un POST réel : **419 Page Expired**.
 *    Seule `kpay/webhook` était exemptée. Le client aurait été débité chez
 *    l'opérateur et jamais crédité.
 *
 * 2. **Le montant confirmé n'était comparé à rien.** Un client réglant
 *    500 FCFA sur un lien de 2 500 crédits recevait les 2 500 crédits. Le
 *    paiement était authentique et « réussi », donc rien ne signalait l'anomalie.
 *
 * **Pourquoi le premier défaut est verrouillé par un test d'ARCHITECTURE et non
 * par un test HTTP.** En environnement de test, Laravel court-circuite
 * `VerifyCsrfToken` (`runningUnitTests()`). Un POST sans jeton y passerait
 * toujours, quel que soit le code — le test serait vert même sans l'exemption.
 * La seule vérification qui vaut est donc de lire la DÉCLARATION d'exemption.
 */
class MonetbilNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'monetbil.service_key' => 'cle_test',
            'monetbil.service_secret' => 'secret_test',
            'monetbil.currency' => 'XAF',
            'payments.gateways.kpay.actif' => false,
            'payments.gateways.monetbil.actif' => true,
            'payments.gateways.monetbil.visible' => true,
        ]);
    }

    // -------------------------------------------------------------------------
    // Défaut 1 : exemption CSRF (test d'architecture)
    // -------------------------------------------------------------------------

    /**
     * **La route de notification est exemptée du CSRF.**
     *
     * Ce test lit `bootstrap/app.php` : c'est la seule preuve valable, puisque
     * l'environnement de test désactive le middleware lui-même. Sans cette
     * exemption, la notification répond 419 en production.
     *
     * Le motif `paiement/` suivi du jeton puis de `/notify` couvre le jeton, qui
     * varie par lien. Une
     * exemption nominative ne marcherait pas ici — contrairement à
     * `kpay/webhook`, dont l'URL est fixe.
     */
    public function test_la_route_de_notification_est_exemptee_du_csrf(): void
    {
        $bootstrap = (string) file_get_contents(base_path('bootstrap/app.php'));

        preg_match('/validateCsrfTokens\(except:\s*\[(.*?)\]/s', $bootstrap, $m);

        $this->assertNotEmpty($m[1] ?? '', 'Aucune exemption CSRF déclarée : la notification Monetbil serait rejetée en 419.');

        $this->assertStringContainsString('paiement/*/notify', $m[1],
            'La route de notification Monetbil doit être exemptée du CSRF. Une '
            .'notification vient d\'un serveur : elle ne peut fournir aucun jeton. '
            .'Sans exemption, Laravel répond 419 et le paiement n\'est JAMAIS constaté.');

        // KPay reste exempté : la régression ne doit pas avoir été déplacée.
        $this->assertStringContainsString('kpay/webhook', $m[1]);
    }

    /**
     * La route de notification existe et accepte POST.
     *
     * Contrôle de cohérence : une exemption CSRF sur une route inexistante ne
     * protégerait rien. On vérifie donc que le nom de route résout bien.
     */
    public function test_la_route_de_notification_existe(): void
    {
        $url = route('payment-link.notify', ['token' => 'jeton']);

        $this->assertStringContainsString('paiement/jeton/notify', $url);
    }

    // -------------------------------------------------------------------------
    // Verbe HTTP : GET et POST (choix de l'exploitant, pas le nôtre)
    // -------------------------------------------------------------------------

    /**
     * **La notification est acceptée en GET comme en POST.**
     *
     * Monetbil laisse l'exploitant choisir la méthode HTTP de notification (onglet
     * « Configuration du service »), et son formulaire affiche **GET par défaut**.
     * Ce choix appartient au fournisseur et à l'exploitant : ne router que POST
     * ferait qu'une notification GET ne trouverait aucune route — le client serait
     * débité chez l'opérateur et **jamais crédité**, sans erreur visible d'aucun
     * côté. C'est le genre de défaut qu'on ne découvre qu'en production, par une
     * réclamation.
     *
     * Le test est un contrôle de ROUTAGE : il vérifie que le verbe GET atteint bien
     * le contrôleur. Le contrôle de sécurité ne dépend pas du verbe — signature,
     * confirmation à l'API et corrélation s'appliquent identiquement.
     */
    public function test_la_notification_est_acceptee_en_get_et_en_post(): void
    {
        // Un GET doit atteindre la route : sans ça, il tomberait en 405.
        $reponseGet = $this->get(route('payment-link.notify', ['token' => 'jeton-inexistant']));

        $reponseGet->assertOk();
        $reponseGet->assertSee('received');

        // Le POST aussi, bien sûr.
        $reponsePost = $this->post(route('payment-link.notify', ['token' => 'jeton-inexistant']));

        $reponsePost->assertOk();
        $reponsePost->assertSee('received');
    }

    /**
     * **Une notification GET signée règle le lien.**
     *
     * Contrôle positif : le test précédent ne vérifie que le routage. Ici, on
     * confirme que les paramètres d'une notification GET sont bien LUS — Laravel
     * renvoie la query pour GET et le corps pour POST, mais s'en remettre à ce
     * détail sans le tester serait une confiance non vérifiée.
     */
    public function test_une_notification_get_signee_credite_le_lien(): void
    {
        $lien = $this->lienEnLigne(2500);

        $this->simulerApi(['status' => 1, 'testmode' => false, 'amount' => 2500]);

        $parametres = $this->notification(2500, [], $lien);

        $this->get(route('payment-link.notify', $lien->token).'?'.http_build_query($parametres))
            ->assertOk()
            ->assertSee('received');

        $this->assertSame(PaymentLink::STATUT_PAYE, $lien->fresh()->status,
            'Une notification GET signée doit régler le lien : le choix du verbe '
            .'appartient à Monetbil et à l\'exploitant, pas à nous.');

        $this->assertSame(2500, User::find($lien->user_id)->credits_balance);
    }

    // -------------------------------------------------------------------------
    // Défaut 2 : contrôle du montant
    // -------------------------------------------------------------------------

    private function lienEnLigne(int $montant = 2500): PaymentLink
    {
        $destinataire = User::factory()->create(['credits_balance' => 0]);

        $lien = app(PaymentLinkService::class)->creer(
            donnees: [
                'amount_fcfa' => $montant,
                'mode' => PaymentLink::MODE_EN_LIGNE,
                'label' => 'Test montant',
                'user_id' => $destinataire->id,
            ],
            auteur: User::factory()->create(['is_admin' => true]),
        );

        $lien->forceFill(['gateway' => PaymentGatewayRegistry::MONETBIL])->save();

        return $lien->fresh();
    }

    /**
     * @param  array<string, mixed>  $transaction  bloc `transaction` renvoyé par l'API
     */
    private function simulerApi(array $transaction): void
    {
        Http::fake([
            'api.monetbil.com/*' => Http::response(['transaction' => $transaction], 200),
        ]);
    }

    /**
     * Construit une notification Monetbil valide et signée.
     *
     * **`item_ref` est inclus par défaut, et c'est le contrat réel.** Une
     * notification légitime porte l'identifiant de corrélation : nous l'envoyons à
     * chaque initiation et Monetbil le retourne. Une notification qui n'en porte
     * pas est anormale (forgée, ou protocole changé) — et le contrôleur la refuse
     * désormais. Les tests du contrôle de montant doivent donc reproduire une
     * notification AUTHENTIQUE, sans quoi ils testeraient un cas pathologique.
     *
     * Passer `null` pour `$lien` reproduit au contraire une notification SANS
     * corrélation, pour vérifier que le contrôleur la refuse.
     *
     * @param  array<string, mixed>  $surcharges
     * @return array<string, mixed>
     */
    private function notification(int $montantNotifie, array $surcharges = [], ?PaymentLink $lien = null): array
    {
        $base = [
            'transaction_id' => 'tx_test',
            'status' => 1,
            'amount' => $montantNotifie,
        ];

        if ($lien !== null) {
            $base['item_ref'] = 'LINK'.$lien->id;
        }

        $parametres = array_merge($base, $surcharges);

        $parametres['sign'] = app(MonetbilService::class)->signature($parametres);

        return $parametres;
    }

    /**
     * **Un montant INSUFFISANT ne crédite rien.**
     *
     * C'est le défaut le plus coûteux : sans ce contrôle, un client réglant
     * 500 FCFA sur un lien de 2 500 crédits recevait les 2 500 crédits. La perte
     * était structurelle — reproductible à volonté, et invisible puisque le
     * paiement était authentique et le statut « réussi ».
     */
    public function test_un_montant_inferieur_ne_credite_rien(): void
    {
        $lien = $this->lienEnLigne(2500);

        $this->simulerApi(['status' => 1, 'testmode' => false, 'amount' => 500]);

        $this->post(route('payment-link.notify', $lien->token), $this->notification(500, [], $lien))
            ->assertOk()
            ->assertSee('received');

        $this->assertSame(PaymentLink::STATUT_EN_ATTENTE, $lien->fresh()->status,
            'Un paiement insuffisant ne doit PAS régler le lien.');

        $this->assertSame(0, User::find($lien->user_id)->credits_balance,
            'Un paiement insuffisant ne doit PAS verser de crédits : les verser '
            .'reviendrait à offrir la différence entre le payé et le dû.');
    }

    /**
     * **Un montant suffisant crédite le lien.**
     *
     * Contrôle positif : sans lui, on ne saurait pas si le refus du cas précédent
     * vient du contrôle du montant ou d'un chemin cassé.
     */
    public function test_un_montant_suffisant_credite_le_lien(): void
    {
        $lien = $this->lienEnLigne(2500);

        $this->simulerApi(['status' => 1, 'testmode' => false, 'amount' => 2500]);

        $this->post(route('payment-link.notify', $lien->token), $this->notification(2500, [], $lien))
            ->assertOk();

        $this->assertSame(PaymentLink::STATUT_PAYE, $lien->fresh()->status);
        $this->assertSame(2500, User::find($lien->user_id)->credits_balance);
    }

    /**
     * **Un montant SUPÉRIEUR crédite ce qui est dû, pas ce qui a été payé.**
     *
     * Un trop-perçu arrive quand l'utilisateur modifie le montant sur le widget.
     * Créditer le montant payé gonflerait le solde au-delà de la prestation, et le
     * surplus serait un remboursement à faire. On crédite le montant du lien, et
     * l'écart est journalisé pour que l'administration le voie.
     */
    public function test_un_montant_superieur_ne_credite_que_le_montant_du(): void
    {
        $lien = $this->lienEnLigne(2500);

        $this->simulerApi(['status' => 1, 'testmode' => false, 'amount' => 5000]);

        $this->post(route('payment-link.notify', $lien->token), $this->notification(5000, [], $lien))
            ->assertOk();

        $this->assertSame(PaymentLink::STATUT_PAYE, $lien->fresh()->status);
        $this->assertSame(2500, User::find($lien->user_id)->credits_balance,
            'Un trop-perçu crédite le montant DU, pas le montant payé : sinon le '
            .'solde dépasserait la prestation vendue.');
    }

    /**
     * **Un montant INTROUVABLE ne crédite rien, et c'est le choix sûr.**
     *
     * L'inverse — créditer quand on ne sait pas — rouvrirait exactement la fuite
     * qu'on ferme. Le règlement reste constatable à la main depuis
     * l'administration, à partir de la trace journalisée.
     */
    public function test_un_montant_introuvable_ne_credite_rien(): void
    {
        $lien = $this->lienEnLigne(2500);

        // L'API ne renvoie pas de montant, et la notification non plus.
        $this->simulerApi(['status' => 1, 'testmode' => false]);

        $parametres = ['transaction_id' => 'tx_test', 'status' => 1, 'item_ref' => 'LINK'.$lien->id];
        $parametres['sign'] = app(MonetbilService::class)->signature($parametres);

        $this->post(route('payment-link.notify', $lien->token), $parametres)->assertOk();

        $this->assertSame(PaymentLink::STATUT_EN_ATTENTE, $lien->fresh()->status,
            'Un montant inconnu ne doit PAS régler le lien : on ne crédite pas ce '
            .'qu\'on ne peut pas vérifier.');
    }

    /**
     * Le montant de l'API prime sur celui de la notification.
     *
     * Les deux sources sont authentiques, mais l'API est la référence : c'est
     * elle qui fait foi sur le statut, et son montant est celui que l'opérateur a
     * RÉELLEMENT encaissé. Une divergence entre les deux signale une anomalie, et
     * retenir le plus petit montant est le choix sûr.
     */
    public function test_le_montant_de_l_api_prime_sur_celui_de_la_notification(): void
    {
        $lien = $this->lienEnLigne(2500);

        // L'API dit 500 (insuffisant), la notification annonce 2500.
        $this->simulerApi(['status' => 1, 'testmode' => false, 'amount' => 500]);

        $this->post(route('payment-link.notify', $lien->token), $this->notification(2500, [], $lien))
            ->assertOk();

        $this->assertSame(PaymentLink::STATUT_EN_ATTENTE, $lien->fresh()->status,
            'Le montant de l\'API doit primer : c\'est celui que l\'opérateur a '
            .'réellement encaissé.');
    }

    /**
     * **Ce que l'initiation ENVOIE correspond à ce que la notification ATTEND.**
     *
     * Test de COHÉRENCE, et il protège d'un défaut silencieux : si `payerParMonetbil`
     * omettait `item_ref` (ou changeait son format), la notification légitime
     * arriverait sans corrélation — donc REFUSÉE — et le client serait débité sans
     * jamais être crédité. Aucun test unitaire de l'un ou l'autre côté ne le
     * verrait : chacun serait correct isolément.
     *
     * C'est le même motif que la duplication d'estimation de prix entre
     * l'affichage et le débit : deux calculs justes séparément peuvent diverger.
     */
    public function test_l_initiation_envoie_la_correlation_attendue_par_la_notification(): void
    {
        $lien = $this->lienEnLigne(2500);

        $envoyes = [];

        Http::fake([
            'www.monetbil.com/*' => function ($request) use (&$envoyes) {
                $envoyes = $request->data();

                return Http::response(['payment_url' => 'https://www.monetbil.com/pay/v2.1/xyz'], 200);
            },
        ]);

        $this->post(route('payment-link.payer', $lien->token), [
            'gateway' => PaymentGatewayRegistry::MONETBIL,
        ])->assertRedirect('https://www.monetbil.com/pay/v2.1/xyz');

        $this->assertSame('LINK'.$lien->id, $envoyes['item_ref'] ?? null,
            'L\'initiation doit envoyer `item_ref` = « LINK{id} », exactement ce '
            .'que la notification compare. Sinon la notification légitime est '
            .'refusée et le client débité sans être crédité.');

        $this->assertSame($lien->user_id, $envoyes['user'] ?? null,
            'L\'identifiant `user` doit être envoyé : c\'est le repli de '
            .'corrélation quand `item_ref` est absent.');

        $this->assertSame($lien->amount_fcfa, $envoyes['amount'] ?? null);
    }

    /**
     * Une signature invalide ne déclenche même pas la vérification.
     *
     * Contrôle de sécurité : sans lui, un tiers pourrait provoquer des appels API
     * à volonté en POSTant sur l'URL de notification.
     */
    public function test_une_signature_invalide_n_entraine_aucun_appel(): void
    {
        $lien = $this->lienEnLigne(2500);

        Http::fake();

        $this->post(route('payment-link.notify', $lien->token), [
            'transaction_id' => 'tx_test',
            'status' => 1,
            'amount' => 2500,
        ])->assertOk()->assertSee('received');

        Http::assertNothingSent();

        $this->assertSame(PaymentLink::STATUT_EN_ATTENTE, $lien->fresh()->status);
    }

    // -------------------------------------------------------------------------
    // Défaut 3 : corrélation avec LE lien (signature globale)
    // -------------------------------------------------------------------------

    /**
     * **Une notification destinée à un AUTRE lien ne crédite pas celui-ci.**
     *
     * C'est la faille que la signature ne peut pas fermer : le secret Monetbil est
     * GLOBAL, donc la signature est identique pour tous nos liens. Un client peut
     * payer sur un lien bon marché, capturer la notification — parfaitement
     * signée — et la rejouer sur un lien coûteux. Statut « réussi » et montant
     * conforme (celui du lien bon marché) : sans contrôle de corrélation, rien ne
     * signalerait la fraude.
     *
     * `item_ref` désigne LE lien (« LINK{id} ») : c'est la corrélation la plus
     * forte, et Monetbil le retourne dans la notification.
     */
    public function test_une_notification_d_un_autre_lien_ne_credite_pas(): void
    {
        $lien = $this->lienEnLigne(2500);

        $this->simulerApi(['status' => 1, 'testmode' => false, 'amount' => 2500]);

        // `item_ref` désigne un AUTRE lien que celui de l'URL. Le montant et le
        // statut sont corrects : seule la corrélation diffère.
        $this->post(route('payment-link.notify', $lien->token), $this->notification(2500, [
            'item_ref' => 'LINK999',
        ]))->assertOk()->assertSee('received');

        $this->assertSame(PaymentLink::STATUT_EN_ATTENTE, $lien->fresh()->status,
            'Une notification portant l\'item_ref d\'un autre lien ne doit PAS '
            .'créditer celui-ci : le secret étant global, la signature ne protège '
            .'pas d\'un rejeu d\'un lien vers un autre.');

        $this->assertSame(0, User::find($lien->user_id)->credits_balance);
    }

    /**
     * Une notification portant le BON `item_ref` crédite.
     *
     * Contrôle positif : sans lui, on ne saurait pas si le refus du cas précédent
     * vient du contrôle de corrélation ou d'un chemin cassé.
     */
    public function test_une_notification_avec_le_bon_item_ref_credite(): void
    {
        $lien = $this->lienEnLigne(2500);

        $this->simulerApi(['status' => 1, 'testmode' => false, 'amount' => 2500]);

        $this->post(route('payment-link.notify', $lien->token), $this->notification(2500, [
            'item_ref' => 'LINK'.$lien->id,
        ]))->assertOk();

        $this->assertSame(PaymentLink::STATUT_PAYE, $lien->fresh()->status);
        $this->assertSame(2500, User::find($lien->user_id)->credits_balance);
    }

    /**
     * **Sans `item_ref`, c'est `user` qui corrèle.**
     *
     * Repli nécessaire : une notification peut ne pas porter `item_ref` (version
     * de widget antérieure, champ omis). `user` désigne le compte, et permet de
     * refuser une notification destinée à un AUTRE compte.
     */
    public function test_sans_item_ref_le_compte_est_verifie(): void
    {
        $lien = $this->lienEnLigne(2500);

        $this->simulerApi(['status' => 1, 'testmode' => false, 'amount' => 2500]);

        $this->post(route('payment-link.notify', $lien->token), $this->notification(2500, [
            'user' => 999999,
        ]))->assertOk()->assertSee('received');

        $this->assertSame(PaymentLink::STATUT_EN_ATTENTE, $lien->fresh()->status,
            'Un `user` désignant un autre compte doit faire refuser le versement.');
    }

    /**
     * Sans `item_ref` NI `user` : on ne peut pas corréler, et on refuse.
     *
     * **Choix sûr assumé.** On pourrait créditer par défaut — le montant et le
     * statut concordent. Mais sans corrélation, une notification capturée sur un
     * autre lien passerait, et c'est exactement la faille qu'on ferme. Le
     * règlement reste constatable à la main depuis l'administration.
     */
    public function test_sans_aucune_correlation_le_versement_est_refuse(): void
    {
        $lien = $this->lienEnLigne(2500);

        $this->simulerApi(['status' => 1, 'testmode' => false, 'amount' => 2500]);

        // Ni item_ref, ni user dans la notification.
        $this->post(route('payment-link.notify', $lien->token), $this->notification(2500))
            ->assertOk();

        $this->assertSame(PaymentLink::STATUT_EN_ATTENTE, $lien->fresh()->status,
            'Sans aucun identifiant de corrélation, on ne peut pas distinguer une '
            .'vraie notification d\'un rejeu : on refuse.');
    }
}
