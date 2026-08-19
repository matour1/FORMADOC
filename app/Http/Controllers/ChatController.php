<?php

namespace App\Http\Controllers;

use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Services\Billing\CreditService;
use App\Services\OpenRouter\OpenRouterService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Chat IA avec affichage du coût en crédits.
 *
 * Flux send() :
 *   1. estimation du coût AVANT appel (modèle du plan, 200 in / 500 out)
 *   2. vérification du solde → erreur si insuffisant
 *   3. débit immédiat des crédits estimés
 *   4. appel OpenRouter (modèle réel, usage réel)
 *   5. ajustement : si coût réel < estimé → remboursement de la différence
 *   6. en cas d'échec → remboursement intégral
 */
class ChatController extends Controller
{
    public function __construct(
        private readonly OpenRouterService $openRouter,
        private readonly CreditService $credits,
    ) {
    }

    /**
     * Liste des sessions de chat de l'utilisateur.
     */
    public function index(Request $request): View
    {
        $sessions = $request->user()
            ->chatSessions()
            ->withCount('messages')
            ->orderByDesc('updated_at')
            ->get();

        return view('chat.index', ['sessions' => $sessions]);
    }

    /**
     * Affiche une session de chat (messages + formulaire).
     */
    public function show(Request $request, ChatSession $chatSession): View
    {
        abort_unless($chatSession->user_id === $request->user()->id, 403);

        $sessions = $request->user()
            ->chatSessions()
            ->withCount('messages')
            ->orderByDesc('updated_at')
            ->get();

        $messages = $chatSession->messages()->get();

        return view('chat.show', [
            'chatSession' => $chatSession,
            'sessions' => $sessions,
            'messages' => $messages,
        ]);
    }

    /**
     * Envoie un message et obtient la réponse IA (débit crédits).
     */
    public function send(Request $request, ?ChatSession $chatSession = null): RedirectResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:12000'],
        ]);

        $user = $request->user();
        $messageContent = $validated['message'];

        // --- 1. Estimation du coût avant appel ---
        $plan = $user->currentPlanSlug();
        $estimate = $this->openRouter->estimateCost('chat_text', 200, 500, $plan);
        $estimatedCredits = max(1, $estimate['credits']);

        // --- 2. Vérification du solde ---
        if (! $user->hasCredits($estimatedCredits)) {
            return back()->with('error', 'Crédits insuffisants ('.$estimatedCredits.' requis pour ce message). Ajoutez des crédits depuis votre compte.');
        }

        // --- 3. Session (création si nouvelle) ---
        if (! $chatSession || $chatSession->user_id !== $user->id) {
            $chatSession = ChatSession::create([
                'user_id' => $user->id,
                'title' => Str::limit($messageContent, 60),
                'model_used' => $estimate['model'],
            ]);
        }

        // --- 4. Enregistrer le message utilisateur ---
        ChatMessage::create([
            'chat_session_id' => $chatSession->id,
            'role' => 'user',
            'content' => $messageContent,
        ]);

        // --- 5. Débit immédiat (estimation) ---
        $debit = $this->credits->debit(
            $user,
            $estimatedCredits,
            type: 'usage',
            reference: 'chat:'.$chatSession->id,
            description: 'Chat IA — message estimé',
            metadata: ['purpose' => 'chat', 'chat_session_id' => $chatSession->id, 'estimated' => true],
        );

        if (! $debit['ok']) {
            return back()->with('error', 'Impossible de débiter vos crédits ('.$debit['reason'].').');
        }

        // --- 6. Historique du chat (contexte) ---
        $history = $chatSession->messages()
            ->orderByDesc('created_at')
            ->take(10)
            ->get()
            ->reverse()
            ->map(fn (ChatMessage $m) => ['role' => $m->role, 'content' => $m->content])
            ->values()
            ->all();

        // --- 7. Appel OpenRouter ---
        try {
            $response = $this->openRouter->chat('chat_text', $history, $plan);
        } catch (\Throwable $e) {
            // Échec → remboursement intégral
            $this->credits->credit(
                $user,
                $estimatedCredits,
                type: 'refund',
                reference: 'chat:'.$chatSession->id,
                description: 'Remboursement chat IA (échec)',
                metadata: ['purpose' => 'chat', 'chat_session_id' => $chatSession->id, 'refund_reason' => 'llm_failure'],
            );

            Log::error('Chat IA : échec appel OpenRouter', [
                'user_id' => $user->id,
                'chat_session_id' => $chatSession->id,
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'L\'assistant IA est momentanément indisponible. Vos crédits ont été remboursés.');
        }

        // --- 8. Coût réel vs estimé → ajustement ---
        $actualCredits = (int) $response['cost_credits'];
        $diff = $estimatedCredits - $actualCredits;

        if ($diff > 0) {
            $this->credits->credit(
                $user,
                $diff,
                type: 'refund',
                reference: 'chat:'.$chatSession->id,
                description: 'Ajustement chat IA (coût réel inférieur à l\'estimation)',
                metadata: ['purpose' => 'chat', 'chat_session_id' => $chatSession->id, 'adjustment' => true],
            );
        } elseif ($diff < 0) {
            // Coût réel supérieur : ne pas débiter davantage (marge absorbée)
            Log::info('Chat IA : coût réel supérieur à l\'estimation', [
                'estimated' => $estimatedCredits,
                'actual' => $actualCredits,
                'chat_session_id' => $chatSession->id,
            ]);
        }

        // --- 9. Enregistrer la réponse ---
        ChatMessage::create([
            'chat_session_id' => $chatSession->id,
            'role' => 'assistant',
            'content' => $response['content'],
            'model_used' => $response['model'],
            'cost_credits' => $actualCredits,
            'metadata' => [
                'usage' => $response['usage'],
                'estimated_credits' => $estimatedCredits,
            ],
        ]);

        // --- 10. Mise à jour de la session ---
        $chatSession->update([
            'model_used' => $response['model'],
            'total_cost_credits' => $chatSession->total_cost_credits + $actualCredits,
        ]);

        return redirect()->route('chat.show', $chatSession);
    }
}
