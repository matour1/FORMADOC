<?php

namespace App\Http\Controllers;

use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Services\Anthropic\ClaudeSkillsService;
use App\Services\Billing\CreditService;
use App\Services\Billing\QuotaService;
use App\Services\Chat\ChatAttachmentService;
use App\Services\Chat\ChatToolsService;
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
 *   1. vérification du QUOTA IA mensuel du plan (1 message = 1 unité)
 *   2. estimation du coût AVANT appel (modèle du plan, 200 in / 500 out)
 *      → affichée à l'utilisateur (étape de confirmation)
 *   3. vérification du solde → erreur si insuffisant
 *   4. débit immédiat des crédits estimés
 *   5. appel OpenRouter avec OUTILS (function calling : page de garde,
 *      reconstruction, recherche web, image…) et Skills Claude
 *      (plans payants ≥ standard inclus, sinon pay-per-use ×1,5 en crédits)
 *   6. ajustement : si coût réel < estimé → remboursement de la différence
 *   7. en cas d'échec → remboursement intégral + restitution du quota IA
 *
 * P1-4 (pièces jointes) : l'UI envoie `attachments[]` en multipart. Elles
 * sont validées (whitelist extensions, 5 Mo, 5 max), stockées sur le disque
 * privé `chat/attachments/{session}/*`, extraites en texte (txt/md/docx)
 * pour enrichir le contexte IA, et tracées dans `metadata.attachments` du
 * message utilisateur (téléchargement sécurisé ownership-vérifié).
 */
class ChatController extends Controller
{
    public function __construct(
        private readonly OpenRouterService $openRouter,
        private readonly CreditService $credits,
        private readonly QuotaService $quotas,
        private readonly ChatToolsService $tools,
        private readonly ClaudeSkillsService $claudeSkills,
        private readonly ChatAttachmentService $attachments,
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

        // Estimation du coût d'un message pour le plan de l'utilisateur (affichée avant envoi)
        $plan = $request->user()->currentPlanSlug();
        $estimate = $this->openRouter->estimateCost('chat_text', 200, 500, $plan);
        $chatModelName = collect(explode('/', $estimate['model']))->last();

        return view('chat.index', [
            'sessions' => $sessions,
            'estimatedCredits' => max(1, $estimate['credits']),
            'chatModelName' => $chatModelName,
            'claudeEligible' => $this->claudeSkills->isEligible($request->user()),
        ]);
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

        // Estimation du coût d'un message pour le plan de l'utilisateur (affichée avant envoi)
        $plan = $request->user()->currentPlanSlug();
        $estimate = $this->openRouter->estimateCost('chat_text', 200, 500, $plan);

        return view('chat.show', [
            'chatSession' => $chatSession,
            'sessions' => $sessions,
            'messages' => $messages,
            'chatCostEstimate' => max(1, $estimate['credits']),
            'claudeEligible' => $this->claudeSkills->isEligible($request->user()),
        ]);
    }

    /**
     * Supprime une session de chat (avec ses messages).
     */
    public function destroy(Request $request, ChatSession $chatSession): RedirectResponse
    {
        abort_unless($chatSession->user_id === $request->user()->id, 403);

        $chatSession->messages()->delete();
        $chatSession->delete();

        return redirect()->route('chat.index')
            ->with('success', 'Conversation supprimée.');
    }

