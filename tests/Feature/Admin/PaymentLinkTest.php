<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\CreditTransaction;
use App\Models\PaymentLink;
use App\Models\User;
use App\Services\Billing\PaymentLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Liens de paiement.
 *
 * **Ce que ces tests protègent.** Un lien de paiement engage de l'argent réel. Trois
 * défauts sont possibles, et deux d'entre eux sont SILENCIEUX :
 *
 *  1. **Un double versement.** Le retour de la passerelle ET le webhook peuvent
 *     arriver tous les deux — c'est le cas nominal, pas théorique. Sans verrou, les
 *     crédits seraient versés deux fois et le solde du client deviendrait faux sans
 *     qu'aucune erreur ne soit levée.
 *  2. **Un versement sans trace.** Un lien marqué payé sans ligne dans
 *     `credit_transactions` rend le solde inexplicable : en cas de litige, on ne
 *     peut plus dire d'où vient le montant.
 *  3. **Un lien expiré encore réglable.** L'argent peut avoir été débité côté
 *     opérateur ; créditer automatiquement un lien expiré donnerait des crédits
 *     pour une vente qui n'existe plus — d'où le refus explicite et la journalisation
 *     pour traitement manuel.
 *
 * La seconde voie (`offline`) a un test dédié parce qu'elle n'est pas un pis-aller :
 * KPay est resté plusieurs JOURS en maintenance pendant le développement de cette
 * fonctionnalité. Un dispositif qui ne sait encaisser qu'en ligne perd la vente.
 */
class PaymentLinkTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    private function service(): PaymentLinkService
    {
        return app(PaymentLinkService::class);
    }

    private function lien(User $destinataire, array $attributs = []): PaymentLink
    {
        return $this->service()->creer(
            donnees: array_merge([
                'amount_fcfa' => 2500,
                'mode' => PaymentLink::MODE_HORS_LIGNE,
                'label' => 'Test',
                'user_id' => $destinataire->id,
            ], $attributs),
            auteur: $this->admin(),
        );
    }

    // -------------------------------------------------------------------------
    // Création
    // -------------------------------------------------------------------------

    public function test_un_lien_recoit_un_jeton_unique_et_les_credits_annonces(): void
    {
        $lien = $this->lien(User::factory()->create());

        $this->assertStringStartsWith('pl_', $lien->token);
        $this->assertGreaterThan(20, strlen($lien->token), 'Le jeton doit être long : un jeton deviné '
            .'ouvrirait la page de règlement d\'un tiers.');
        $this->assertSame(2500, $lien->amount_fcfa);
        $this->assertSame(2500, $lien->credits);
        $this->assertSame(PaymentLink::STATUT_EN_ATTENTE, $lien->status);
        $this->assertNotNull($lien->expires_at);
    }

    public function test_deux_liens_ont_des_jetons_differents(): void
    {
        $destinataire = User::factory()->create();

        $this->assertNotSame(
            $this->lien($destinataire)->token,
            $this->lien($destinataire)->token,
        );
    }

    public function test_la_duree_de_validite_suit_le_reglage(): void
    {
        config(['payments.link_ttl_days' => 3]);

        $lien = $this->lien(User::factory()->create());

        $this->assertSame(3, (int) $lien->created_at->diffInDays($lien->expires_at));
    }

    // -------------------------------------------------------------------------
    // Règlement hors ligne
    // -------------------------------------------------------------------------

    public function test_un_reglement_hors_ligne_credite_et_journalise(): void
    {
        $destinataire = User::factory()->create(['credits_balance' => 0]);
        $lien = $this->lien($destinataire);

        $resultat = $this->service()->regler($lien, 'VIREMENT-001', $this->admin()->id);

        $this->assertTrue($resultat['ok']);
        $this->assertTrue($resultat['credits_verses']);
        $this->assertSame(2500, $destinataire->fresh()->credits_balance);

        $lien->refresh();
        $this->assertSame(PaymentLink::STATUT_PAYE, $lien->status);
        $this->assertSame('VIREMENT-001', $lien->payment_reference);
        $this->assertNotNull($lien->paid_at);

        // La trace doit exister ET correspondre au solde.
        $this->assertDatabaseHas('credit_transactions', [
            'user_id' => $destinataire->id,
            'amount' => 2500,
            'balance_after' => 2500,
            'reference' => 'payment_link:'.$lien->id,
        ]);
    }

    /**
     * **Le test le plus important.**
     *
     * Deux règlements successifs du même lien — retour de passerelle puis webhook, ou
     * clic répété — ne doivent verser les crédits qu'UNE fois. Sans le verrou de ligne
     * et la vérification d'idempotence, le solde du client doublerait sans erreur.
     */
    public function test_un_lien_ne_se_regle_qu_une_fois(): void
    {
        $destinataire = User::factory()->create(['credits_balance' => 0]);
        $lien = $this->lien($destinataire);

        $premier = $this->service()->regler($lien, 'REF-1');
        $this->assertTrue($premier['credits_verses']);

        $second = $this->service()->regler($lien->fresh(), 'REF-2');

        $this->assertTrue($second['ok'], 'Un second règlement est un doublon, pas une erreur.');
        $this->assertFalse($second['credits_verses'], 'Aucun crédit ne doit être versé une seconde fois.');

        $this->assertSame(2500, $destinataire->fresh()->credits_balance, 'Le solde ne doit pas doubler.');
        $this->assertSame(1, CreditTransaction::where('reference', 'payment_link:'.$lien->id)->count());
    }

    public function test_un_reglement_sur_un_lien_annule_est_refuse(): void
    {
        $destinataire = User::factory()->create(['credits_balance' => 0]);
        $lien = $this->lien($destinataire);

        $this->assertTrue($this->service()->annuler($lien));

        $resultat = $this->service()->regler($lien->fresh(), 'REF-1');

        $this->assertFalse($resultat['ok']);
        $this->assertSame('annule', $resultat['motif']);
        $this->assertSame(0, $destinataire->fresh()->credits_balance);
    }

    /**
     * Un lien expiré refuse le versement automatique.
     *
     * L'argent a pu être débité côté opérateur : on ne verse donc pas
     * automatiquement, mais on ne perd pas l'information — un administrateur pourra
     * créditer après vérification. C'est pourquoi le motif est retourné.
     */
    public function test_un_reglement_sur_un_lien_expire_est_refuse_et_signale(): void
    {
        $destinataire = User::factory()->create(['credits_balance' => 0]);
        $lien = $this->lien($destinataire);

        $lien->forceFill(['expires_at' => now()->subDay()])->save();

        $resultat = $this->service()->regler($lien->fresh(), 'REF-TARDIVE');

        $this->assertFalse($resultat['ok']);
        $this->assertSame('expire', $resultat['motif']);
        $this->assertSame(0, $destinataire->fresh()->credits_balance);
        $this->assertSame(PaymentLink::STATUT_EN_ATTENTE, $lien->fresh()->status);
    }

    public function test_un_lien_sans_compte_est_marque_paye_sans_verser_de_credits(): void
    {
        // Prospect sans compte : le règlement est constaté, mais il n'y a personne à
        // créditer. Marquer le lien sans verser est le bon comportement — l'inverse
        // ferait échouer l'enregistrement et perdrait la trace du paiement.
        $lien = $this->service()->creer(
            donnees: [
                'amount_fcfa' => 1000,
                'mode' => PaymentLink::MODE_HORS_LIGNE,
                'user_id' => null,
                'customer_email' => 'prospect@exemple.test',
            ],
            auteur: $this->admin(),
        );

        $resultat = $this->service()->regler($lien, 'ESPECES-001');

        $this->assertTrue($resultat['ok']);
        $this->assertFalse($resultat['credits_verses']);
        $this->assertSame(PaymentLink::STATUT_PAYE, $lien->fresh()->status);
        $this->assertSame(0, CreditTransaction::count());
    }

    // -------------------------------------------------------------------------
    // État du lien
    // -------------------------------------------------------------------------

    public function test_un_lien_expire_n_est_plus_utilisable(): void
    {
        $lien = $this->lien(User::factory()->create());

        $this->assertTrue($lien->estUtilisable());
        $this->assertFalse($lien->estExpire());

        $lien->forceFill(['expires_at' => now()->subHour()])->save();

        $this->assertFalse($lien->fresh()->estUtilisable());
        $this->assertTrue($lien->fresh()->estExpire());
    }

    public function test_un_lien_paye_n_est_plus_utilisable(): void
    {
        $destinataire = User::factory()->create();
        $lien = $this->lien($destinataire);

        $this->service()->regler($lien, 'REF');

        $this->assertFalse($lien->fresh()->estUtilisable());
    }

    // -------------------------------------------------------------------------
    // Page publique
    // -------------------------------------------------------------------------

    public function test_la_page_publique_affiche_le_montant_avant_tout_bouton(): void
    {
        // Un lien opaque qui demande un paiement sans dire pour quoi n'inspire pas
        // confiance, et c'est le premier motif d'abandon.
        $lien = $this->lien(User::factory()->create(), ['description' => 'Mise en forme de mémoire']);

        $this->get(route('payment-link.show', $lien->token))
            ->assertOk()
            ->assertSee('2 500')
            ->assertSee('Mise en forme de mémoire');
    }

    public function test_un_jeton_inconnu_repond_404(): void
    {
        // Répondre 200 avec un message laisserait croire que le lien a existé, et
        // permettrait d'énumérer les jetons valides.
        $this->get(route('payment-link.show', 'pl_inexistant'))->assertNotFound();
    }

    /**
     * La page publique ne doit exposer AUCUNE navigation d'espace utilisateur.
     *
     * Le destinataire n'a pas de compte : lui proposer « Tableau de bord » ou
     * « Mes documents » le mènerait à une page de connexion. La page doit se suffire
     * à elle-même.
     */
    public function test_la_page_publique_n_expose_aucune_navigation_d_espace(): void
    {
        $lien = $this->lien(User::factory()->create());

        $response = $this->get(route('payment-link.show', $lien->token))->assertOk();

        $response->assertDontSee('Tableau de bord');
        $response->assertDontSee('Mes documents');
        $response->assertDontSee('Assistant IA');

        // Et elle ne doit pas être indexable : un lien de paiement indexé devient public.
        $response->assertSee('noindex');
    }

    public function test_un_lien_hors_ligne_n_affiche_pas_de_bouton_de_paiement(): void
    {
        // En afficher un serait une promesse non tenue : le règlement a lieu par un
        // autre canal. Le client cliquerait pour rien.
        $lien = $this->lien(User::factory()->create());

        $this->get(route('payment-link.show', $lien->token))
            ->assertOk()
            ->assertSee('Règlement hors ligne')
            ->assertDontSee(route('payment-link.payer', $lien->token));
    }

    public function test_un_lien_regle_affiche_un_etat_explicite(): void
    {
        $lien = $this->lien(User::factory()->create());
        $this->service()->regler($lien, 'REF');

        $this->get(route('payment-link.show', $lien->token))
            ->assertOk()
            ->assertSee('Paiement déjà enregistré');
    }

    public function test_un_lien_expire_affiche_une_explication(): void
    {
        // Un lien mort sans explication est un appel au support.
        $lien = $this->lien(User::factory()->create());
        $lien->forceFill(['expires_at' => now()->subDay()])->save();

        $this->get(route('payment-link.show', $lien->token))
            ->assertOk()
            ->assertSee('Lien expiré');
    }

    // -------------------------------------------------------------------------
    // Administration
    // -------------------------------------------------------------------------

    public function test_la_gestion_des_liens_est_reservee_a_l_administrateur(): void
    {
        $this->get(route('admin.payment-links.index'))->assertRedirect(route('login'));

        $ordinaire = User::factory()->create(['is_admin' => false]);
        $this->actingAs($ordinaire)->get(route('admin.payment-links.index'))->assertNotFound();

        $this->actingAs($this->admin())->get(route('admin.payment-links.index'))->assertOk();
    }

    public function test_un_lien_en_ligne_exige_un_compte_destinataire(): void
    {
        // Sans compte, les crédits n'auraient nulle part où être versés : on
        // encaisserait sans contrepartie possible.
        $this->actingAs($this->admin())
            ->post(route('admin.payment-links.store'), [
                'amount_fcfa' => 1000,
                'mode' => PaymentLink::MODE_EN_LIGNE,
            ])
            ->assertSessionHasErrors('user_id');

        $this->assertSame(0, PaymentLink::count());
    }

    public function test_un_montant_sous_le_minimum_est_refuse(): void
    {
        config(['kpay.min_amount' => 500]);

        $this->actingAs($this->admin())
            ->post(route('admin.payment-links.store'), [
                'amount_fcfa' => 100,
                'mode' => PaymentLink::MODE_HORS_LIGNE,
            ])
            ->assertSessionHasErrors('amount_fcfa');
    }

    /**
     * Un lien EN LIGNE ne se règle pas manuellement.
     *
     * Le marquer payé à la main créerait un double versement possible au retour de
     * la confirmation de l'opérateur. L'écran doit donc refuser, et le message doit
     * dire quoi faire.
     */
    public function test_un_lien_en_ligne_ne_se_regle_pas_manuellement(): void
    {
        $destinataire = User::factory()->create(['credits_balance' => 0]);

        $lien = $this->service()->creer(
            donnees: [
                'amount_fcfa' => 1000,
                'mode' => PaymentLink::MODE_EN_LIGNE,
                'user_id' => $destinataire->id,
            ],
            auteur: $this->admin(),
        );

        $this->actingAs($this->admin())
            ->post(route('admin.payment-links.regler', $lien), ['payment_reference' => 'MANUEL'])
            ->assertSessionHas('error');

        $this->assertSame(0, $destinataire->fresh()->credits_balance);
        $this->assertSame(PaymentLink::STATUT_EN_ATTENTE, $lien->fresh()->status);
    }

    public function test_la_reference_de_reglement_est_obligatoire(): void
    {
        // Sans référence, l'encaissement est indistinguable d'un crédit accordé par
        // erreur : impossible de le rapprocher d'un relevé bancaire.
        $destinataire = User::factory()->create();
        $lien = $this->lien($destinataire);

        $this->actingAs($this->admin())
            ->post(route('admin.payment-links.regler', $lien), [])
            ->assertSessionHasErrors('payment_reference');
    }

    public function test_la_prolongation_s_ajoute_a_l_echeance_existante(): void
    {
        $lien = $this->lien(User::factory()->create());
        $echeance = $lien->expires_at->copy();

        $this->actingAs($this->admin())
            ->post(route('admin.payment-links.prolonger', $lien), ['jours' => 10])
            ->assertSessionHasNoErrors();

        // Partir d'aujourd'hui RACCOURCIRAIT un lien qui expirait dans sept jours,
        // alors que l'intention est de l'allonger.
        $this->assertTrue(
            $lien->fresh()->expires_at->equalTo($echeance->copy()->addDays(10)),
            'La prolongation doit s\'ajouter à l\'échéance existante, pas repartir d\'aujourd\'hui.'
        );
    }

    public function test_un_lien_paye_ne_peut_pas_etre_annule(): void
    {
        $lien = $this->lien(User::factory()->create());
        $this->service()->regler($lien, 'REF');

        $this->actingAs($this->admin())
            ->post(route('admin.payment-links.annuler', $lien->fresh()))
            ->assertSessionHas('error');

        $this->assertSame(PaymentLink::STATUT_PAYE, $lien->fresh()->status);
    }
}
