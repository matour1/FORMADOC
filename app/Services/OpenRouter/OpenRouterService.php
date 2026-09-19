<?php

namespace App\Services\OpenRouter;

use App\Services\Billing\UsageLedger;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

use function microtime;

/**
 * Client HTTP OpenRouter (API compatible OpenAI).
 *
 * - POST /api/v1/chat/completions avec headers OpenAI + referer/title
 * - Support des outils (function calling) et de la recherche web
 * - Estimation de coût AVANT exécution (estimateCost)
 * - Retries avec backoff sur erreurs réseau / 429
 * - Timeout dynamique selon la longueur du contenu
 *
 * **Registre d'usage (R7)** — chaque tentative est enregistrée dans
 * `AiUsageLedger`, y compris les échecs et les bascules de fournisseur. Le coût
 * facturé est celui du fournisseur, pas celui de ce qui a produit une réponse :
 * un retry échoué coûte aussi.
 */
class OpenRouterService
{
    use NormalizesToolCalls;

    /**
     * P1-2 : coûts USD de chaque tour de la boucle multi-tours en cours
     * (cumulés dans chat(), rempli par parseResponse()).
     *
     * @var array<int, float>
     */
    private array $turnCosts = [];

    /**
     * Nombre de tentatives HTTP de l'appel en cours, retries inclus.
     *
     * `Http::retry()` effectue ses réessais en interne, sans exposer le compteur.
     * On l'incrémente donc depuis le callback `when`, qui est appelé à chaque
     * échec — c'est la seule façon de connaître le nombre réel de tentatives
     * facturées par le fournisseur.
     */
    private int $httpAttempts = 0;

    /**
     * Contexte de facturation du chat en cours (utilisateur, session, document).
     *
     * @var array<string, mixed>
     */
    private array $billingContext = [];

    public function __construct(
        private readonly ModelRouter $router,
        private readonly ?DeepSeekFallbackService $deepSeek = null,
        private readonly ?UsageLedger $ledger = null,
    ) {}

    /**
     * Nombre maximum de secondes sans réponse avant de déclencher le fallback
     * externe DeepSeek. Dépend du timeout OpenRouter en cours.
     */
    private function externalFallbackEnabled(): bool
    {
        return (bool) config('openrouter.external_fallback_enabled', true);
    }

