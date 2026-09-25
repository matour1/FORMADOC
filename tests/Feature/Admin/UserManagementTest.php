<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\CreditTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Gestion des comptes utilisateurs depuis l'espace d'exploitation.
 *
 * **Ce que ces tests protègent.** L'espace d'administration était en lecture seule.
 * Il ne l'est plus, et cette bascule change la nature du risque : une action
 * d'exploitation peut désormais retirer l'accès d'un client, lui prendre des
 * crédits ou lui en donner. Trois propriétés doivent tenir, et aucune ne se voit à
 * la lecture du code :
 *
 *  1. **Un ajustement laisse une trace.** Le solde et l'historique doivent raconter
 *     la même histoire ; sinon un litige devient insoluble.
 *  2. **Le solde ne devient jamais négatif.** Un débit direct sur la colonne le
 *     permettrait, et le client se retrouverait débiteur.
 *  3. **On ne se sabote pas soi-même.** Se retirer ses propres droits
 *     d'administration ou se suspendre enferme dehors, et seul un accès direct à la
 *     base permet de revenir.
 */
class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    // -------------------------------------------------------------------------
    // Contrôle d'accès
    // -------------------------------------------------------------------------

    public function test_la_gestion_des_comptes_est_reservee_a_l_administrateur(): void
    {
        $cible = User::factory()->create();

        $this->get(route('admin.users.index'))->assertRedirect(route('login'));
        $this->get(route('admin.users.show', $cible))->assertRedirect(route('login'));

        $ordinaire = User::factory()->create(['is_admin' => false]);
        $this->actingAs($ordinaire)->get(route('admin.users.index'))->assertNotFound();

        $this->actingAs($this->admin())->get(route('admin.users.index'))->assertOk();
    }

    public function test_un_non_administrateur_ne_peut_pas_agir_sur_un_compte(): void
    {
        $ordinaire = User::factory()->create(['is_admin' => false]);
        $cible = User::factory()->create(['credits_balance' => 100]);

        // 404 (et non 403) : ne pas confirmer l'existence de l'espace.
        $this->actingAs($ordinaire)
            ->post(route('admin.users.credits', $cible), ['amount' => 1000, 'reason' => 'tentative'])
            ->assertNotFound();

        $this->assertSame(100, $cible->fresh()->credits_balance);
        $this->assertSame(0, CreditTransaction::count());
    }

    // -------------------------------------------------------------------------
    // Ajustement de crédits
    // -------------------------------------------------------------------------

    public function test_un_credit_est_applique_et_journalise(): void
    {
        $admin = $this->admin();
        $cible = User::factory()->create(['credits_balance' => 0]);

        $this->actingAs($admin)
            ->post(route('admin.users.credits', $cible), [
                'amount' => 5000,
                'reason' => 'Geste commercial après incident de facturation',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(5000, $cible->fresh()->credits_balance);

        // L'historique doit exister ET correspondre au solde. Un solde modifié sans
        // ligne de journal rend le litige insoluble : on ne peut plus dire d'où
        // vient le montant.
        $this->assertDatabaseHas('credit_transactions', [
            'user_id' => $cible->id,
            'amount' => 5000,
            'balance_after' => 5000,
        ]);
    }

    public function test_un_debit_est_applique_et_journalise(): void
    {
        $cible = User::factory()->create(['credits_balance' => 3000]);

        $this->actingAs($this->admin())
            ->post(route('admin.users.credits', $cible), [
                'amount' => -1000,
                'reason' => 'Correction d\'un crédit accordé par erreur',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(2000, $cible->fresh()->credits_balance);

        $this->assertDatabaseHas('credit_transactions', [
            'user_id' => $cible->id,
            'amount' => -1000,
            'balance_after' => 2000,
        ]);
    }

    /**
     * **Le solde ne peut pas devenir négatif.**
     *
     * Un débit direct sur la colonne (`decrement`) le permettrait sans erreur : le
     * client deviendrait débiteur, et il faudrait le découvrir plus tard. Le
     * contrôle passe par `CreditService`, qui verrouille la ligne et refuse.
     */
    public function test_un_debit_superieur_au_solde_est_refuse(): void
    {
        $cible = User::factory()->create(['credits_balance' => 100]);

        $this->actingAs($this->admin())
            ->post(route('admin.users.credits', $cible), [
                'amount' => -5000,
                'reason' => 'Tentative de débit excessif',
            ])
            ->assertSessionHas('error');

        $this->assertSame(100, $cible->fresh()->credits_balance, 'Le solde ne doit pas devenir négatif.');
        $this->assertSame(0, CreditTransaction::count(), 'Un débit refusé ne doit rien journaliser.');
    }

    /**
     * **Le motif est obligatoire.**
     *
     * Un ajustement sans explication est indistinguable d'une erreur six mois plus
     * tard : impossible de savoir si les 5 000 crédits viennent d'un geste
     * commercial validé ou d'une faute de frappe.
     */
    public function test_un_ajustement_sans_motif_explicite_est_refuse(): void
    {
        $cible = User::factory()->create(['credits_balance' => 0]);

        $this->actingAs($this->admin())
            ->post(route('admin.users.credits', $cible), ['amount' => 1000])
            ->assertSessionHasErrors('reason');

        $this->actingAs($this->admin())
            ->post(route('admin.users.credits', $cible), ['amount' => 1000, 'reason' => 'ok'])
            ->assertSessionHasErrors('reason');

        $this->assertSame(0, $cible->fresh()->credits_balance);
    }

    public function test_un_montant_nul_est_refuse(): void
    {
        $cible = User::factory()->create(['credits_balance' => 0]);

        $this->actingAs($this->admin())
            ->post(route('admin.users.credits', $cible), ['amount' => 0, 'reason' => 'Aucun effet attendu'])
            ->assertSessionHasErrors('amount');
    }

    // -------------------------------------------------------------------------
    // Droits d'administration
    // -------------------------------------------------------------------------

    public function test_promouvoir_et_retirer_les_droits_d_administration(): void
    {
        $cible = User::factory()->create(['is_admin' => false]);
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.users.admin', $cible))->assertSessionHasNoErrors();
        $this->assertTrue($cible->fresh()->is_admin);

        $this->actingAs($admin)->post(route('admin.users.admin', $cible))->assertSessionHasNoErrors();
        $this->assertFalse($cible->fresh()->is_admin);
    }

    /**
     * **On ne se retire pas ses propres droits.**
     *
     * Le geste est presque toujours un accident, et il est irréversible depuis
     * l'interface : plus personne ne peut entrer dans l'espace d'exploitation, et
     * seul un accès direct à la base ou une commande Artisan peut rétablir la
     * situation.
     */
    public function test_on_ne_peut_pas_modifier_ses_propres_droits(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post(route('admin.users.admin', $admin))
            ->assertSessionHas('error');

        $this->assertTrue($admin->fresh()->is_admin, 'Un administrateur ne doit pas pouvoir se rétrograder.');
    }

    // -------------------------------------------------------------------------
    // Suspension
    // -------------------------------------------------------------------------

    public function test_suspendre_puis_retablir_un_compte(): void
    {
        $cible = User::factory()->create(['is_suspended' => false]);

        $this->actingAs($this->admin())->post(route('admin.users.suspension', $cible));
        $this->assertTrue($cible->fresh()->is_suspended);

        $this->actingAs($this->admin())->post(route('admin.users.suspension', $cible));
        $this->assertFalse($cible->fresh()->is_suspended);
    }

    public function test_on_ne_peut_pas_se_suspendre_soi_meme(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.users.suspension', $admin))->assertSessionHas('error');

        $this->assertFalse($admin->fresh()->is_suspended);
    }

    /**
     * **Un compte suspendu ne peut plus se connecter.**
     *
     * C'est l'effet attendu de la suspension : `is_suspended` n'est pas un drapeau
     * décoratif affiché dans une liste, il coupe l'accès. Sans ce test, la colonne
     * pourrait être renseignée sans que la connexion la consulte — et l'écran
     * afficherait « suspendu » sur un compte parfaitement fonctionnel.
     */
    public function test_un_compte_suspendu_ne_peut_pas_se_connecter(): void
    {
        $cible = User::factory()->create([
            'email' => 'suspendu@formadoc.test',
            'password' => 'motdepasse',
            'is_suspended' => true,
        ]);

        $this->post(route('login.attempt'), [
            'email' => 'suspendu@formadoc.test',
            'password' => 'motdepasse',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_un_compte_ordinaire_se_connecte_normalement(): void
    {
        // Contrôle négatif : sans lui, une régression qui refuserait TOUTE
        // connexion passerait le test précédent.
        User::factory()->create([
            'email' => 'actif@formadoc.test',
            'password' => 'motdepasse',
            'is_suspended' => false,
        ]);

        $this->post(route('login.attempt'), [
            'email' => 'actif@formadoc.test',
            'password' => 'motdepasse',
        ]);

        $this->assertAuthenticated();
    }

    // -------------------------------------------------------------------------
    // Liste et filtres
    // -------------------------------------------------------------------------

    public function test_la_recherche_filtre_sur_le_nom_et_l_email(): void
    {
        User::factory()->create(['name' => 'Alice Dupont', 'email' => 'alice@exemple.test']);
        User::factory()->create(['name' => 'Bruno Martin', 'email' => 'bruno@exemple.test']);

        $response = $this->actingAs($this->admin())
            ->get(route('admin.users.index', ['q' => 'alice']))
            ->assertOk();

        $response->assertSee('Alice Dupont');
        $response->assertDontSee('Bruno Martin');
    }

    /**
     * Le tri n'accepte que les colonnes déclarées.
     *
     * `orderBy($request->input('sort'))` accepterait n'importe quel nom de colonne,
     * y compris une expression SQL : c'est une injection déguisée. La liste est donc
     * fermée dans le contrôleur, et ce test le vérifie.
     */
    public function test_un_tri_inconnu_n_est_pas_concatene_dans_la_requete(): void
    {
        User::factory()->create(['name' => 'Cible']);

        $this->actingAs($this->admin())
            ->get(route('admin.users.index', ['sort' => 'credits; DROP TABLE users']))
            ->assertOk();

        // La table est intacte : la requête n'a pas été exécutée.
        $this->assertDatabaseCount('users', 2);
    }
}
