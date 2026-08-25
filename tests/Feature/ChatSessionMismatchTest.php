<?php

namespace Tests\Feature;

use App\Models\ChatSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
                'confirm_cost' => '1',
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
                'confirm_cost' => '1',
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
        $user = User::factory()->create(['credits_balance' => 10000]);

        $session = ChatSession::create([
            'user_id' => $user->id,
            'title' => 'Ma session',
        ]);

        // Sans confirm_cost → redirect back (pas de 403) : le flux normal
        // d'estimation du coût fonctionne toujours sur sa propre session.
        $this->actingAs($user)
            ->post(route('chat.send', $session), [
                'message' => 'Message légitime',
            ])
            ->assertRedirect()
            ->assertSessionHas('info');

        // Aucune nouvelle session créée (on a bien réutilisé la sienne)
        $this->assertDatabaseHas('chat_sessions', [
            'id' => $session->id,
            'user_id' => $user->id,
        ]);
        $this->assertDatabaseMissing('chat_messages', [
            'chat_session_id' => $session->id,
        ]);
    }

    public function test_sans_session_fournie_le_flux_de_confirmation_du_cout_fonctionne(): void
    {
        $user = User::factory()->create(['credits_balance' => 10000]);

        $countBefore = ChatSession::count();

        // Sans session dans l'URL ni confirm_cost → le flux s'arrête à la
        // confirmation du coût (étape 3), aucune session créée, pas de 403.
        $this->actingAs($user)
            ->post(route('chat.send'), [
                'message' => 'Nouvelle conversation',
            ])
            ->assertRedirect()
            ->assertSessionHas('info');

        $this->assertSame($countBefore, ChatSession::count());
    }
}