    /**
     * Envoie une requête de chat au modèle sélectionné par le routeur.
     *
     * Options supportées :
     *   - tools, tool_choice : function calling OpenAI
     *   - temperature, max_tokens, response_format
     *   - web_search_options : recherche web native OpenRouter
     *   - executor (callable|null) : exécute les tool_calls demandés par le
     *     modèle, reçoit (array $toolCall, int $turn) et retourne un tableau
     *     {result: string, error?: string}. La boucle tourne tant que le
     *     modèle demande des outils (max tool_loop_max_turns, défaut 5).
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $options
     * @return array<string, mixed> {model, content, tool_calls, usage, cost_usd, cost_credits, raw, tool_turns?}
     *
     * @throws RequestException si tous les candidats échouent
     */
    public function chat(string $taskType, array $messages, string $plan = 'default', array $options = []): array
    {
        $start = microtime(true);
        $selection = $this->router->select($taskType, $plan);

        // Échec silencieux si aucune clé API (l'IA reste optionnelle)
        if (empty(config('openrouter.api_key'))) {
            Log::warning('OpenRouter appelé sans clé API', ['task_type' => $taskType]);
            throw new \RuntimeException('Clé API OpenRouter non configurée.');
        }

        $lastException = null;
        $executor = $options['executor'] ?? null;
        $maxTurns = (int) ($options['tool_loop_max_turns'] ?? 5);
        $turns = 0;

        // Contexte de facturation : il permet de rattacher chaque ligne du
        // registre à une action utilisateur (« chat:12 », « document:34 »).
        // Il vient des options pour ne pas coupler le service au HTTP.
        $this->billingContext = [
            'user_id' => $options['user_id'] ?? null,
            'chat_session_id' => $options['chat_session_id'] ?? null,
            'document_id' => $options['document_id'] ?? null,
            'task_type' => $taskType,
            'reference' => $options['billing_reference'] ?? null,
        ];

        // P1-2 : cumul du coût réel sur TOUS les tours (appel initial +
        // appels d'outils). Avant, seul le coût du dernier tour était
        // retourné → les tours intermédiaires étaient facturés à perte.
        $this->turnCosts = [];

        foreach ($selection['all_candidates'] as $model) {
            // P1-2 : on repart de zéro pour chaque candidat (un échec sur un
            // modèle ne doit pas polluer le cumul du candidat suivant).
            $totalUsd = 0.0;
            $totalCredits = 0;
            $this->turnCosts = [];
            // Les tentatives sont propres à chaque candidat : sans cette remise à
            // zéro, le compteur hérité du modèle précédent gonflerait le nombre
            // de tentatives attribuées au modèle qui finit par répondre.
            $this->httpAttempts = 0;

            try {
                $payload = $this->buildPayload($model, $messages, $options);

                // R7 : mémorisé AVANT l'envoi. En cas d'échec, la requête n'est
                // plus disponible pour estimer les tokens d'entrée que le
                // fournisseur facture — sans cette mesure, le coût des retries
                // serait invisible dans les totaux.
                $this->lastInputCharCount = $this->inputChars($messages);

                $response = Http::withHeaders($this->headers())
                    ->timeout($this->dynamicTimeout($this->lastInputCharCount, count($selection['all_candidates'])))
                    ->retry(
                        (int) config('openrouter.max_retries', 2),
                        (int) config('openrouter.retry_delays_ms.0', 2000),
                        // Laravel 13 : le callback when reçoit ($exception,
                        // $request, $method) — PAS ($attempt, $exception).
                        // R7 : on y compte les tentatives réelles, car chaque
                        // réessai est facturé par le fournisseur même en échec.
                        function ($exception, $request, $method): bool {
                            $this->httpAttempts++;

                            return $this->isRetryable($exception);
                        },
                    )
                    ->post(rtrim(config('openrouter.api_url', 'https://openrouter.ai/api/v1'), '/').'/chat/completions', $payload);

                if (! $response->successful()) {
                    throw new RequestException($response);
                }

                $parsed = $this->parseResponse($response, $model, $selection['plan']);

                // R7 : chaque tour HTTP est enregistré séparément. C'est ce qui
                // rend le coût recalculable depuis les lignes brutes : le total
                // du registre est la somme des lignes, sans cumul opaque.
                // (Enregistré AVANT le contrôle tool_choice : le tour a bien été
                // facturé par le fournisseur, même si on rejette sa réponse.)
                $this->recordSuccess($model, $parsed, turn: 0);

                // Q-TOOLCHOICE : certains fournisseurs (DeepSeek via OpenRouter)
                // IGNORENT « tool_choice: required » et répondent en texte libre.
                // Le tour serait facturé sans qu'aucune action ne soit exécutée :
                // on traite ce cas comme un échec du modèle pour laisser la main
                // au candidat suivant (gpt-4o-mini, qui honore la contrainte).
                if (($options['tool_choice'] ?? null) === 'required'
                    && empty($parsed['tool_calls'])
                    && is_callable($executor)) {
                    throw new ToolChoiceIgnoredException(sprintf(
                        'Le modèle %s a ignoré tool_choice=required (aucun appel d\'outil).',
                        $model
                    ));
                }

                // P1-2 : on cumule le coût de chaque tour
                $totalUsd += $parsed['cost_usd'];
                $totalCredits += $parsed['cost_credits'];

                // Boucle d'exécution des outils (function calling multi-tours)
                while (! empty($parsed['tool_calls']) && is_callable($executor) && $turns < $maxTurns) {
                    $turns++;

                    // Q-FIX-400 : le message assistant avec tool_calls est
                    // ajouté UNE SEULE fois, avant l'exécution des outils.
                    // L'ajouter dans le foreach (une fois par tool_call)
                    // dupliquait les tool_calls → 400 OpenAI "assistant
                    // message with 'tool_calls' must be followed by tool
                    // messages responding to each tool_call_id".
                    $messages[] = [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => $parsed['tool_calls'],
                    ];

                    // Exécution de chaque outil demandé
                    foreach ($parsed['tool_calls'] as $toolCall) {
                        // Normalise le tool_call OpenAI/DeepSeek
                        // ({function:{name,arguments}} → {name,arguments})
                        $toolCall = $this->normalizeToolCall($toolCall);
                        $result = $executor($toolCall, $turns);

                        $messages[] = [
                            'role' => 'tool',
                            'tool_call_id' => $toolCall['id'] ?? 'call_'.Str::uuid(),
                            'content' => $result['error'] ?? $result['result'],
                        ];
                    }

                    // Second appel avec les résultats d'outils
                    // R7 : la requête grandit à chaque tour d'outil ; on remet
                    // donc l'estimation à jour pour que l'entrée facturée d'un
                    // échec en cours de boucle ne soit pas sous-évaluée.
                    $this->lastInputCharCount = $this->inputChars($messages);

                    $response = Http::withHeaders($this->headers())
                        ->timeout($this->dynamicTimeout($this->lastInputCharCount, count($selection['all_candidates'])))
                        ->retry(
                            (int) config('openrouter.max_retries', 2),
                            (int) config('openrouter.retry_delays_ms.0', 2000),
                            fn ($exception, $request, $method) => $this->isRetryable($exception),
                        )
                        ->post(rtrim(config('openrouter.api_url', 'https://openrouter.ai/api/v1'), '/').'/chat/completions', $this->buildPayload($model, $messages, $options));

                    if (! $response->successful()) {
                        throw new RequestException($response);
                    }

                    $parsed = $this->parseResponse($response, $model, $selection['plan']);

                    // R7 : le tour d'outil est facturé comme les autres.
                    $this->recordSuccess($model, $parsed, turn: $turns);

                    // P1-2 : cumul du coût de ce tour d'outil
                    $totalUsd += $parsed['cost_usd'];
                    $totalCredits += $parsed['cost_credits'];
                }

                // Q-SYNTHESE : quand le budget de tours est épuisé, le modèle a
                // enchaîné des appels d'outils sans jamais rédiger sa réponse
                // (content vide). L'utilisateur ne recevrait alors qu'un message
                // neutre, sans analyse ni lien de téléchargement. On demande donc
                // une synthèse FINALE sans outils (tool_choice: none) : le modèle
                // rédige à partir des résultats déjà obtenus.
                if ($turns >= $maxTurns && ! empty($parsed['tool_calls'])) {
                    $messages[] = [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => $parsed['tool_calls'],
                    ];

                    foreach ($parsed['tool_calls'] as $toolCall) {
                        $toolCall = $this->normalizeToolCall($toolCall);
                        $result = $executor($toolCall, $turns);

                        $messages[] = [
                            'role' => 'tool',
                            'tool_call_id' => $toolCall['id'] ?? 'call_'.Str::uuid(),
                            'content' => $result['error'] ?? $result['result'],
                        ];
                    }

                    $messages[] = [
                        'role' => 'user',
                        'content' => 'Toutes les actions sont terminées. Rédige maintenant ta réponse finale '
                            .'en français : 1) le résumé de l\'analyse (structure, titres détectés, '
                            .'ambiguïtés et titres mal définis repérés) ; 2) les corrections proposées ; '
                            .'3) la liste des fichiers générés avec leur lien de téléchargement '
                            .'(au format chat/generated/...). Ne demande pas de confirmation, réponds.',
                    ];

                    $summaryOptions = $options;
                    unset($summaryOptions['tools'], $summaryOptions['tool_choice']);

                    $this->lastInputCharCount = $this->inputChars($messages);

                    $response = Http::withHeaders($this->headers())
                        ->timeout($this->dynamicTimeout($this->lastInputCharCount, count($selection['all_candidates'])))
                        ->retry(
                            (int) config('openrouter.max_retries', 2),
                            (int) config('openrouter.retry_delays_ms.0', 2000),
                            fn ($exception, $request, $method) => $this->isRetryable($exception),
                        )
                        ->post(rtrim(config('openrouter.api_url', 'https://openrouter.ai/api/v1'), '/').'/chat/completions', $this->buildPayload($model, $messages, $summaryOptions));

                    if ($response->successful()) {
                        $summary = $this->parseResponse($response, $model, $selection['plan']);
                        $this->recordSuccess($model, $summary, turn: $turns + 1);
                        $totalUsd += $summary['cost_usd'];
                        $totalCredits += $summary['cost_credits'];

                        // On conserve le contenu rédigé et les outils déjà exécutés.
                        $parsed['content'] = $summary['content'];
                        $parsed['usage'] = $summary['usage'];
                        $parsed['tool_calls'] = [];
                    }
                }

                if ($turns > 0) {
                    $parsed['tool_turns'] = $turns;
                }

                // P1-2 : on écrase les champs coût avec le cumul multi-tours
                // (les champs unitaires restent disponibles dans 'raw'/usage).
                $parsed['cost_usd'] = round($totalUsd, 6);
                $parsed['cost_credits'] = $totalCredits;
                // Coût cumulé par tour (pour debug / métriques)
                $parsed['cost_usd_per_turn'] = array_map(
                    fn ($v) => round($v, 6),
                    $this->turnCosts
                );

                // Temps de traitement total (ms) — affiché à l'utilisateur
                $parsed['duration_ms'] = (int) round((microtime(true) - $start) * 1000);
                $parsed['provider'] = 'openrouter';

                return $parsed;
            } catch (\Throwable $e) {
                $lastException = $e;
                $context = [
                    'model' => $model,
                    'task_type' => $taskType,
                    'error' => $e->getMessage(),
                ];
                // Le message d'exception Laravel est tronqué (~500 chars) :
                // on logge le corps BRUT de la réponse HTTP pour le diagnostic.
                if ($e instanceof RequestException && $e->response !== null) {
                    $context['response_body'] = Str::limit($e->response->body(), 5000);
                }
                Log::warning('OpenRouter : échec du modèle, tentative du fallback', $context);

                // Q-TOOLCHOICE : le tour a DÉJÀ été enregistré en succès (le
                // fournisseur l'a facturé et rapporté son usage). Un
                // recordFailure ajouterait une estimation d'entrée en double.
                if (! $e instanceof ToolChoiceIgnoredException) {
                    // R7 : un modèle candidat qui échoue a tout de même consommé
                    // des tokens d'entrée — le fournisseur les facture. Sans cet
                    // enregistrement, le coût des périodes d'instabilité serait
                    // systématiquement sous-estimé.
                    $this->recordFailure($model, $e);
                }
            }
        }

        // --- Fallback externe : API DeepSeek directe ---
        // Quand TOUS les modèles OpenRouter ont échoué (service down, timeout
        // généralisé, quota épuisé), on bascule sur l'API DeepSeek directe
        // pour que le chat reste fonctionnel. Le coût est estimé sur le même
        // modèle (deepseek-chat) et le provider est tracé.
        if ($this->externalFallbackEnabled() && $this->deepSeek !== null) {
            Log::info('OpenRouter indisponible — bascule sur DeepSeek direct', [
                'task_type' => $taskType,
                'last_error' => $lastException?->getMessage(),
            ]);

            try {
                $fallback = $this->deepSeek->chat($messages, $options);

                // Le fallback DeepSeek exécute le function calling comme
                // OpenRouter (boucle d'outils multi-tours, coût cumulé).
                $fallback['provider'] = 'deepseek_fallback';

                // R7 : la bascule est enregistrée AVEC le modèle d'origine. Sans
                // cette trace, on constaterait un tarif inattendu sans pouvoir
                // expliquer pourquoi le prix a changé en cours de route.
                $this->ledger?->recordFallback(
                    fromModel: (string) ($selection['plan'] ?? 'openrouter'),
                    toModel: (string) ($fallback['model'] ?? 'deepseek-chat'),
                    contexte: [
                        ...$this->billingContext,
                        'input_tokens' => (int) ($fallback['usage']['prompt_tokens'] ?? 0),
                        'output_tokens' => (int) ($fallback['usage']['completion_tokens'] ?? 0),
                        'cost_usd' => (float) ($fallback['cost_usd'] ?? 0.0),
                        'cost_credits' => (int) ($fallback['cost_credits'] ?? 0),
                        'metadata' => ['last_error' => $lastException?->getMessage()],
                    ],
                );

                return $fallback;
            } catch (\Throwable $fallbackError) {
                Log::error('DeepSeek fallback : échec', [
                    'error' => $fallbackError->getMessage(),
                ]);

                // R7 : le repli lui-même a échoué — il reste une tentative
                // facturée du point de vue de l'infrastructure.
                $this->ledger?->recordFailure(
                    'deepseek-chat',
                    'Repli DeepSeek en échec : '.$fallbackError->getMessage(),
                    [...$this->billingContext, 'provider' => 'deepseek_fallback', 'is_fallback' => true],
                );
            }
        }

        if ($lastException instanceof RequestException) {
            throw $lastException;
        }

        throw new \RuntimeException(
            'OpenRouter : tous les modèles ont échoué ('.$lastException?->getMessage().')',
            0,
            $lastException
        );
    }

