<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class AuthFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_un_visiteur_est_redirige_vers_login_sur_account(): void
    {
        $this->get(route('account.index'))
            ->assertRedirect(route('login'));
    }

    public function test_inscription_cree_le_compte_et_connecte(): void
    {
        $this->post(route('register.attempt'), [
            'name' => 'Nouvel Utilisateur',
            'email' => 'nouveau@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertRedirect(route('onboarding'));

        $this->assertAuthenticated();
        $this->assertDatabaseHas('users', [
            'email' => 'nouveau@example.com',
            'credits_balance' => 0,
        ]);
    }

    public function test_inscription_refuse_email_deja_utilise(): void
    {
        User::factory()->create(['email' => 'deja@example.com']);

        $this->post(route('register.attempt'), [
            'name' => 'Doublon',
            'email' => 'deja@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_connexion_avec_bons_identifiants(): void
    {
        $user = User::factory()->create(['password' => bcrypt('password123')]);

        $this->post(route('login.attempt'), [
            'email' => $user->email,
            'password' => 'password123',
        ])->assertRedirect(route('account.index'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_connexion_refusee_avec_mauvais_mot_de_passe(): void
    {
        $user = User::factory()->create(['password' => bcrypt('password123')]);

        $this->post(route('login.attempt'), [
            'email' => $user->email,
            'password' => 'mauvais',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_deconnexion_invalide_la_session(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('logout'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_page_mot_de_passe_oublie_est_accessible(): void
    {
        $this->get(route('password.request'))
            ->assertOk()
            ->assertSee('Réinitialiser votre mot de passe');
    }

    public function test_envoi_lien_reinitialisation_pour_email_existant(): void
    {
        $user = User::factory()->create(['email' => 'reset@example.com']);

        $this->post(route('password.email'), [
            'email' => 'reset@example.com',
        ])->assertSessionHas('status');

        $this->assertDatabaseHas('password_reset_tokens', [
            'email' => 'reset@example.com',
        ]);
    }

    public function test_reinitialisation_mot_de_passe(): void
    {
        $user = User::factory()->create(['email' => 'reset2@example.com']);

        $token = Password::broker()->createToken($user);

        $this->get(route('password.reset', $token))
            ->assertOk();

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => 'reset2@example.com',
            'password' => 'nouveaupass123',
            'password_confirmation' => 'nouveaupass123',
        ])->assertRedirect(route('login'));

        $this->assertDatabaseHas('users', [
            'email' => 'reset2@example.com',
        ]);

        // Le mot de passe doit avoir changé
        $fresh = User::where('email', 'reset2@example.com')->first();
        $this->assertTrue(Hash::check('nouveaupass123', $fresh->password));
    }
}
