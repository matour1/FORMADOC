<?php

namespace Tests\Feature;

use App\Models\ChatSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P1-1 — Rate limiters applicatifs.
 *
 * POST /chat, login, register, purchase, feedback sont throttlés.
 * En test, CACHE_STORE=array → le cache est isolé par test.
 */
class RateLimitingTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_throttle_login_limite_apres_5_tentatives(): void
    {
        // 5 tentatives autorisées → la 6e est bloquée (429)
        for ($i = 0; $i < 5; $i++) {
            $this->post(route('login.attempt'), [
                'email' => 'test@example.com',
                'password' => 'mauvais',
            ])->assertStatus(302); // validation échoue → redirect back
        }

        $this->post(route('login.attempt'), [
            'email' => 'test@example.com',
            'password' => 'mauvais',
        ])->assertStatus(429);
    }

    public function test_le_throttle_register_limite_apres_3_tentatives(): void
    {
        // Le limiter est par email|IP : on garde le même email pour cumuler
        for ($i = 0; $i < 3; $i++) {
            $this->post(route('register.attempt'), [
                'name' => 'Test',
                'email' => 'meme@example.com',
                'password' => 'password123',
                'password_confirmation' => 'password123',
            ])->assertStatus(302);
        }

        $this->post(route('register.attempt'), [
            'name' => 'Test',
            'email' => 'meme@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertStatus(429);
    }

    public function test_le_throttle_feedback_limite_apres_3_envois(): void
    {
        $payload = [
            'email' => 'avis@example.com',
            'avis' => 'Très bon outil de mise en forme automatique.',
            'note' => '5',
        ];

        for ($i = 0; $i < 3; $i++) {
            $this->post(route('feedback.store'), $payload)
                ->assertStatus(302);
        }

        $this->post(route('feedback.store'), $payload)
            ->assertStatus(429);
    }

    public function test_le_honeypot_feedback_ignore_le_robot(): void
    {
        // Champ caché "website" rempli → le feedback n'est PAS stocké
        $this->post(route('feedback.store'), [
            'email' => 'robot@example.com',
            'avis' => 'Spam automatique spam automatique spam.',
            'note' => '1',
            'website' => 'http://spam.example.com',
        ])->assertStatus(302);

        $this->assertDatabaseCount('feedback', 0);
    }

    public function test_le_throttle_chat_limite_apres_20_envois(): void
    {
        $user = User::factory()->create(['credits_balance' => 10000]);

        // La première requête affiche le coût (confirm_cost absent) → redirect
        // back, mais elle compte quand même dans le throttle (middleware).
        for ($i = 0; $i < 20; $i++) {
            $this->actingAs($user)
                ->post(route('chat.send'), ['message' => 'Message test '.$i])
                ->assertStatus(302);
        }

        $this->actingAs($user)
            ->post(route('chat.send'), ['message' => 'Message test 20'])
            ->assertStatus(429);
    }

    public function test_le_throttle_purchase_limite_apres_5_requetes(): void
    {
        $user = User::factory()->create();

        // amount min = 500 FCFA (config kpay.min_amount)
        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($user)
                ->post(route('credits.purchase'), ['amount' => 500, 'method' => 'kpay'])
                ->assertStatus(302);
        }

        $this->actingAs($user)
            ->post(route('credits.purchase'), ['amount' => 500, 'method' => 'kpay'])
            ->assertStatus(429);
    }

    public function test_le_throttle_est_bien_isole_par_utilisateur(): void
    {
        // Deux utilisateurs différents ne partagent pas le même compteur chat
        $userA = User::factory()->create(['credits_balance' => 10000]);
        $userB = User::factory()->create(['credits_balance' => 10000]);

        for ($i = 0; $i < 20; $i++) {
            $this->actingAs($userA)
                ->post(route('chat.send'), ['message' => 'A '.$i])
                ->assertStatus(302);
        }

        // userA est bloqué…
        $this->actingAs($userA)
            ->post(route('chat.send'), ['message' => 'A bloqué'])
            ->assertStatus(429);

        // …mais userB conserve ses 20 envois
        $this->actingAs($userB)
            ->post(route('chat.send'), ['message' => 'B 1'])
            ->assertStatus(302);
    }
}