    /**
     * Estimation du coût en USD puis en crédits (1 crédit = 1 FCFA).
     *
     * Applique le coefficient de rentabilité (exigence C) :
     *   prix_public = coût_API × (1 + infra) × (1 + marge)
     * avec infra = 0.15 et marge = 0.60 → coefficient ≈ 1.84 (≈ 2×).
     *
     * @return array{usd: float, credits: int, model: string}
     */
    public function estimateCost(string $taskType, int $inputTokens, int $outputTokens, string $plan = 'default'): array
    {
        $selection = $this->router->select($taskType, $plan);
        $model = $selection['model'];

        $pricing = config("openrouter.pricing.{$model}", null);

        if ($pricing === null) {
            Log::warning('Prix inconnu pour le modèle OpenRouter', ['model' => $model]);
            $usd = 0.0;
        } elseif (isset($pricing['image'])) {
            // Génération d'image : coût par image (1 image = 1 appel)
            $usd = (float) $pricing['image'] * max(1, $outputTokens);
        } else {
            $usd = ($inputTokens / 1_000_000) * (float) $pricing['input']
                 + ($outputTokens / 1_000_000) * (float) $pricing['output'];
        }

        $credits = $this->usdToCredits($usd);

        return [
            'usd' => round($usd, 6),
            'credits' => $credits,
            'model' => $model,
        ];
    }

