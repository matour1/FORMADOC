<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Diagnostic Monetbil : protocole d'initiation et vérification de paiement.
 *
 * **Ce que ces tests protègent.** La commande `monetbil:check` sert à trancher, en
 * recette, le seul point de l'intégration jamais éprouvé contre le vrai service.
 * Elle doit donc :
 *
 *  - refuser de partir sans service exploitable (sinon elle enverrait une requête
 *    signée avec un secret vide, et l'échec serait attribué à tort au fournisseur) ;
 *  - rapporter clairement un échec d'initiation plutôt qu'un succès trompeur ;
 *  - traduire les statuts de `checkPayment()` (1 = succès, 7 = succès de test) pour
 *    permettre la comparaison avec `config/monetbil.php`.
 */
class MonetbilCheckCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'monetbil.services' => [
                'pack_1000' => ['id' => 'svc_1000', 'key' => 'cle_1000', 'secret' => 'secret_1000'],
            ],
            'monetbil.service_key' => '',
            'monetbil.service_secret' => '',
            'monetbil.currency' => 'XAF',
            'billing.credit_packs' => [
                ['montant' => 1000, 'credits' => 1000, 'libelle' => 'Découverte', 'service' => 'pack_1000'],
            ],
        ]);
    }

    /**
     * **Un service exploitable déclenche l'appel et rapporte l'URL.**
     *
     * Contrôle positif : la commande appelle bien le widget et restitue
     * `payment_url`, ce qui prouve que le chemin et le format de réponse sont
     * compris. Le `--force` évite la confirmation interactive.
     */
    public function test_un_service_valide_initie_le_paiement_et_rapporte_l_url(): void
    {
        Http::fake([
            '*/widget/*/cle_1000' => Http::response(['payment_url' => 'https://monetbil.test/pay/xyz'], 200),
        ]);

        $this->artisan('monetbil:check', ['--service' => 'pack_1000', '--amount' => 1000, '--force' => true])
            ->expectsOutputToContain('SUCCÈS')
            ->expectsOutputToContain('https://monetbil.test/pay/xyz')
            ->assertSuccessful();

        Http::assertSent(fn ($request) => str_contains($request->url(), '/widget/')
            && str_contains($request->url(), 'cle_1000'));
    }

    /**
     * **Sans service exploitable, la commande refuse et n'appelle rien.**
     *
     * Un service à moitié renseigné (clé sans secret) ne peut pas signer. Envoyer
     * la requête quand même produirait un échec attribué au fournisseur, alors que
     * la cause est notre configuration — un diagnostic qui accuse le mauvais
     * composant est pire qu'aucun diagnostic.
     */
    public function test_sans_service_exploitable_la_commande_refuse_et_n_appelle_rien(): void
    {
        config([
            'monetbil.services' => [
                'pack_1000' => ['id' => 'svc_1000', 'key' => 'cle_1000', 'secret' => ''],
            ],
        ]);

        Http::fake();

        $this->artisan('monetbil:check', ['--service' => 'pack_1000', '--amount' => 1000])
            ->expectsOutputToContain('Aucun service Monetbil exploitable')
            ->assertExitCode(1);

        Http::assertNothingSent();
    }

    /**
     * **Une initiation refusée est rapportée comme un ÉCHEC, pas comme un succès.**
     *
     * C'est le point de la commande : si Monetbil refuse la requête signée, c'est
     * que le protocole est à revoir. Conclure « succès » parce que l'appel HTTP a
     * abouti (même en erreur) ferait passer un protocole faux pour bon.
     */
    public function test_une_initiation_refusee_est_rapportee_comme_un_echec(): void
    {
        Http::fake([
            '*/widget/*' => Http::response('Service introuvable', 400),
        ]);

        $this->artisan('monetbil:check', ['--service' => 'pack_1000', '--amount' => 1000, '--force' => true])
            ->expectsOutputToContain('ÉCHEC')
            ->assertExitCode(1);
    }

    /**
     * **`--transaction` interroge `checkPayment` et traduit les statuts.**
     *
     * Le statut `7` est un SUCCÈS (mode test) : la commande doit l'afficher comme
     * tel, sinon la phase de recette paraîtrait échouer alors que le paiement de
     * test a abouti.
     */
    public function test_la_verification_traduit_le_statut_de_test_comme_un_succes(): void
    {
        Http::fake([
            '*/checkPayment*' => Http::response([
                'transaction' => ['status' => 7, 'testmode' => true, 'amount' => 1000, 'msisdn' => '2376xxxxxxx'],
            ], 200),
        ]);

        $this->artisan('monetbil:check', ['--transaction' => 'tx_recette'])
            ->expectsOutputToContain('statut      : 7')
            ->expectsOutputToContain('succès ?    : OUI')
            ->expectsOutputToContain('mode test ? : OUI')
            ->assertSuccessful();
    }

    /**
     * **Un succès réel (`status = 1`) est reconnu.**
     *
     * Contrôle complémentaire : le cas nominal de production, distinct du mode test.
     */
    public function test_la_verification_reconnait_le_succes_reel(): void
    {
        Http::fake([
            '*/checkPayment*' => Http::response([
                'transaction' => ['status' => 1, 'testmode' => false, 'amount' => 3000],
            ], 200),
        ]);

        $this->artisan('monetbil:check', ['--transaction' => 'tx_reelle'])
            ->expectsOutputToContain('succès ?    : OUI')
            ->expectsOutputToContain('mode test ? : non')
            ->assertSuccessful();
    }
}
