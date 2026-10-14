<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Parcours d'accueil (onboarding) — progression persistante.
 *
 * **Ce que ces tests protègent.** L'ancienne page d'onboarding était une maquette
 * (aucune écriture, progression perdue à chaque visite). Un vrai parcours doit
 * tenir trois promesses, chacune vérifiée ici :
 *
 *  1. **Reprenable** — l'étape survit à une nouvelle requête, et une étape ne
 *     RECULE jamais (double clic, onglet rouvert) ;
 *  2. **Sautable** — « Passer » clôt sans bloquer, et un parcours terminé renvoie
 *     au tableau de bord au lieu de réafficher l'assistant ;
 *  3. **Sans engagement caché** — choisir une offre mémorise l'intention mais ne
 *     crée AUCUN abonnement : le paiement reste une décision séparée.
 */
class OnboardingTest extends TestCase
{
    use RefreshDatabase;

    private function utilisateur(): User
    {
        return User::factory()->create(['onboarding_step' => 1, 'onboarding_completed_at' => null]);
    }

    // -------------------------------------------------------------------------
    // Accès
    // -------------------------------------------------------------------------

    public function test_un_invite_est_redirige_vers_login(): void
    {
        $this->get(route('onboarding'))->assertRedirect(route('login'));
    }

    public function test_un_nouveau_compte_demarre_a_l_etape_un(): void
    {
        $user = $this->utilisateur();

        $this->assertSame(1, $user->onboardingStep());
        $this->assertFalse($user->hasCompletedOnboarding());

        $this->actingAs($user)
            ->get(route('onboarding'))
            ->assertOk()
            ->assertSee('Personnalisez votre espace');
    }

    // -------------------------------------------------------------------------
    // Étape 1 — préférences
    // -------------------------------------------------------------------------

    public function test_enregistrer_les_preferences_avance_a_l_etape_deux(): void
    {
        $user = $this->utilisateur();

        $this->actingAs($user)
            ->post(route('onboarding.preferences'), [
                'locale' => 'en',
                'theme_dark' => '1',
                'email_notifications' => '1',
            ])
            ->assertRedirect(route('onboarding'));

        $user->refresh();

        $this->assertSame(2, $user->onboarding_step);
        $this->assertSame('en', $user->preferences['locale']);
        $this->assertSame('dark', $user->preferences['theme']);
        $this->assertTrue($user->preferences['email_notifications']);
    }

    public function test_les_preferences_alimentent_l_ecran_des_parametres(): void
    {
        // Cohérence : l'utilisateur qui a réglé sa langue ici doit la retrouver
        // à l'identique sur l'écran des paramètres (même structure de stockage).
        $user = $this->utilisateur();

        $this->actingAs($user)
            ->post(route('onboarding.preferences'), ['locale' => 'en'])
            ->assertRedirect();

        $this->assertSame('en', $user->fresh()->preferences['locale']);
        $this->assertSame('light', $user->fresh()->preferences['theme'], 'Thème par défaut = clair quand la case est décochée.');
    }

    public function test_une_langue_invalide_est_refusee(): void
    {
        $user = $this->utilisateur();

        $this->actingAs($user)
            ->post(route('onboarding.preferences'), ['locale' => 'de'])
            ->assertSessionHasErrors('locale');

        $this->assertSame(1, $user->fresh()->onboarding_step, 'Un refus de validation ne doit pas avancer le parcours.');
    }

    // -------------------------------------------------------------------------
    // Progression : avancer, ne jamais reculer
    // -------------------------------------------------------------------------

    public function test_avancer_change_l_etape_courante(): void
    {
        $user = $this->utilisateur();

        $this->actingAs($user)
            ->post(route('onboarding.advance'), ['step' => 3])
            ->assertRedirect(route('onboarding'));

        $this->assertSame(3, $user->fresh()->onboarding_step);
    }

    public function test_une_etape_ne_recule_jamais(): void
    {
        // Un double clic, un onglet rouvert ou un retour arrière ne doit pas
        // ramener l'utilisateur à une étape déjà franchie.
        $user = $this->utilisateur();
        $user->forceFill(['onboarding_step' => 3])->save();

        $this->actingAs($user)
            ->post(route('onboarding.advance'), ['step' => 2])
            ->assertRedirect();

        $this->assertSame(3, $user->fresh()->onboarding_step);
    }

    public function test_avancer_ne_peut_pas_depasser_la_derniere_etape(): void
    {
        // Sans borne, un `step=99` forcerait une étape inexistante et la vue
        // tomberait dans une branche vide. La validation doit refuser.
        $user = $this->utilisateur();

        $this->actingAs($user)
            ->post(route('onboarding.advance'), ['step' => 99])
            ->assertSessionHasErrors('step');

        $this->assertSame(1, $user->fresh()->onboarding_step);
    }

    public function test_la_progression_est_reprise_sur_une_nouvelle_requete(): void
    {
        // C'est le défaut central de l'ancienne maquette : la page repartait à
        // zéro. Ici, l'étape vit sur le compte, donc elle survit.
        $user = $this->utilisateur();
        $user->forceFill(['onboarding_step' => 3])->save();

        $this->actingAs($user)
            ->get(route('onboarding'))
            ->assertOk()
            ->assertSee('Crédits et assistance IA');
    }

    // -------------------------------------------------------------------------
    // Achèvement et reprise
    // -------------------------------------------------------------------------

    public function test_passer_termine_le_parcours_et_redirige_vers_le_tableau_de_bord(): void
    {
        $user = $this->utilisateur();

        $this->actingAs($user)
            ->post(route('onboarding.complete'))
            ->assertRedirect(route('dashboard'));

        $this->assertNotNull($user->fresh()->onboarding_completed_at);
        $this->assertTrue($user->fresh()->hasCompletedOnboarding());
    }

    public function test_un_parcours_termine_redirige_vers_le_tableau_de_bord(): void
    {
        $user = User::factory()->create(['onboarding_completed_at' => now()]);

        $this->actingAs($user)
            ->get(route('onboarding'))
            ->assertRedirect(route('dashboard'));
    }

    // -------------------------------------------------------------------------
    // Étape 4 — offre envisagée
    // -------------------------------------------------------------------------

    public function test_choisir_une_offre_memorise_l_intention_sans_creer_d_abonnement(): void
    {
        // Le point de sécurité : AUCUN abonnement n'est créé par le parcours.
        // S'abonner est un engagement payant, qui se prend sur l'écran des tarifs.
        Plan::query()->create([
            'slug' => 'premium',
            'name' => 'Premium',
            'price_fcfa' => 5000,
            'is_active' => true,
            'sort_order' => 2,
        ]);

        $user = $this->utilisateur();

        $this->actingAs($user)
            ->post(route('onboarding.plan'), ['plan' => 'premium'])
            ->assertRedirect(route('dashboard'));

        $user->refresh();

        $this->assertSame('premium', $user->preferences['intended_plan']);
        $this->assertTrue($user->hasCompletedOnboarding());
        $this->assertSame(0, $user->subscriptions()->count(), 'Le parcours ne doit jamais créer d\'abonnement.');
    }

    public function test_une_offre_inexistante_est_refusee(): void
    {
        $user = $this->utilisateur();

        $this->actingAs($user)
            ->post(route('onboarding.plan'), ['plan' => 'inexistant'])
            ->assertSessionHasErrors('plan');

        $this->assertFalse($user->fresh()->hasCompletedOnboarding());
    }
}