    /**
     * Coût USD → crédits avec coefficient de rentabilité.
     */
    private function usdToCredits(float $usd): int
    {
        $infra = (float) config('openrouter.cost_infrastructure', 0.15);
        $margin = (float) config('openrouter.cost_margin', 0.60);
        $rate = (float) config('openrouter.rate_fcfa_per_usd', 620);

        return (int) ceil(max(0.0, $usd) * (1 + $infra) * (1 + $margin) * $rate);
    }

    /**
     * Headers OpenAI-compatible + identification de l'app.
     */
    private function headers(): array
    {
        return [
            'Authorization' => 'Bearer '.config('openrouter.api_key'),
            'Content-Type' => 'application/json',
            'HTTP-Referer' => config('openrouter.app_url', url('/')),
            'X-Title' => config('openrouter.app_name', 'FORMADOC'),
        ];
    }

    /**
     * Construit le payload de la requête (messages + options).
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function buildPayload(string $model, array $messages, array $options): array
    {
        $payload = [
            'model' => $model,
            'messages' => $messages,
        ];

        if (! empty($options['tools'])) {
            $payload['tools'] = $options['tools'];
        }
        if (! empty($options['tool_choice'])) {
            $payload['tool_choice'] = $options['tool_choice'];
        }
        if (isset($options['temperature'])) {
            $payload['temperature'] = (float) $options['temperature'];
        }
        if (isset($options['max_tokens'])) {
            $payload['max_tokens'] = (int) $options['max_tokens'];
        }
        if (! empty($options['web_search_options'])) {
            $payload['web_search_options'] = $options['web_search_options'];
        }
        if (! empty($options['response_format'])) {
            $payload['response_format'] = $options['response_format'];
        }

        return $payload;
    }

    /**
     * Enregistre un appel réussi dans le registre d'usage (R7).
     *
     * Appelée **une fois par tour HTTP** : le coût total du registre est ainsi la
     * somme de ses lignes, donc vérifiable. Les tentatives de retry sont, elles,
     * enregistrées séparément par `recordFailure()`, de sorte que le total
     * reflète ce que le fournisseur facture et non ce qui a produit une réponse.
     *
     * @param  array<string, mixed>  $parsed  Réponse normalisée de `parseResponse()`
     */
    private function recordSuccess(string $model, array $parsed, int $turn = 0): void
    {
        $usage = $parsed['usage'] ?? [];

        $this->ledger?->record([
            ...$this->billingContext,
            'model' => $model,
            'provider' => 'openrouter',
            'input_tokens' => (int) ($usage['prompt_tokens'] ?? 0),
            'output_tokens' => (int) ($usage['completion_tokens'] ?? 0),
            // `httpAttempts` compte les retries effectués AVANT ce succès : le
            // premier essai vaut 1, un succès au deuxième vaut 2. C'est ce qui
            // rend visible le coût d'un fournisseur instable.
            'attempt' => max(1, $this->httpAttempts + 1),
            'succeeded' => true,
            'cost_usd' => (float) ($parsed['cost_usd'] ?? 0.0),
            'cost_credits' => (int) ($parsed['cost_credits'] ?? 0),
            'metadata' => ['turn' => $turn],
        ]);
    }

