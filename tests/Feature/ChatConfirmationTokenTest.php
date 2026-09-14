<?php

namespace Tests\Feature;

use App\Models\ChatSession;
use App\Models\User;
use App\Services\OpenRouter\OpenRouterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Envoi direct du chat (la confirmation de coût a été supprimée — UX).
 *
 * Le flux est désormais : POST /chat/{session?} avec `message` → l'estimation
 * est faite, le solde vérifié, le message enregistré et la réponse IA générée.
 * Les anciens champs (confirm_cost / confirm_token) sont ignorés sans erreur
 * (rétrocompatibilité), et un message vide est refusé.
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
            'title' => 'Session envoi direct',
        ]);
    }

    public function test_l_envoi_direct_cree_le_message_et_debite_une_fois(): void
    {
        $this->fakeChatResponse();
        $session = $this->makeSession();

        $this->actingAs($session->user)
            ->post(route('chat.send', $session), [
                'message' => 'Bonjour',
            ])
            ->assertRedirect(route('chat.show', $session));

        // 1 message utilisateur + 1 réponse assistant, 1 débit
        $this->assertDatabaseCount('chat_messages', 2);
        $this->assertSame(9999, $session->user->fresh()->credits_balance);
    }

    public function test_les_anciens_champs_confirm_sont_ignores_sans_erreur(): void
    {
        $this->fakeChatResponse();
        $session = $this->makeSession();

        // Un client qui enverrait encore confirm_cost/confirm_token (ancien flux)
        // ne doit PAS être bloqué : l'envoi direct prime.
        $this->actingAs($session->user)
            ->post(route('chat.send', $session), [
                'message' => 'Bonjour',
                'confirm_cost' => '1',
                'confirm_token' => 'ancien-token',
            ])
            ->assertRedirect(route('chat.show', $session));

        $this->assertDatabaseCount('chat_messages', 2);
        $this->assertSame(9999, $session->user->fresh()->credits_balance);
    }

    public function test_la_creation_de_session_se_fait_sans_session_existante(): void
    {
        $this->fakeChatResponse();
        $user = User::factory()->create(['credits_balance' => 10000]);

        $countBefore = ChatSession::count();

        // Sans session dans l'URL → une nouvelle session est créée
        // (nouvelle conversation depuis la page index).
        $this->actingAs($user)
            ->post(route('chat.send'), [
                'message' => 'Nouvelle conversation',
            ])
            ->assertRedirect();

        $this->assertSame($countBefore + 1, ChatSession::count());
        $this->assertDatabaseHas('chat_sessions', [
            'user_id' => $user->id,
            'title' => 'Nouvelle conversation',
        ]);
        $this->assertDatabaseCount('chat_messages', 2); // user + assistant
        $this->assertSame(9999, $user->fresh()->credits_balance);
    }

    public function test_un_message_sans_texte_est_refuse(): void
    {
        $this->fakeChatResponse();
        $session = $this->makeSession();

        $this->actingAs($session->user)
            ->post(route('chat.send', $session), [
                'message' => '',
            ])
            ->assertSessionHasErrors('message');

        $this->assertDatabaseCount('chat_messages', 0);
        $this->assertSame(10000, $session->user->fresh()->credits_balance);
    }
}