    /**
     * Téléchargement sécurisé d'un fichier généré par le chat.
     *
     * P0-4 : sans cette route, les fichiers `chat/generated/**` et
     * `claude-skills/**` étaient stockés sur le disk 'local' SANS aucun
     * moyen de les récupérer (ni accès public, ni route de téléchargement).
     * Cette route vérifie que le fichier demandé a bien été généré pour
     * l'utilisateur courant (traçé dans metadata.generated_files d'un de
     * ses messages), et refuse tout chemin hors de ces répertoires.
     */
    public function downloadFile(Request $request): \Symfony\Component\HttpFoundation\BinaryFileResponse|RedirectResponse
    {
        $user = $request->user();
        $requestedPath = (string) $request->query('file', '');

        // Normalisation : interdit tout chemin absolu ou traversal (..)
        $path = str_replace('\\', '/', $requestedPath);
        $path = ltrim($path, '/');

        if ($path === '' || str_contains($path, '..') || str_starts_with($path, '/')) {
            abort(404, 'Fichier introuvable.');
        }

        // Seuls les répertoires de fichiers générés / pièces jointes sont autorisés
        $allowedPrefixes = ['chat/generated/', 'claude-skills/', 'chat/attachments/'];
        $inAllowed = false;
        foreach ($allowedPrefixes as $prefix) {
            if (str_starts_with($path, $prefix)) {
                $inAllowed = true;
                break;
            }
        }

        if (! $inAllowed) {
            abort(404, 'Fichier introuvable.');
        }

        // --- Ownership ---
        // Pièces jointes (P1-4) : le chemin contient l'ID de session
        // (chat/attachments/{sessionId}/...). La session doit appartenir
        // à l'utilisateur courant → ownership vérifié, on saute le check
        // `generated_files` (réservé aux fichiers générés par les outils).
        if (str_starts_with($path, 'chat/attachments/')) {
            $segments = explode('/', $path);
            $sessionId = (int) ($segments[2] ?? 0);

            if ($sessionId <= 0 || ! $user->chatSessions()->whereKey($sessionId)->exists()) {
                abort(404, 'Fichier introuvable.');
            }

            $owned = true;
        } else {
            // Fichiers générés : le fichier doit être référencé dans les
            // métadonnées d'un message assistant d'une session appartenant à
            // l'utilisateur.
            $owned = $user->chatSessions()
                ->whereHas('messages', function ($q) use ($path) {
                    $q->where('role', 'assistant')
                        ->whereJsonContains('metadata->generated_files', $path);
                })
                ->exists();

            // Fallback : la référence peut aussi être dans le contenu du message
            // (messages générés avant le traçage metadata). On vérifie alors que
            // le chemin correspond à un message de l'utilisateur.
            if (! $owned) {
                $owned = $user->chatSessions()
                    ->whereHas('messages', function ($q) use ($path) {
                        $q->where('role', 'assistant')
                            ->where('content', 'like', '%'.$path.'%');
                    })
                    ->exists();
            }
        }

        if (! $owned) {
            abort(404, 'Fichier introuvable.');
        }

        // Le fichier doit exister sur le disk local
        $storage = \Illuminate\Support\Facades\Storage::disk('local');
        if (! $storage->exists($path)) {
            abort(404, 'Fichier introuvable.');
        }

        return response()->download($storage->path($path), basename($path));
    }