    /**
     * Enregistre un échec de modèle dans le registre d'usage (R7).
     *
     * Un échec coûte : les tokens d'entrée ont été envoyés, donc facturés. On
     * les estime depuis le contenu réellement transmis quand c'est possible —
     * sous-estimer reviendrait à rendre invisible le coût des retries, qui est
     * justement le poste le plus difficile à anticiper.
     */
    private function recordFailure(string $model, \Throwable $e): void
    {
        $this->ledger?->recordFailure(
            $model,
            $e->getMessage(),
            [
                ...$this->billingContext,
                'attempt' => max(1, $this->httpAttempts),
                // Aucun token de sortie : la réponse n'est jamais arrivée.
                'input_tokens' => $this->estimatedInputTokens(),
                'estimated' => true,
            ],
        );
    }

    /**
     * Estimation des tokens d'entrée pour un appel échoué.
     *
     * Le fournisseur ne rapporte rien quand la requête échoue, mais il facture
     * l'entrée. On retient l'approximation usuelle de 4 caractères par token :
     * imprécise à l'unité, suffisante pour que le coût des échecs cesse d'être
     * invisible dans les totaux.
     */
    private function estimatedInputTokens(): int
    {
        return $this->lastInputCharCount > 0
            ? (int) ceil($this->lastInputCharCount / 4)
            : 0;
    }

