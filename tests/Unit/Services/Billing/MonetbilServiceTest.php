<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Billing;

use App\Services\Billing\MonetbilService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Client Monetbil.
 *
 * **Ce que ces tests protègent.** Trois défauts, tous silencieux, et deux d'entre eux
 * ont réellement existé dans le SDK officiel :
 *
 *  1. **La signature.** Monetbil signe le MD5 du secret suivi des valeurs triées par
 *     CLÉ. Se tromper de tri (par valeur, ou pas de tri) produit une signature
 *     différente — et la comparaison étant une simple inégalité de chaînes, TOUTES
 *     les notifications sont rejetées sans le moindre message expliquant pourquoi.
 *     On teste donc la signature contre une valeur calculée indépendamment.
 *  2. **Le statut de mode test.** Le SDK définit `1` pour un succès réel et `7` pour
 *     un succès de recette. Ne reconnaître que `1` rejette tous les paiements de test :
 *     l'intégration ne peut plus être validée avant la mise en production.
 *  3. **L'absence de `payment_url`.** Une réponse valide mais sans URL ferait
 *     rediriger l'utilisateur vers nulle part. Elle doit être traitée comme un échec.
 *
 * **Ce qu'on ne teste PAS ici.** Le fait que la vérification du statut interroge
 * l'API et non la notification : c'est une décision d'appelant (`accepté par
 * PaymentLinkController::notify()`), testée au niveau de la fonctionnalité.
 */
class MonetbilServiceTest extends TestCase
{
    private function service(): MonetbilService
    {
        return app(MonetbilService::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'monetbil.service_key' => 'cle_test',
            'monetbil.service_secret' => 'secret_test',
            'monetbil.widget_url' => 'https://www.monetbil.com/widget/',
            'monetbil.widget_version' => 'v2.1',
            'monetbil.currency' => 'XAF',
            'monetbil.locale' => 'fr',
        ]);
    }

    // -------------------------------------------------------------------------
    // Signature
    // -------------------------------------------------------------------------

    /**
     * **La signature trie par CLÉ, et le résultat est vérifiable à la main.**
     *
     * On calcule la valeur attendue indépendamment du service : si l'implémentation
     * changeait l'ordre de tri ou la concaténation, ce test échouerait alors que
     * deux appels au même service se « valideraient » l'un l'autre.
     */
    public function test_la_signature_trie_les_valeurs_par_cle(): void
    {
        $parametres = ['zebre' => 'dernier', 'abri' => 'premier', 'milieu' => 'central'];

        // Calcul indépendant : tri par clé, puis concaténation des VALEURS.
        $attendu = ['abri' => 'premier', 'milieu' => 'central', 'zebre' => 'dernier'];
        ksort($attendu);
        $signature = md5('secret_test'.implode('', $attendu));

        $this->assertSame($signature, $this->service()->signature($parametres));
    }

    /**
     * Une signature correcte est acceptée.
     */
    public function test_une_signature_valide_est_acceptee(): void
    {
        $parametres = ['amount' => '500', 'status' => '1'];

        $signature = $this->service()->signature($parametres);
        $parametres['sign'] = $signature;

        $this->assertTrue($this->service()->signatureValide($parametres));
    }

    /**
     * **Une notification dont UNE valeur a été modifiée est rejetée.**
     *
     * Le cas concret : un montant altéré en transit. La signature ne correspond plus,
     * donc la notification est refusée. Sans ce contrôle, n'importe qui pourrait
     * annoncer un paiement de son choix.
     */
    public function test_une_valeur_modifiee_invalide_la_signature(): void
    {
        $parametres = ['amount' => '500', 'status' => '1'];
        $parametres['sign'] = $this->service()->signature($parametres);

        // Le montant est altéré APRÈS signature, comme lors d'une interception.
        $parametres['amount'] = '50000';

        $this->assertFalse($this->service()->signatureValide($parametres),
            'Un montant modifié doit invalider la signature : sans cela, n\'importe '
            .'qui pourrait annoncer un paiement du montant de son choix.');
    }

    /**
     * Une notification sans signature est rejetée.
     *
     * Le SDK officiel retourne `false` dans ce cas ; on conserve ce comportement.
     */
    public function test_une_notification_sans_signature_est_rejetee(): void
    {
        $this->assertFalse($this->service()->signatureValide(['amount' => '500']));
    }

    /**
     * Un secret absent fait échouer la vérification plutôt que de l'accepter.
     *
     * **Échec fermé, et c'est le point.** Si le secret n'est pas configuré, la
     * comparaison porterait sur une chaîne vide ; accepter reviendrait à valider
     * n'importe quelle notification. On refuse.
     */
    public function test_un_secret_absent_fait_echouer_la_verification(): void
    {
        config(['monetbil.service_secret' => '']);

        $parametres = ['amount' => '500'];
        $parametres['sign'] = md5(''.'500');

        $this->assertFalse($this->service()->signatureValide($parametres),
            'Sans secret configuré, la vérification doit ÉCHOUER : l\'accepter '
            .'reviendrait à valider n\'importe quelle notification.');
    }

    /**
     * Les valeurs non scalaires sont écartées du calcul.
     *
     * Un tableau imbriqué serait sérialisé de façon qui ne correspond pas à ce que
     * Monetbil signe (le SDK utilise `http_build_query`). On l'ignore plutôt que de
     * produire une signature systématiquement fausse.
     */
    public function test_les_valeurs_non_scalaires_sont_ecartees(): void
    {
        $avec = ['montant' => '500', 'imbrique' => ['a' => 'b']];
        $sans = ['montant' => '500'];

        $this->assertSame(
            $this->service()->signature($sans),
            $this->service()->signature($avec),
            'Un tableau imbriqué doit être ignoré, et non sérialisé : sa '
            .'représentation ne correspondrait pas à ce que Monetbil signe.',
        );
    }

    // -------------------------------------------------------------------------
    // Statuts
    // -------------------------------------------------------------------------

    /**
     * **Le statut 7 est un succès : c'est celui du mode test.**
     *
     * Le SDK officiel définit deux jeux de statuts, et un code qui ne testerait que
     * `1` rejetterait TOUS les paiements de recette. On ne pourrait alors jamais
     * valider l'intégration avant la mise en production — le défaut ne se voit qu'au
     * moment précis où l'on essaie de recetter.
     */
    public function test_le_statut_de_mode_test_est_un_succes(): void
    {
        $this->assertTrue($this->service()->estUnSucces(1), 'Succès réel.');
        $this->assertTrue($this->service()->estUnSucces(7),
            'Succès de mode test. Ne pas le reconnaître rendrait toute la phase de '
            .'recette impossible.');
    }

    /**
     * Les statuts d'échec et d'annulation ne sont pas des succès.
     */
    public function test_les_statuts_d_echec_ne_sont_pas_des_succes(): void
    {
        foreach ([0, -1, 8, 9] as $statut) {
            $this->assertFalse($this->service()->estUnSucces($statut),
                "Le statut {$statut} ne doit pas être considéré comme un succès.");
        }
    }

    /**
     * **Un abandon est distingué d'un échec.**
     *
     * Ce n'est pas cosmétique : un abandon (le client ferme le widget) est un
     * comportement normal qui ne doit déclencher aucune alerte ; un échec est un
     * incident qui doit en déclencher une. Les confondre noierait les incidents dans
     * le bruit des abandons.
     */
    public function test_un_abandon_est_distingue_d_un_echec(): void
    {
        $this->assertTrue($this->service()->estUnAbandon(-1), 'Abandon réel.');
        $this->assertTrue($this->service()->estUnAbandon(9), 'Abandon de mode test.');

        $this->assertFalse($this->service()->estUnAbandon(0), 'Un échec n\'est pas un abandon.');
        $this->assertFalse($this->service()->estUnAbandon(8));
        $this->assertFalse($this->service()->estUnAbandon(1),
            'Un succès n\'est évidemment pas un abandon.');
    }

    // -------------------------------------------------------------------------
    // Initialisation du paiement
    // -------------------------------------------------------------------------

    /**
     * Une réponse valide renvoie l'URL de paiement.
     */
    public function test_une_reponse_valide_renvoie_l_url_de_paiement(): void
    {
        Http::fake([
            '*' => Http::response(['payment_url' => 'https://www.monetbil.com/pay/v2.1/abc'], 200),
        ]);

        $resultat = $this->service()->url(
            amountFcfa: 2500,
            paymentRef: 'LINK1-uuid',
            returnUrl: 'https://exemple.test/retour',
            notifyUrl: 'https://exemple.test/notify',
        );

        $this->assertTrue($resultat['ok']);
        $this->assertSame('https://www.monetbil.com/pay/v2.1/abc', $resultat['paymentUrl']);
    }

    /**
     * **Une réponse SANS `payment_url` est un échec.**
     *
     * Le cas est réel : Monetbil peut répondre 200 avec un corps qui signale un
     * problème sans fournir d'URL. Laisser passer une chaîne vide ferait rediriger le
     * client vers nulle part — une page blanche après avoir cliqué « Payer », sans
     * explication.
     */
    public function test_une_reponse_sans_url_est_un_echec(): void
    {
        Http::fake([
            '*' => Http::response(['message' => 'service indisponible'], 200),
        ]);

        $resultat = $this->service()->url(
            amountFcfa: 2500,
            paymentRef: 'LINK1-uuid',
            returnUrl: 'https://exemple.test/retour',
            notifyUrl: 'https://exemple.test/notify',
        );

        $this->assertFalse($resultat['ok']);
        $this->assertArrayNotHasKey('paymentUrl', $resultat);
    }

    /**
     * Sans clé de service, l'initialisation échoue sans appel réseau.
     *
     * **On ne tente PAS l'appel.** Une requête sans clé aboutirait de toute façon à
     * un refus, et consommerait le quota de la passerelle. Le refus immédiat donne
     * aussi un message plus clair : « Monetbil n'est pas configuré » plutôt qu'une
     * erreur HTTP du fournisseur.
     */
    public function test_sans_cle_de_service_l_initialisation_echoue_sans_appel(): void
    {
        config(['monetbil.service_key' => '']);

        Http::fake();

        $resultat = $this->service()->url(
            amountFcfa: 2500,
            paymentRef: 'LINK1-uuid',
            returnUrl: 'https://exemple.test/retour',
            notifyUrl: 'https://exemple.test/notify',
        );

        $this->assertFalse($resultat['ok']);
        $this->assertStringContainsString('configuré', (string) $resultat['message']);

        Http::assertNothingSent();
    }

    /**
     * Une erreur HTTP de la passerelle est traitée comme un échec.
     */
    public function test_une_erreur_http_est_un_echec(): void
    {
        Http::fake(['*' => Http::response('refusé', 400)]);

        $resultat = $this->service()->url(
            amountFcfa: 2500,
            paymentRef: 'LINK1-uuid',
            returnUrl: 'https://exemple.test/retour',
            notifyUrl: 'https://exemple.test/notify',
        );

        $this->assertFalse($resultat['ok']);
    }

    // -------------------------------------------------------------------------
    // Vérification du statut
    // -------------------------------------------------------------------------

    /**
     * La vérification remonte le statut réel et le mode test.
     *
     * **Le mode test est PROPAGÉ, et c'est indispensable.** Un paiement de recette ne
     * doit jamais débloquer de crédits réels. Si l'appelant ne savait pas qu'il est en
     * mode test, il ne pourrait pas s'en prémunir.
     */
    public function test_la_verification_remonte_le_statut_et_le_mode_test(): void
    {
        Http::fake([
            '*' => Http::response([
                'transaction' => [
                    'status' => 7,
                    'testmode' => true,
                    'msisdn' => '+237600000000',
                    'amount' => 2500,
                ],
            ], 200),
        ]);

        $resultat = $this->service()->checkPayment('tx_123');

        $this->assertTrue($resultat['ok']);
        $this->assertSame(7, $resultat['statut']);
        $this->assertTrue($resultat['succes'], 'Le statut 7 est un succès de mode test.');
        $this->assertTrue($resultat['testmode'],
            'Le mode test doit être propagé : sans cette information, un paiement de '
            .'recette pourrait débloquer des crédits réels.');
        $this->assertSame(2500, $resultat['montant']);
    }

    /**
     * Une réponse sans bloc `transaction` est un échec, pas un succès par défaut.
     *
     * Le SDK officiel initialise `$payment_status = 0` dans ce cas. On conserve ce
     * choix : ne rien savoir ne doit jamais valoir un succès.
     */
    public function test_une_reponse_sans_transaction_est_un_echec(): void
    {
        Http::fake(['*' => Http::response(['resultat' => 'inconnu'], 200)]);

        $resultat = $this->service()->checkPayment('tx_123');

        $this->assertFalse($resultat['ok']);
        $this->assertFalse($resultat['succes'],
            'Une réponse incompréhensible ne doit jamais valoir un succès.');
    }

    /**
     * Un identifiant vide n'entraîne aucun appel réseau.
     */
    public function test_un_identifiant_vide_n_entraine_aucun_appel(): void
    {
        Http::fake();

        $resultat = $this->service()->checkPayment('');

        $this->assertFalse($resultat['ok']);
        Http::assertNothingSent();
    }

    // -------------------------------------------------------------------------
    // Configuration
    // -------------------------------------------------------------------------

    /**
     * `estConfigure()` exige la clé ET le secret.
     *
     * Une clé sans secret ne permet ni d'initier un paiement ni de vérifier une
     * signature : le moyen ne peut pas fonctionner. Ne vérifier que la clé
     * proposerait un moyen qui échoue systématiquement.
     */
    public function test_est_configure_exige_la_cle_et_le_secret(): void
    {
        config(['monetbil.service_key' => 'cle', 'monetbil.service_secret' => '']);
        $this->assertFalse($this->service()->estConfigure(), 'Clé sans secret.');

        config(['monetbil.service_key' => '', 'monetbil.service_secret' => 'secret']);
        $this->assertFalse($this->service()->estConfigure(), 'Secret sans clé.');

        config(['monetbil.service_key' => 'cle', 'monetbil.service_secret' => 'secret']);
        $this->assertTrue($this->service()->estConfigure());
    }

    /**
     * L'URL du widget contient la version et la clé de service.
     *
     * Le format est imposé par Monetbil : `{base}/{version}/{service_key}`. Une URL
     * mal construite produit une erreur de leur côté, sans message utile.
     */
    public function test_l_url_du_widget_contient_la_version_et_la_cle(): void
    {
        config([
            'monetbil.widget_url' => 'https://www.monetbil.com/widget/',
            'monetbil.widget_version' => 'v2.1',
            'monetbil.service_key' => 'ma_cle',
        ]);

        $this->assertSame(
            'https://www.monetbil.com/widget/v2.1/ma_cle',
            $this->service()->widgetUrl(),
        );
    }
}