    /**
     * Envoie un message et obtient la réponse IA (débit crédits).
     */
    public function send(Request $request, ?ChatSession $chatSession = null): RedirectResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:12000'],
            // P1-4 : pièces jointes (multipart). Validation stricte dans
            // ChatAttachmentService (whitelist extensions, 5 Mo, 5 max).
            'attachments' => ['nullable', 'array', 'max:5'],
            'attachments.*' => ['nullable', 'file'],
        ]);

        $user = $request->user();
        $messageContent = $validated['message'];

        // --- 0. P1-5 : 403 explicite si la session URL appartient à un autre
        // utilisateur. Vérifié AVANT toute estimation/consommation (quota,
        // crédits) pour ne rien gaspiller en cas de tentative d'accès croisé.
        if ($chatSession && $chatSession->user_id !== $user->id) {
            abort(403, 'Cette conversation ne vous appartient pas.');
        }

        // --- 1. Estimation du coût avant appel ---
        $plan = $user->currentPlanSlug();
        $estimate = $this->openRouter->estimateCost('chat_text', 200, 500, $plan);
        $estimatedCredits = max(1, $estimate['credits']);

        // --- 2. Vérification du solde ---
        if (! $user->hasCredits($estimatedCredits)) {
            return back()->with('error', 'Crédits insuffisants ('.$estimatedCredits.' requis pour ce message). Ajoutez des crédits depuis votre compte.');
        }

        // --- 3. Confirmation du coût AVANT exécution (exigence A) ---
        // La première soumission affiche le coût estimé ; l'utilisateur
        // confirme explicitement (confirm_cost=1) pour déclencher l'envoi.
        if (! $request->boolean('confirm_cost')) {
            session()->flash('pending_cost', [
                'message' => $messageContent,
                'credits' => $estimatedCredits,
                'plan' => $plan,
            ]);

            return back()->with('info', 'Coût estimé pour ce message : '.$estimatedCredits
                .' crédit(s) (plan '.$plan.'). Confirmez pour envoyer.');
        }

        // --- 4. Quota IA mensuel (1 message IA = 1 unité) ---
        // Les abonnés payants consomment leur quota inclus ; les utilisateurs
        // sans abonnement payant (plan Gratuit, quota IA = 0) passent en
        // pay-per-use : le chat leur est facturé en crédits (Q4), sans quota.
        if ($this->claudeSkills->hasPaidSubscription($user)) {
            $aiQuota = $this->quotas->consume($user, 'ai');
            if (! $aiQuota['ok']) {
                return back()->with('error', 'Quota IA mensuel atteint ('.$aiQuota['used'].'/'
                    .$aiQuota['quota'].'). Passez à un plan supérieur ou attendez la prochaine période.');
            }
        }

        // --- 5. Session (création si nouvelle) ---
        // (Le mismatch est déjà bloqué en étape 0 : ici on crée seulement
        // si aucune session n'est fournie.)
        if (! $chatSession) {
            $chatSession = ChatSession::create([
                'user_id' => $user->id,
                'title' => Str::limit($messageContent, 60),
                'model_used' => $estimate['model'],
            ]);
        }

        // --- 5b. P1-4 : pièces jointes ---
        // Validation + stockage privé + extraction texte. En cas d'erreur
        // bloquante (aucune pièce acceptée), on annule avant débit.
        $uploaded = $request->file('attachments', []);
        $attachmentResult = $this->attachments->handle($uploaded, $chatSession->id);

        if (! $attachmentResult['ok']) {
            return back()->with('error', implode(' ', $attachmentResult['errors']));
        }

        $storedAttachments = $attachmentResult['files'];

        // --- 6. Enregistrer le message utilisateur ---
        ChatMessage::create([
            'chat_session_id' => $chatSession->id,
            'role' => 'user',
            'content' => $messageContent,
            // P1-4 : trace des pièces jointes (nom, chemin, taille, ext)
            // pour l'affichage et le téléchargement sécurisé.
            'metadata' => $storedAttachments === [] ? null : [
                'attachments' => array_map(fn ($att) => [
                    'name' => $att['name'],
                    'path' => $att['path'],
                    'size' => $att['size'],
                    'ext' => $att['ext'],
                ], $storedAttachments),
            ],
        ]);

        // --- 7. Débit immédiat (estimation) ---
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

        // --- 8. Historique du chat (contexte) ---
        $history = $chatSession->messages()
            ->orderByDesc('created_at')
            ->take(10)
            ->get()
            ->reverse()
            ->map(fn (ChatMessage $m) => ['role' => $m->role, 'content' => $m->content])
            ->values()
            ->all();

        // --- 8b. P1-4 : injection du contenu des pièces jointes ---
        // Le bloc est préfixé au dernier message utilisateur pour que le
        // modèle dispose du contenu (txt/md/docx) ou au moins de la mention.
        if ($storedAttachments !== []) {
            $block = $this->attachments->contextBlock($storedAttachments);
            if ($block !== '') {
                $history[count($history) - 1]['content'] = $block . "\n\n" . $history[count($history) - 1]['content'];
            }
        }

        // --- 9. Outils actionnables (function calling) ---
        // Skills Claude : injectés si éligible (plans payants ≥ standard
        // ou pay-per-use via crédits).
        $tools = $this->tools->schemas();
        if ($this->claudeSkills->isEligible($user)) {
            $tools[] = $this->skillSchema();
        }

        // Collecte des fichiers générés pendant les appels d'outils
        // (P0-4) : ils sont tracés dans les métadonnées du message assistant
        // pour permettre un téléchargement sécurisé (ownership vérifié).
        $generatedFiles = [];

        $executor = function (array $toolCall, int $turn) use ($user, $plan, &$generatedFiles): array {
            $result = $this->executeTool($toolCall, $user, $plan);

            // Le résultat d'un outil peut contenir un chemin de fichier généré
            // (chat/generated/... ou claude-skills/...) → on le trace.
            $content = (string) ($result['result'] ?? '');
            if (preg_match('#(chat/generated/[A-Za-z0-9._/-]+|claude-skills/[A-Za-z0-9._/-]+)#', $content, $m)) {
                $generatedFiles[] = $m[1];
            }

            return $result;
        };

        // --- 10. Appel OpenRouter (avec outils) ---
        try {
            $response = $this->openRouter->chat(
                empty($tools) ? 'chat_text' : 'function_calling',
                $history,
                $plan,
                ['tools' => $tools, 'executor' => $executor],
            );
        } catch (\Throwable $e) {
            // Échec → remboursement intégral + restitution du quota IA
            $this->credits->credit(
                $user,
                $estimatedCredits,
                type: 'refund',
                reference: 'chat:'.$chatSession->id,
                description: 'Remboursement chat IA (échec)',
                metadata: ['purpose' => 'chat', 'chat_session_id' => $chatSession->id, 'refund_reason' => 'llm_failure'],
            );
            if ($this->claudeSkills->hasPaidSubscription($user)) {
                $this->quotas->refund($user, 'ai');
            }

            Log::error('Chat IA : échec appel OpenRouter', [
                'user_id' => $user->id,
                'chat_session_id' => $chatSession->id,
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'L\'assistant IA est momentanément indisponible. Vos crédits ont été remboursés.');
        }

        // --- 11. Coût réel vs estimé → ajustement ---
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

        // --- 12. Enregistrer la réponse ---
        $content = (string) ($response['content'] ?? '');
        if ($content === '' && ! empty($response['tool_turns'])) {
            $content = 'Action(s) exécutée(s) avec succès ('.$response['tool_turns'].' appel(s) d\'outil).';
        }

        ChatMessage::create([
            'chat_session_id' => $chatSession->id,
            'role' => 'assistant',
            'content' => $content,
            'model_used' => $response['model'],
            'cost_credits' => $actualCredits,
            'metadata' => [
                'usage' => $response['usage'],
                'estimated_credits' => $estimatedCredits,
                'tool_turns' => $response['tool_turns'] ?? 0,
                // P0-4 : fichiers générés pendant ce message (téléchargement sécurisé)
                'generated_files' => array_values(array_unique($generatedFiles)),
            ],
        ]);

        // --- 13. Mise à jour de la session ---
        $chatSession->update([
            'model_used' => $response['model'],
            'total_cost_credits' => $chatSession->total_cost_credits + $actualCredits,
        ]);

        return redirect()->route('chat.show', $chatSession);
    }

    /* ------------------------------------------------------------------
     |  Exécution des outils (cible de l'executor OpenRouter)
     | ------------------------------------------------------------------ */

    /**
     * Schéma OpenAI de l'outil Skills documentaires Claude.
     *
     * @return array<string, mixed>
     */
    private function skillSchema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'document.skill_generate',
                'description' => 'Génère un document natif Office (docx, xlsx, pptx ou pdf) via les '
                    .'Skills documentaires Claude. Inclus avec les abonnements Standard et supérieurs, '
                    .'ou disponible en pay-per-use via crédits (×1,5). Le fichier est enregistré '
                    .'dans le stockage et son chemin est retourné.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'skill' => ['type' => 'string', 'enum' => ['docx', 'xlsx', 'pptx', 'pdf'], 'description' => 'Type de document à générer'],
                        'prompt' => ['type' => 'string', 'description' => 'Instructions détaillées de génération (contenu, mise en forme)'],
                        'output_name' => ['type' => 'string', 'description' => 'Nom du fichier (sans extension)'],
                    ],
                    'required' => ['skill', 'prompt'],
                ],
            ],
        ];
    }

    /**
     * Exécute un tool_call : Skills Claude ou outils internes/externes.
     *
     * @param array<string, mixed> $toolCall
     *
     * @return array{result?: string, error?: string}
     */
    private function executeTool(array $toolCall, $user, string $plan): array
    {
        $name = (string) ($toolCall['name'] ?? '');

        // Skills documentaires Claude (plans payants ou pay-per-use crédits)
        if ($name === 'document.skill_generate') {
            if (! $this->claudeSkills->isEligible($user)) {
                return ['error' => 'Les Skills documentaires Claude nécessitent un abonnement Standard '
                    .'ou supérieur, ou des crédits suffisants (pay-per-use ×1,5).'];
            }

            $arguments = $this->decodeArguments($toolCall['arguments'] ?? '{}');
            $skill = (string) ($arguments['skill'] ?? '');
            $prompt = (string) ($arguments['prompt'] ?? '');
            $outputName = (string) ($arguments['output_name'] ?? 'document_skill');

            // Pay-per-use : vérifier le solde AVANT génération et débiter
            // le coût skill (tokens + conteneur) majoré ×1,5 sans abonnement.
            $paidSub = $this->claudeSkills->hasPaidSubscription($user);
            $skillCost = $this->claudeSkills->effectiveCost([], $skill, $user);
            $debit = null;

            if (! $paidSub) {
                if (! $user->hasCredits($skillCost)) {
                    return ['error' => 'Crédits insuffisants pour générer ce document ('.$skillCost
                        .' crédits requis, pay-per-use sans abonnement). Ajoutez des crédits depuis votre compte.'];
                }

                $debit = $this->credits->debit(
                    $user,
                    $skillCost,
                    type: 'usage',
                    reference: 'skill:'.$skill.':'.Str::uuid(),
                    description: 'Skill documentaire Claude '.strtoupper($skill).' (pay-per-use ×1,5)',
                    metadata: ['purpose' => 'skill_generate', 'skill' => $skill, 'pay_per_use' => true],
                );

                if (! $debit['ok']) {
                    return ['error' => 'Impossible de débiter vos crédits ('.$debit['reason'].').'];
                }
            }

            try {
                $result = $this->claudeSkills->generate($user, $skill, $prompt, $outputName);

                // Ajustement : coût réel (tokens réels) vs estimation initiale
                if ($debit) {
                    $diff = $skillCost - $result['cost_credits'];
                    if ($diff > 0) {
                        $this->credits->credit(
                            $user,
                            $diff,
                            type: 'refund',
                            reference: 'skill:'.$skill.':'.$user->id,
                            description: 'Ajustement skill Claude (coût réel inférieur à l\'estimation)',
                            metadata: ['purpose' => 'skill_generate', 'skill' => $skill, 'adjustment' => true],
                        );
                    }
                }

                return ['result' => 'Document '.strtoupper($skill).' généré : '
                    .$result['path'].' ('.$result['cost_credits'].' crédits).'];
            } catch (\Throwable $e) {
                // Échec → remboursement du débit pay-per-use
                if ($debit && $debit['ok']) {
                    $this->credits->credit(
                        $user,
                        $skillCost,
                        type: 'refund',
                        reference: 'skill:'.$skill.':'.$user->id,
                        description: 'Remboursement skill Claude (échec génération)',
                        metadata: ['purpose' => 'skill_generate', 'skill' => $skill, 'refund_reason' => 'generation_failure'],
                    );
                }

                Log::warning('Chat : échec Skill Claude, fallback outils internes', [
                    'skill' => $skill,
                    'error' => $e->getMessage(),
                ]);

                return ['error' => 'Génération Claude indisponible : '.$e->getMessage()
                    .' — la génération via outils internes (PHPWord) reste possible.'];
            }
        }

        // Outils internes / externes standards
        return $this->tools->execute($toolCall, $user, $plan);
    }

    /**
     * Décode les arguments JSON d'un tool_call.
     *
     * @return array<string, mixed>
     */
    private function decodeArguments(mixed $arguments): array
    {
        if (is_array($arguments)) {
            return $arguments;
        }

        if (! is_string($arguments) || $arguments === '') {
            return [];
        }

        $decoded = json_decode($arguments, true);

        return is_array($decoded) ? $decoded : [];
    }
}
