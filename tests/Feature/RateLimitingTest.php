<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\OpenRouter\OpenRouterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
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
        $this->mock(OpenRouterService::class, function (Mockery\MockInterface $mock) {
            $mock->shouldReceive('estimateCost')->andReturn([
                'usd' => 0.001,
                'credits' => 1,
                'model' => 'deepseek/deepseek-chat',
            ]);
            $mock->shouldReceive('chat')->andReturn([
                'content' => 'Réponse.',
                'model' => 'deepseek/deepseek-chat',
                'cost_usd' => 0.0005,
                'cost_credits' => 1,
                'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50],
                'tool_turns' => 0,
            ]);
        });

        $user = User::factory()->create(['credits_balance' => 10000]);

        // L'envoi est désormais direct (plus d'étape de confirmation) :
        // chaque POST exécute le message et compte dans le throttle.
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
        $this->mock(OpenRouterService::class, function (Mockery\MockInterface $mock) {
            $mock->shouldReceive('estimateCost')->andReturn([
                'usd' => 0.001,
                'credits' => 1,
                'model' => 'deepseek/deepseek-chat',
            ]);
            $mock->shouldReceive('chat')->andReturn([
                'content' => 'Réponse.',
                'model' => 'deepseek/deepseek-chat',
                'cost_usd' => 0.0005,
                'cost_credits' => 1,
                'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50],
                'tool_turns' => 0,
            ]);
        });

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