    /**
     * Taille (caractères) de la dernière requête envoyée.
     *
     * Mémorisée au moment de l'envoi : en cas d'échec, la requête n'est plus
     * disponible pour estimer les tokens d'entrée facturés.
     */
    private int $lastInputCharCount = 0;

    /**
     * Parse la réponse OpenRouter en structure normalisée.
     *
     * @return array{model: string, content: string, tool_calls: array, usage: array, cost_usd: float, cost_credits: int, raw: array}
     */
    private function parseResponse(Response $response, string $model, string $plan): array
    {
        $data = $response->json();

        $content = $data['choices'][0]['message']['content'] ?? '';
        $toolCalls = $data['choices'][0]['message']['tool_calls'] ?? [];
        $usage = $data['usage'] ?? [];

        $inputTokens = (int) ($usage['prompt_tokens'] ?? 0);
        $outputTokens = (int) ($usage['completion_tokens'] ?? 0);

        // Estimation du coût réel basée sur l'usage retourné
        $pricing = config("openrouter.pricing.{$model}", null);
        if ($pricing !== null && ! isset($pricing['image'])) {
            $usd = ($inputTokens / 1_000_000) * (float) $pricing['input']
                 + ($outputTokens / 1_000_000) * (float) $pricing['output'];
        } else {
            $usd = 0.0;
        }

        $credits = $this->usdToCredits($usd);

        // P1-2 : mémoriser le coût de ce tour (cumulé dans chat())
        $this->turnCosts[] = $usd;

        return [
            'model' => $model,
            'content' => $content,
            'tool_calls' => $toolCalls,
            'usage' => $usage,
            'cost_usd' => round($usd, 6),
            'cost_credits' => $credits,
            'raw' => $data,
        ];
    }

    /**
     * Nombre de caractères de tous les messages (pour le timeout dynamique).
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     */
    private function inputChars(array $messages): int
    {
        $chars = 0;
        foreach ($messages as $message) {
            $chars += strlen((string) ($message['content'] ?? ''));
        }

        return $chars;
    }

    /**
     * Timeout dynamique : base + per_char * chars, borné [min, max].
     */
    private function dynamicTimeout(int $chars, int $attempt = 1): int
    {
        $base = (int) config('openrouter.timeout.base', 180);
        $perChar = (float) config('openrouter.timeout.per_char', 0.008);
        $min = (int) config('openrouter.timeout.min', 120);
        $max = (int) config('openrouter.timeout.max', 600);

        $timeout = (int) ($base + $perChar * $chars);
        $timeout = max($min, min($max, $timeout));

        // Croissance par tentative (fallback)
        $growth = (float) config('openrouter.timeout_growth', 1.5);
        $timeout = (int) ($timeout * ($growth ** max(0, $attempt - 1)));

        return max($min, $timeout);
    }

    /**
     * Une erreur est réessayable si c'est une erreur réseau ou un 429/5xx.
     *
     * @param  mixed  $e  Exception (ou objet inattendu) passé par le callback
     *                    `when` de PendingRequest::retry() (Laravel 13).
     */
    private function isRetryable($e): bool
    {
        if (! $e instanceof \Exception) {
            return false;
        }

        if ($e instanceof ConnectionException) {
            return true;
        }

        if (str_contains($e->getMessage(), 'cURL error')) {
            return true;
        }

        if ($e instanceof RequestException) {
            $status = $e->response->status();

            return $status === 429 || $status >= 500;
        }

        return false;
    }
}
