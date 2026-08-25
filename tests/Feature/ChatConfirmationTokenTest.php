<?php

namespace Tests\Feature;

use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Models\User;
use App\Services\Ai\OpenRouterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * P2-6 : gate d'idempotence sur la confirmation de coût du chat.
 *
 * Le flux légitime est : 1er POST (estimation, sans confirm_cost) → flash
 * pending_cost avec token → 2e POST (confirm_cost=1 + confirm_token) → envoi.
 * Un POST confirm_cost=1 sans token, avec un mauvais token, ou rejoué avec
 * le même token doit être rejeté sans créer de message ni débiter 2 fois.
 */
class ChatConfirmationTokenTest extends TestCase
{
    use RefreshDatabase;

    private function fakeChatResponse(): void
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
    }

    private function makeSession(): ChatSession
    {
        $user = User::factory()->create(['credits_balance' => 10000]);

        return ChatSession::create([
            'user_id' => $user->id,
            'title' => 'Session idempotence',
        ]);
    }

    public function test_la_confirmation_sans_token_est_refusee_et_aucun_message_n_est_cree(): void
    {
        $this->fakeChatResponse();
        $session = $this->makeSession();

        $this->actingAs($session->user)
            ->post(route('chat.send', $session), [
                'message' => 'Bonjour',
                'confirm_cost' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('error', fn (string $value) => str_contains($value, 'Confirmation expirée ou invalide'));

        $this->assertDatabaseCount('chat_messages', 0);
    }

    public function test_la_confirmation_avec_un_mauvais_token_est_refusee(): void
    {
        $this->fakeChatResponse();
        $session = $this->makeSession();

        // Étape 1 : estimation → token généré
        $this->actingAs($session->user)
            ->post(route('chat.send', $session), ['message' => 'Bonjour'])
            ->assertSessionHas('pending_cost');

        // Étape 2 : confirmation avec un token forgé → rejet
        $this->actingAs($session->user)
            ->post(route('chat.send', $session), [
                'message' => 'Bonjour',
                'confirm_cost' => '1',
                'confirm_token' => Str::random(32),
            ])
            ->assertRedirect()
            ->assertSessionHas('error', fn (string $value) => str_contains($value, 'Confirmation expirée ou invalide'));

        $this->assertDatabaseCount('chat_messages', 0);
        $this->assertSame(10000, $session->user->fresh()->credits_balance);
    }

    public function test_le_token_est_a_usage_unique_un_double_post_n_execute_qu_une_fois(): void
    {
        $this->fakeChatResponse();
        $session = $this->makeSession();

        // Étape 1 : estimation → récupération du token
        $this->actingAs($session->user)
            ->post(route('chat.send', $session), ['message' => 'Bonjour'])
            ->assertSessionHas('pending_cost');

        $token = session('pending_cost.token');
        $this->assertIsString($token);
        $this->assertNotEmpty($token);

        // Étape 2a : confirmation légitime → 1 message, 1 débit
        $this->actingAs($session->user)
            ->post(route('chat.send', $session), [
                'message' => 'Bonjour',
                'confirm_cost' => '1',
                'confirm_token' => $token,
            ])
            ->assertRedirect(route('chat.show', $session));

        $this->assertDatabaseCount('chat_messages', 2); // user + assistant
        $this->assertSame(9999, $session->user->fresh()->credits_balance);

        // Étape 2b : rejeu du même token (double-clic / rechargement) → rejet
        $this->actingAs($session->user)
            ->post(route('chat.send', $session), [
                'message' => 'Bonjour',
                'confirm_cost' => '1',
                'confirm_token' => $token,
            ])
            ->assertRedirect()
            ->assertSessionHas('error', fn (string $value) => str_contains($value, 'Confirmation expirée ou invalide'));

        // Toujours 2 messages, aucun double débit
        $this->assertDatabaseCount('chat_messages', 2);
        $this->assertSame(9999, $session->user->fresh()->credits_balance);
    }

    public function test_un_message_different_avec_le_meme_token_est_refuse(): void
    {
        $this->fakeChatResponse();
        $session = $this->makeSession();

        $this->actingAs($session->user)
            ->post(route('chat.send', $session), ['message' => 'Bonjour'])
            ->assertSessionHas('pending_cost');

        $token = session('pending_cost.token');

        // Confirmation avec un message DIFFÉRENT de celui estimé → rejet
        $this->actingAs($session->user)
            ->post(route('chat.send', $session), [
                'message' => 'Message pirate',
                'confirm_cost' => '1',
                'confirm_token' => $token,
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseCount('chat_messages', 0);
        $this->assertSame(10000, $session->user->fresh()->credits_balance);
    }
}
