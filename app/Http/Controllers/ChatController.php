<?php

namespace App\Http\Controllers;

use App\Models\ChatMessage;
use App\Models\ChatSession;
use App\Services\Anthropic\ClaudeSkillsService;
use App\Services\Billing\CreditService;
use App\Services\Billing\QuotaService;
use App\Services\Chat\CapabilitiesService;
use App\Services\Chat\ChatAttachmentService;
use App\Services\Chat\ChatContextCompressor;
use App\Services\Chat\ChatToolsService;
use App\Services\OpenRouter\OpenRouterService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Chat IA avec affichage du coût en crédits.
 *
 * Flux send() :
 *   1. vérification du QUOTA IA mensuel du plan (1 message = 1 unité)
 *   2. estimation du coût AVANT appel (modèle du plan, 200 in / 500 out)
 *      → affichée dans le composer (coût estimé, ajusté après usage)
 *   3. vérification du solde → erreur si insuffisant
 *   4. débit immédiat des crédits estimés
 *   5. appel OpenRouter avec OUTILS (function calling : analyse, reconstruction,
 *      recherche web, image…) et Skills Claude
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
        private readonly ChatContextCompressor $compressor,
    ) {}

    /**
     * Mode d'exécution du chat (auto | chat | agent).
     *
     * Le mode est AUTOMATIQUE : il vient exclusivement de la configuration
     * globale (config/chat.php → CHAT_MODE), dont la valeur par défaut est
     * « agent » (outils obligatoires + exécution automatique). Aucun
     * sélecteur n'est exposé à l'utilisateur, le comportement se gère seul.
     *
     *   agent → outils OBLIGATOIRES (tool_choice: required) : l'IA doit
     *           appeler un outil et l'exécution est automatique. C'est le
     *           mode « qui se gère automatiquement » demandé.
     *   auto  → le modèle décide d'utiliser ou non les outils.
     *   chat  → conversation pure, outils désactivés.
     */
    private function chatMode(Request $request): string
    {
        $configMode = (string) config('chat.mode', 'agent');

        return in_array($configMode, ['auto', 'chat', 'agent'], true) ? $configMode : 'agent';
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
            // Q-PJ : coût fixe par pièce jointe, affiché dynamiquement dans le composer
            'attachmentCostCredits' => (int) config('chat.attachments_cost_credits', 1),
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
            // Q-PJ : coût fixe par pièce jointe, affiché dynamiquement dans le composer
            'attachmentCostCredits' => (int) config('chat.attachments_cost_credits', 1),
            'claudeEligible' => $this->claudeSkills->isEligible($request->user()),
        ]);
    }

    /**
     * Supprime une session de chat (avec ses messages).
     *
     * P1-6 : suppression cascade RGPD — purge aussi les fichiers de la
     * session avant suppression des messages : pièces jointes
     * (chat/attachments/{sessionId}/**) et fichiers générés/claude-skills
     * tracés dans metadata.generated_files des messages assistant.
     */
    public function destroy(Request $request, ChatSession $chatSession): RedirectResponse
    {
        abort_unless($chatSession->user_id === $request->user()->id, 403);

        $chatStorage = Storage::disk('local');

        // Fichiers générés / claude-skills tracés dans les messages (P1-6)
        $generatedPaths = [];
        foreach ($chatSession->messages as $message) {
            $files = $message->metadata['generated_files'] ?? null;
            if (is_array($files)) {
                foreach ($files as $path) {
                    if (is_string($path) && $path !== '') {
                        $generatedPaths[] = $path;
                    }
                }
            }
        }

        // Purge récursive des pièces jointes de la session (P1-6)
        try {
            if ($chatStorage->exists('chat/attachments/'.$chatSession->id)) {
                $chatStorage->deleteDirectory('chat/attachments/'.$chatSession->id);
            }
        } catch (\Throwable) {
            // Best-effort
        }

        $chatSession->messages()->delete();
        $chatSession->delete();

        // Suppression physique des fichiers générés (best-effort)
        foreach (array_unique($generatedPaths) as $path) {
            try {
                if ($chatStorage->exists($path)) {
                    $chatStorage->delete($path);
                }
            } catch (\Throwable) {
                // Fichier déjà absent ou erreur de stockage — on continue
            }
        }

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
    public function downloadFile(Request $request): BinaryFileResponse|RedirectResponse
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
        $storage = Storage::disk('local');
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
        // Q-TEMPS : l'analyse IA (DeepSeekAnalyzer) et la génération de
        // documents peuvent dépasser la limite PHP par défaut (120 s).
        // On repousse la limite au max du timeout DeepSeek + marge.
        if (function_exists('set_time_limit')) {
            set_time_limit((int) config('deepseek.timeout.max', 600) + 60);
        }

        $validated = $request->validate([
            'message' => ['required', 'string', 'max:12000'],
            // P1-4 : pièces jointes (multipart). Validation stricte dans
            // ChatAttachmentService (whitelist extensions, 5 Mo, 5 max).
            'attachments' => ['nullable', 'array', 'max:5'],
            'attachments.*' => ['nullable', 'file'],
        ]);

        $user = $request->user();
        $messageContent = $validated['message'];
        // Q-MODE : mode d'exécution du chat (auto | chat | agent) — AUTOMATIQUE.
        // Plus aucun sélecteur dans l'UI : le mode vient de la configuration
        // globale (config/chat.php → CHAT_MODE, défaut : agent).
        $chatMode = $this->chatMode($request);

        // --- 0. P1-5 : 403 explicite si la session URL appartient à un autre
        // utilisateur. Vérifié AVANT toute estimation/consommation (quota,
        // crédits) pour ne rien gaspiller en cas de tentative d'accès croisé.
        if ($chatSession && $chatSession->user_id !== $user->id) {
            abort(403, 'Cette conversation ne vous appartient pas.');
        }

        // --- 1. Estimation du coût avant appel ---
        // Q-PJ : chaque pièce jointe ajoutée à la conversation a un coût fixe
        // en crédits (traitement + stockage + contexte), en plus du coût du
        // message lui-même. Le total est débité en une fois (jamais séparé).
        $plan = $user->currentPlanSlug();
        $estimate = $this->openRouter->estimateCost('chat_text', 200, 500, $plan);
        $uploaded = $request->file('attachments', []);
        $uploaded = array_values(array_filter($uploaded, fn ($f) => $f instanceof UploadedFile && $f->isValid()));
        $attachmentsCost = count($uploaded) * (int) config('chat.attachments_cost_credits', 1);
        $estimatedCredits = max(1, $estimate['credits']) + $attachmentsCost;

        // --- 2. Vérification du solde ---
        if (! $user->hasCredits($estimatedCredits)) {
            return back()->with('error', 'Crédits insuffisants ('.$estimatedCredits.' requis pour ce message, pièces jointes incluses). Ajoutez des crédits depuis votre compte.');
        }

        // NB : plus d'étape de confirmation de coût (supprimée — l'envoi est
        // direct, le coût estimé est affiché dans le composer avant envoi et
        // ajusté après usage). Le débit a lieu après le traitement (étape 7).

        // --- 3. Quota IA mensuel (1 message IA = 1 unité) ---
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

        // --- 4. Session (création si nouvelle) ---
        // (Le mismatch est déjà bloqué en étape 0 : ici on crée seulement
        // si aucune session n'est fournie.)
        if (! $chatSession) {
            $chatSession = ChatSession::create([
                'user_id' => $user->id,
                'title' => Str::limit($messageContent, 60),
                'model_used' => $estimate['model'],
            ]);
        }

        // --- 5. P1-4 : pièces jointes ---
        // Validation + stockage privé + extraction texte. En cas d'erreur
        // bloquante (aucune pièce acceptée), on annule avant débit.
        // Q-PJ : le coût des PJ (attachmentsCost) a déjà été estimé en étape 1
        // et est débité avec le message.
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
        // Q-PJ : le montant débité inclut le coût des pièces jointes.
        // L'ajustement (étape 11) ne rembourse QUE la partie IA si le coût
        // réel est inférieur ; le coût des PJ reste acquis (traitement fait).
        $debit = $this->credits->debit(
            $user,
            $estimatedCredits,
            type: 'usage',
            reference: 'chat:'.$chatSession->id,
            description: 'Chat IA — message estimé (pièces jointes incluses)',
            metadata: [
                'purpose' => 'chat',
                'chat_session_id' => $chatSession->id,
                'estimated' => true,
                'attachments_count' => count($storedAttachments),
                'attachments_cost_credits' => $attachmentsCost,
            ],
        );

        if (! $debit['ok']) {
            return back()->with('error', 'Impossible de débiter vos crédits ('.$debit['reason'].').');
        }

        // --- 8. Historique du chat (contexte compressé) ---
        // Q-COMPRESSION : pour ne pas trop consommer de crédits sur les
        // longues conversations, l'historique est borné en nombre de messages
        // ET en budget de caractères. Les messages les plus anciens au-delà
        // du budget sont élagués (le modèle garde le fil des plus récents).
        $history = $chatSession->messages()
            ->orderByDesc('created_at')
            ->take((int) config('chat.history_messages', 10))
            ->get()
            ->reverse()
            ->map(fn (ChatMessage $m) => ['role' => $m->role, 'content' => $m->content])
            ->values()
            ->all();

        $history = $this->compressor->limitChars(
            $history,
            (int) config('chat.history_max_chars', 12000),
        );

        // --- 8b. P1-4 : injection du contenu des pièces jointes ---
        // Le bloc est préfixé au dernier message utilisateur pour que le
        // modèle dispose du contenu (txt/md/docx) ou au moins de la mention.
        // Q-PJ : le contenu est borné (budget global) pour maîtriser le coût.
        if ($storedAttachments !== []) {
            $limited = $this->compressor->limitAttachments(
                $storedAttachments,
                (int) config('chat.attachment_max_chars', 8000),
            );

            $block = $this->attachments->contextBlock($limited);
            if ($block !== '') {
                $history[count($history) - 1]['content'] = $block."\n\n".$history[count($history) - 1]['content'];
            }
        }

        // --- 9. Outils actionnables (function calling) ---
        // Q-MODE : selon le mode d'exécution (config chat.mode, défaut agent) :
        //   agent → (défaut) les outils sont OBLIGATOIRES (tool_choice:
        //           'required') et exécutés automatiquement : l'IA est forcée
        //           d'appeler un outil quand la tâche le nécessite (modifier
        //           une pièce jointe, convertir en PDF, générer un Word/PDF…)
        //   auto  → les outils sont proposés, le modèle décide
        //   chat  → conversation pure : pas d'outils envoyés
        $toolOptions = [];

        if ($chatMode !== 'chat') {
            $tools = $this->tools->schemas();
            if ($this->claudeSkills->isEligible($user)) {
                $tools[] = $this->skillSchema();
            }
            $toolOptions['tools'] = $tools;

            if ($chatMode === 'agent' && $tools !== []) {
                // OBLIGE le modèle à appeler un outil (au lieu de répondre en
                // texte libre). L'exécution est automatique (executor ci-dessous).
                $toolOptions['tool_choice'] = 'required';
            }
        }

        // --- 9b. Prompt système (orienté outils) ---
        // Sans message système, le modèle voit les schémas d'outils mais
        // répond en texte au lieu d'utiliser les outils d'édition de
        // documents (document_edit / document_to_pdf / document_create).
        // Le prompt est préfixé à l'historique pour guider le modèle.
        // L'inventaire des capacités (CapabilitiesService) est généré
        // dynamiquement depuis le code : l'IA connaît TOUT ce que l'app peut
        // faire (outils, opérations, conversions, skills Claude, limites +
        // options manuelles) et se met à jour à chaque amélioration.
        $systemPrompt = "Tu es l'assistant IA de FORMADOC, un service de mise en forme de rapports "
            ."académiques. Tu réponds en français. AVANT d'appeler le moindre outil, analyse TOUJOURS "
            .'en profondeur le contenu de la pièce jointe fournie (le bloc « Pièces jointes » du '
            .'contexte) : 1) identifie le contexte global (sujet, chapitres, sections) ; 2) repère '
            .'tous les titres, leur niveau (Titre 1, 2, 3…), leur couleur et leur police ; 3) détecte '
            .'les ambiguïtés et anomalies : titres mal définis, niveaux incohérents, texte parasite '
            ."qui a été inclus DANS un titre (contenu qui n'a rien à y faire), texte qui aurait dû "
            .'être un titre ou une légende, titres en double, formulation trop longue ou trop courte ; '
            .'4) propose des corrections claires pour chaque anomalie. Tu présentes cette analyse dans '
            ."ta réponse avant d'agir. Les outils ne servent UNIQUEMENT qu'à modifier, générer ou "
            .'convertir des fichiers : pour toute action (remplacer un texte, changer un titre, '
            .'ajouter un paragraphe, changer la police, les couleurs, les niveaux de titres, la mise '
            ."en forme complète), utilise l'outil document_edit avec source_path = chemin de la pièce "
            .'jointe. Pour convertir une pièce jointe DOCX en PDF, utilise document_to_pdf. Pour '
            .'convertir un PDF en DOCX éditable, utilise document_to_docx. Pour analyser la structure '
            ."d'un document, utilise document_analyze. Pour créer un document vierge (Word ou PDF) à "
            ."partir d'un contenu, utilise document_create. Annonce brièvement l'action effectuée, le "
            ."nombre de tokens consommés et le nom du fichier généré. À la fin de l'exécution, envoie "
            .'OBLIGATOIREMENT le lien de téléchargement du fichier généré, au format '
            ."chat/generated/... (visible dans le chat), pour que l'utilisateur puisse télécharger "
            ."son document. Chaque appel d'outil est facturé selon les tokens consommés, et la pièce "
            .'jointe analysée est facturée en crédits.';

        if ($chatMode === 'agent') {
            // En mode agent, on insiste : l'outil doit être utilisé dès que la
            // demande implique une action sur un fichier.
            $systemPrompt .= ' Tu es en mode AGENT : utilise systématiquement un outil dès que '
                .'la demande implique une action (modifier, convertir, générer, analyser un '
                .'document). Ne te contente pas de répondre en texte quand un outil est '
                .'pertinent — appelle-le, puis annonce le résultat.';
        }

        // Inventaire dynamique des capacités (auto-à-jour depuis le code)
        try {
            $capabilities = app(CapabilitiesService::class)->toSystemPrompt();
            $systemPrompt .= "\n\n".$capabilities;
        } catch (\Throwable $e) {
            Log::warning('Chat : échec de génération de l\'inventaire des capacités', [
                'error' => $e->getMessage(),
            ]);
        }

        $history = array_merge([['role' => 'system', 'content' => $systemPrompt]], $history);

        // Collecte des fichiers générés pendant les appels d'outils
        // (P0-4) : ils sont tracés dans les métadonnées du message assistant
        // pour permettre un téléchargement sécurisé (ownership vérifié).
        $generatedFiles = [];

        // R7 : comptage des appels d'outils de la chaîne en cours. Un outil qui
        // échoue a quand même été facturé au tarif d'un appel IA — il doit donc
        // être remboursé proportionnellement (étape 11b).
        $toolCallsSucceeded = 0;
        $toolCallsFailed = 0;

        $executor = function (array $toolCall, int $turn) use ($user, $plan, &$generatedFiles, &$toolCallsSucceeded, &$toolCallsFailed): array {
            $result = $this->executeTool($toolCall, $user, $plan);

            if (! empty($result['error'])) {
                $toolCallsFailed++;
            } else {
                $toolCallsSucceeded++;
            }

            // Le résultat d'un outil peut contenir un chemin de fichier généré
            // (chat/generated/... ou claude-skills/...) → on le trace.
            $content = (string) ($result['result'] ?? '');
            if (preg_match('#(chat/generated/[A-Za-z0-9._/-]+|claude-skills/[A-Za-z0-9._/-]+)#', $content, $m)) {
                $generatedFiles[] = $m[1];
            }

            return $result;
        };

        // --- 10. Appel OpenRouter (avec outils) ---
        // Q-FALLBACK : si OpenRouter est down (tous les modèles échouent),
        // le service bascule automatiquement sur l'API DeepSeek directe.
        // Le provider utilisé est tracé dans les métadonnées du message.
        $startedAt = microtime(true);
        try {
            $response = $this->openRouter->chat(
                empty($toolOptions['tools']) ? 'chat_text' : 'function_calling',
                $history,
                $plan,
                array_merge($toolOptions, [
                    'executor' => $executor,
                    // Q-MODE : en mode agent, borne la boucle d'outils pour
                    // éviter qu'un agent parte en boucle infinie.
                    'tool_loop_max_turns' => (int) config('chat.tool_loop_max_turns', 5),
                ]),
            );
        } catch (\Throwable $e) {
            // Échec (OpenRouter ET fallback DeepSeek) → remboursement
            // intégral + restitution du quota IA
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
        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        // --- 11. Coût réel vs estimé → ajustement ---
        // Q-PJ : l'ajustement ne porte QUE sur la partie IA. Le coût des
        // pièces jointes (attachmentsCost) a été consommé (traitement,
        // stockage, extraction) : il n'est pas remboursé.
        $actualCredits = (int) $response['cost_credits'];
        $iaEstimatedCredits = max(1, $estimate['credits']);
        $diff = $iaEstimatedCredits - $actualCredits;

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
                'estimated' => $iaEstimatedCredits,
                'actual' => $actualCredits,
                'chat_session_id' => $chatSession->id,
            ]);
        }

        // --- 11b. Remboursement des échecs d'outils (R7) ---
        // Une chaîne d'outils partiellement échouée a consommé des appels
        // facturés qui n'ont produit aucune valeur pour l'utilisateur. La part
        // remboursée est calculée proportionnellement au nombre d'échecs, et non
        // de façon forfaitaire : rembourser tout serait une perte, ne rien
        // rembourser facturerait un service non rendu.
        //
        // La base est le coût RÉEL (`$actualCredits`), pas l'estimation : l'étape
        // 11 vient déjà de rembourser l'écart entre les deux. Reprendre
        // l'estimation ici rembourserait deux fois la même somme.
        $refundPartiel = 0;
        $toolCallsTotal = $toolCallsSucceeded + $toolCallsFailed;
        if ($toolCallsFailed > 0) {
            $remboursement = $this->credits->refundPartial(
                $user,
                $actualCredits,
                $toolCallsFailed,
                $toolCallsTotal,
                reference: 'chat:'.$chatSession->id,
                description: 'Remboursement partiel chat IA (échecs d\'outils)',
                metadata: ['purpose' => 'chat', 'chat_session_id' => $chatSession->id, 'refund_reason' => 'partial_tool_failure'],
            );
            $refundPartiel = $remboursement['refunded'];
        }

        // --- 12. Enregistrer la réponse ---
        // Q-MASQUAGE : quand le modèle n'a retourné QUE des appels d'outils
        // (sans contenu texte), on n'affiche PAS de message brut "Action(s)
        // exécutée(s)..." à l'utilisateur. On produit un message neutre et
        // clair, et le détail des actions est tracé dans les métadonnées
        // (tool_turns) pour l'UI (badge discret, pas de contenu exposé).
        $content = (string) ($response['content'] ?? '');
        if ($content === '' && ! empty($response['tool_turns'])) {
            $content = 'L\'action demandée a bien été effectuée.';
        }
        if ($content === '') {
            $content = 'Je n\'ai pas pu traiter votre demande. Veuillez reformuler.';
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
                // Q-TEMPS : temps de traitement total (ms) — affiché pour les
                // actions longues (analyse IA, traitement via le chat).
                'duration_ms' => $response['duration_ms'] ?? $durationMs,
                // Q-FALLBACK : provider réellement utilisé (openrouter ou
                // deepseek_fallback) pour la transparence.
                'provider' => $response['provider'] ?? 'openrouter',
                // Q-MODE : mode d'exécution utilisé (auto | chat | agent)
                'mode' => $chatMode,
                // R7 : échecs d'outils et part effectivement remboursée, pour que
                // l'ajustement soit lisible côté utilisateur (support, litiges).
                'tool_calls_succeeded' => $toolCallsSucceeded,
                'tool_calls_failed' => $toolCallsFailed,
                'refunded_credits' => $refundPartiel,
                // P0-4 : fichiers générés pendant ce message (téléchargement sécurisé)
                'generated_files' => array_values(array_unique($generatedFiles)),
            ],
        ]);

        // --- 13. Mise à jour de la session ---
        $chatSession->update([
            'model_used' => $response['model'],
            'total_cost_credits' => $chatSession->total_cost_credits + $actualCredits + $attachmentsCost,
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
                'name' => 'document_skill_generate',
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
     * @param  array<string, mixed>  $toolCall
     * @return array{result?: string, error?: string}
     */
    private function executeTool(array $toolCall, $user, string $plan): array
    {
        $name = (string) ($toolCall['name'] ?? '');

        // Skills documentaires Claude (plans payants ou pay-per-use crédits)
        if ($name === 'document_skill_generate') {
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
