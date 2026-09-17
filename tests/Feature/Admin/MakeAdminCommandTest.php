<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Commande `user:make-admin`.
 *
 * **Ce que ces tests protègent.** Élever un compte donne accès aux coûts,
 * documents et statistiques de TOUS les utilisateurs. Trois propriétés comptent,
 * et chacune correspond à une façon de se tromper :
 *
 *  1. la commande élève VRAIMENT le compte (un `forceFill` oublié laisserait
 *     `is_admin` à `false` et la commande afficherait « élevé » sans effet) ;
 *  2. elle CRÉE le compte quand il n'existe pas (sinon le premier administrateur
 *     reste impossible à obtenir) ;
 *  3. elle ne réinitialise PAS le mot de passe d'un compte existant — un effet
 *     de bord destructeur pour une opération qui ne parle que de droits.
 */
class MakeAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_commande_cree_un_compte_administrateur(): void
    {
        $this->artisan('user:make-admin', ['email' => 'fondateur@exemple.test'])
            ->assertSuccessful();

        $utilisateur = User::where('email', 'fondateur@exemple.test')->firstOrFail();

        $this->assertTrue($utilisateur->is_admin);
        $this->assertNotNull($utilisateur->email_verified_at, 'Un compte d\'exploitation n\'a pas de boîte mail à confirmer.');
    }

    public function test_la_commande_eleve_un_compte_existant_sans_toucher_au_mot_de_passe(): void
    {
        $utilisateur = User::factory()->create(['is_admin' => false]);
        $hachageInitial = $utilisateur->password;

        $this->artisan('user:make-admin', ['email' => $utilisateur->email])
            ->assertSuccessful();

        $utilisateur->refresh();

        $this->assertTrue($utilisateur->is_admin);
        $this->assertSame(
            $hachageInitial,
            $utilisateur->password,
            'Élever un compte ne doit pas réinitialiser son mot de passe.'
        );
    }

    public function test_le_mot_de_passe_fourni_est_bien_hache_et_utilisable(): void
    {
        $this->artisan('user:make-admin', [
            'email' => 'fondateur@exemple.test',
            '--password' => 'MotDePasseTest123!',
        ])->assertSuccessful();

        $utilisateur = User::where('email', 'fondateur@exemple.test')->firstOrFail();

        // Le cast `hashed` du modèle doit produire UN SEUL hachage : vérifier le
        // mot de passe en clair garantit qu'il n'a pas été haché deux fois (auquel
        // cas la connexion serait impossible malgré un compte correct).
        $this->assertTrue(Hash::check('MotDePasseTest123!', $utilisateur->password));
    }

    public function test_le_compte_cree_accede_reellement_a_l_espace_admin(): void
    {
        // Le test qui compte : les propriétés ci-dessus peuvent toutes être vraies
        // alors que l'accès échoue (middleware, groupe de routes, cast). On vérifie
        // donc le comportement observable, pas seulement la colonne.
        $this->artisan('user:make-admin', ['email' => 'fondateur@exemple.test'])
            ->assertSuccessful();

        $utilisateur = User::where('email', 'fondateur@exemple.test')->firstOrFail();

        $this->actingAs($utilisateur)->get(route('admin.index'))->assertOk();
    }

    public function test_une_execution_repetee_est_sans_effet(): void
    {
        // Idempotence : relancer la commande ne doit ni échouer, ni créer un
        // doublon, ni réinitialiser le mot de passe.
        $this->artisan('user:make-admin', ['email' => 'fondateur@exemple.test', '--password' => 'Premier123!'])->assertSuccessful();

        $utilisateur = User::where('email', 'fondateur@exemple.test')->firstOrFail();
        $hachageInitial = $utilisateur->password;

        $this->artisan('user:make-admin', ['email' => 'fondateur@exemple.test'])
            ->expectsOutputToContain('déjà administrateur')
            ->assertSuccessful();

        $this->assertSame(1, User::where('email', 'fondateur@exemple.test')->count());
        $this->assertSame($hachageInitial, $utilisateur->fresh()->password);
    }

    public function test_is_admin_ne_peut_pas_etre_renseigne_par_affectation_de_masse(): void
    {
        // Garde-fou de sécurité, qui justifie le `forceFill` de la commande :
        // `is_admin` est hors de la liste `Fillable`, donc une requête
        // d'inscription contenant `is_admin=1` ne peut pas créer d'administrateur.
        $utilisateur = User::create([
            'name' => 'Intrus',
            'email' => 'intrus@exemple.test',
            'password' => 'MotDePasse123!',
            'is_admin' => true,
        ]);

        $this->assertFalse($utilisateur->fresh()->is_admin);
    }
}
