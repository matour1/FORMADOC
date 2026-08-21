<?php

namespace App\Services\OpenRouter;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Client HTTP OpenRouter (API compatible OpenAI).
 *
 * - POST /api/v1/chat/completions avec headers OpenAI + referer/title
 * - Support des outils (function calling) et de la recherche web
 * - Estimation de coût AVANT exécution (estimateCost)
 * - Retries avec backoff sur erreurs réseau / 429
 * - Timeout dynamique selon la longueur du contenu
 */
class OpenRouterService
{
    public function __construct(
        private readonly ModelRouter $router,
    ) {
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
     * @param array<int, array{role: string, content: string}> $messages
     * @param array<string, mixed> $options
     * @return array<string, mixed> {model, content, tool_calls, usage, cost_usd, cost_credits, raw, tool_turns?}
     *
     * @throws \Illuminate\Http\Client\RequestException si tous les candidats échouent
     */
    public function chat(string $taskType, array $messages, string $plan = 'default', array $options = []): array
    {
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

        foreach ($selection['all_candidates'] as $model) {
            try {
                $payload = $this->buildPayload($model, $messages, $options);

                $response = Http::withHeaders($this->headers())
                    ->timeout($this->dynamicTimeout($this->inputChars($messages), count($selection['all_candidates'])))
                    ->retry(
                        (int) config('openrouter.max_retries', 2),
                        (int) config('openrouter.retry_delays_ms.0', 2000),
                        fn (int $attempt, \Exception $e) => $this->isRetryable($e),
                    )
                    ->post(rtrim(config('openrouter.api_url', 'https://openrouter.ai/api/v1'), '/').'/chat/completions', $payload);

                if (! $response->successful()) {
                    throw new RequestException($response);
                }

                $parsed = $this->parseResponse($response, $model, $selection['plan']);

                // Boucle d'exécution des outils (function calling multi-tours)
                while (! empty($parsed['tool_calls']) && is_callable($executor) && $turns < $maxTurns) {
                    $turns++;

                    // Exécution de chaque outil demandé
                    foreach ($parsed['tool_calls'] as $toolCall) {
                        $result = $executor($toolCall, $turns);

                        $messages[] = [
                            'role' => 'assistant',
                            'content' => null,
                            'tool_calls' => $parsed['tool_calls'],
                        ];
                        $messages[] = [
                            'role' => 'tool',
                            'tool_call_id' => $toolCall['id'] ?? 'call_'.Str::uuid(),
                            'content' => $result['error'] ?? $result['result'],
                        ];
                    }

                    // Second appel avec les résultats d'outils
                    $response = Http::withHeaders($this->headers())
                        ->timeout($this->dynamicTimeout($this->inputChars($messages), count($selection['all_candidates'])))
                        ->retry(
                            (int) config('openrouter.max_retries', 2),
                            (int) config('openrouter.retry_delays_ms.0', 2000),
                            fn (int $attempt, \Exception $e) => $this->isRetryable($e),
                        )
                        ->post(rtrim(config('openrouter.api_url', 'https://openrouter.ai/api/v1'), '/').'/chat/completions', $this->buildPayload($model, $messages, $options));

                    if (! $response->successful()) {
                        throw new RequestException($response);
                    }

                    $parsed = $this->parseResponse($response, $model, $selection['plan']);
                }

                if ($turns > 0) {
                    $parsed['tool_turns'] = $turns;
                }

                return $parsed;
            } catch (\Throwable $e) {
                $lastException = $e;
                Log::warning('OpenRouter : échec du modèle, tentative du fallback', [
                    'model' => $model,
                    'task_type' => $taskType,
                    'error' => $e->getMessage(),
                ]);
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
     * @param array<int, array{role: string, content: string}> $messages
     * @param array<string, mixed> $options
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
     * @param array<int, array{role: string, content: string}> $messages
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
     */
    private function isRetryable(\Exception $e): bool
    {
        if ($e instanceof ConnectionException) {
            return true;
        }

        if (str_contains($e->getMessage(), 'cURL error')) {
            return true;
        }

        if ($e instanceof \Illuminate\Http\Client\RequestException) {
            $status = $e->response->status();
            return $status === 429 || $status >= 500;
        }

        return false;
    }
}
