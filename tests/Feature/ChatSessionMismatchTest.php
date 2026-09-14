<?php

namespace Tests\Feature;

use App\Models\ChatSession;
use App\Models\User;
use App\Services\OpenRouter\OpenRouterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * P1-5 — Session mismatch dans ChatController::send().
 *
 * POST /chat/{id} avec une session appartenant à un AUTRE utilisateur doit
 * répondre 403 (et non créer silencieusement une nouvelle session comme avant).
 */
class ChatSessionMismatchTest extends TestCase
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

    public function test_une_session_appartenant_a_un_autre_utilisateur_renvoie_403(): void
    {
        $owner = User::factory()->create(['credits_balance' => 10000]);
        $intruder = User::factory()->create(['credits_balance' => 10000]);

        $session = ChatSession::create([
            'user_id' => $owner->id,
            'title' => 'Session du propriétaire',
        ]);

        $this->actingAs($intruder)
            ->post(route('chat.send', $session), [
                'message' => 'Tentative d\'accès croisé',
            ])
            ->assertForbidden();
    }

    public function test_403_aucune_session_n_est_creee_pour_l_intrus(): void
    {
        $owner = User::factory()->create(['credits_balance' => 10000]);
        $intruder = User::factory()->create(['credits_balance' => 10000]);

        $session = ChatSession::create([
            'user_id' => $owner->id,
            'title' => 'Session du propriétaire',
        ]);

        $countBefore = ChatSession::count();

        $this->actingAs($intruder)
            ->post(route('chat.send', $session), [
                'message' => 'Tentative d\'accès croisé',
            ])
            ->assertForbidden();

        // Aucune session supplémentaire créée, aucune erreur côté propriétaire
        $this->assertSame($countBefore, ChatSession::count());
        $this->assertDatabaseMissing('chat_messages', [
            'chat_session_id' => $session->id,
            'content' => 'Tentative d\'accès croisé',
        ]);
    }

    public function test_403_n_est_pas_renvoye_pour_la_session_de_l_utilisateur(): void
    {
        $this->fakeChatResponse();
        $user = User::factory()->create(['credits_balance' => 10000]);

        $session = ChatSession::create([
            'user_id' => $user->id,
            'title' => 'Ma session',
        ]);

        // L'envoi direct (sans confirm_cost) fonctionne sur sa propre session.
        $this->actingAs($user)
            ->post(route('chat.send', $session), [
                'message' => 'Message légitime',
            ])
            ->assertRedirect(route('chat.show', $session));

        // On a bien réutilisé la session existante (pas de doublon)
        $this->assertDatabaseCount('chat_sessions', 1);
        $this->assertDatabaseHas('chat_sessions', [
            'id' => $session->id,
            'user_id' => $user->id,
        ]);
        $this->assertDatabaseCount('chat_messages', 2); // user + assistant
    }

    public function test_sans_session_fournie_une_nouvelle_session_est_creee(): void
    {
        $this->fakeChatResponse();
        $user = User::factory()->create(['credits_balance' => 10000]);

        $countBefore = ChatSession::count();

        // Sans session dans l'URL → le flux d'envoi direct crée la session
        // (nouvelle conversation), enregistre le message et répond.
        $this->actingAs($user)
            ->post(route('chat.send'), [
                'message' => 'Nouvelle conversation',
            ])
            ->assertRedirect();

        $this->assertSame($countBefore + 1, ChatSession::count());
    }
}
