<?php

namespace App\Services\OpenRouter;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Fallback direct vers l'API DeepSeek quand OpenRouter est indisponible.
 *
 * OpenRouter agrège plusieurs modèles, mais si le service est down (timeout,
 * 5xx généralisés), le chat doit continuer de fonctionner. Ce service appelle
 * directement l'API DeepSeek (même modèle deepseek-chat, endpoints OpenAI
 * compatibles) avec la même structure de réponse normalisée que
 * OpenRouterService (model, content, tool_calls, usage, cost_usd,
 * cost_credits, raw).
 *
 * Note : le function calling est supporté (mêmes outils que OpenRouter,
 * endpoints OpenAI compatibles) via une boucle d'exécution multi-tours.
 */
class DeepSeekFallbackService
{
    use NormalizesToolCalls;

    /**
     * Appelle l'API DeepSeek directe.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $options
     * @return array<string, mixed> {model, content, tool_calls, usage, cost_usd, cost_credits, raw, tool_turns?}
     *
     * @throws \RuntimeException si l'API DeepSeek échoue aussi
     */
    public function chat(array $messages, array $options = []): array
    {
        $apiKey = (string) config('deepseek.api_key', '');
        $apiUrl = rtrim((string) config('deepseek.api_url', 'https://api.deepseek.com/v1'), '/');
        $model = (string) config('deepseek.model', 'deepseek-chat');

        if ($apiKey === '') {
            throw new \RuntimeException('Clé API DeepSeek non configurée pour le fallback.');
        }

        $start = microtime(true);
        $executor = $options['executor'] ?? null;
        $maxTurns = (int) ($options['tool_loop_max_turns'] ?? 5);
        $turns = 0;
        $totalUsd = 0.0;
        $totalCredits = 0;
        $turnCosts = [];

        try {
            $parsed = $this->callOnce($apiKey, $apiUrl, $model, $messages, $options, $turnCosts);
            $totalUsd += $parsed['cost_usd'];
            $totalCredits += $parsed['cost_credits'];

            // Boucle d'exécution des outils (function calling multi-tours,
            // même pattern que OpenRouterService).
            while (! empty($parsed['tool_calls']) && is_callable($executor) && $turns < $maxTurns) {
                $turns++;

                // Q-FIX-400 : le message assistant avec tool_calls est ajouté
                // UNE seule fois (avant l'exécution des outils), sinon les
                // tool_call_ids sont dupliqués → 400 OpenAI/DeepSeek.
                $messages[] = [
                    'role' => 'assistant',
                    'content' => null,
                    'tool_calls' => $parsed['tool_calls'],
                ];

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

                $parsed = $this->callOnce($apiKey, $apiUrl, $model, $messages, $options, $turnCosts);
                $totalUsd += $parsed['cost_usd'];
                $totalCredits += $parsed['cost_credits'];
            }

            if ($turns > 0) {
                $parsed['tool_turns'] = $turns;
            }

            // Coût cumulé sur TOUS les tours (comme OpenRouterService)
            $parsed['cost_usd'] = round($totalUsd, 6);
            $parsed['cost_credits'] = $totalCredits;
            $parsed['cost_usd_per_turn'] = array_map(
                fn ($v) => round($v, 6),
                $turnCosts
            );
            $parsed['duration_ms'] = (int) round((microtime(true) - $start) * 1000);
            $parsed['provider'] = 'deepseek_fallback';

            return $parsed;
        } catch (\Throwable $e) {
            Log::warning('DeepSeek fallback : échec', [
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException('DeepSeek fallback : '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Un seul appel HTTP à DeepSeek + parsing normalisé.
     *
     * @return array<string, mixed> {model, content, tool_calls, usage, cost_usd, cost_credits, raw}
     */
    private function callOnce(string $apiKey, string $apiUrl, string $model, array $messages, array $options, array &$turnCosts): array
    {
        $payload = [
            'model' => $model,
            'messages' => $this->normalizeMessages($messages, ! empty($options['tools'])),
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

        $timeout = $this->timeout(count($messages));

        $response = Http::withToken($apiKey)
            ->acceptJson()
            ->timeout($timeout)
            ->retry((int) config('deepseek.max_retries', 1), 2000)
            ->post($apiUrl.'/chat/completions', $payload);

        if (! $response->successful()) {
            throw new \RuntimeException('DeepSeek fallback : HTTP '.$response->status().' — '.Str::limit($response->body(), 200));
        }

        $data = $response->json();

        $content = (string) ($data['choices'][0]['message']['content'] ?? '');
        $toolCalls = $data['choices'][0]['message']['tool_calls'] ?? [];
        $usage = $data['usage'] ?? [];

        // Prix du modèle deepseek (input/output) — estimés depuis la
        // config openrouter (même modèle), sinon défaut approximatif.
        $pricing = config('openrouter.pricing.deepseek/deepseek-chat', ['input' => 0.2574, 'output' => 1.029]);
        $inputTokens = (int) ($usage['prompt_tokens'] ?? 0);
        $outputTokens = (int) ($usage['completion_tokens'] ?? 0);
        $usd = ($inputTokens / 1_000_000) * (float) $pricing['input']
             + ($outputTokens / 1_000_000) * (float) $pricing['output'];

        $turnCosts[] = $usd;

        return [
            'model' => 'deepseek/'.$model,
            'content' => $content,
            'tool_calls' => $toolCalls,
            'usage' => $usage,
            'cost_usd' => round($usd, 6),
            'cost_credits' => $this->usdToCredits($usd),
            'raw' => $data,
        ];
    }

    /**
     * Normalise les messages pour DeepSeek.
     *
     * - Sans outils : les rôles tool/function sont retirés et le contenu
     *   aplati (mode texte simple).
     * - Avec outils : les messages assistant avec tool_calls et les messages
     *   tool sont conservés (nécessaires à la boucle de function calling).
     *
     * @param  array<int, array{role: string, content: ?string}>  $messages
     * @return array<int, array{role: string, content: ?string, tool_calls?: array, tool_call_id?: string}>
     */
    private function normalizeMessages(array $messages, bool $withTools = false): array
    {
        $out = [];
        foreach ($messages as $message) {
            $role = (string) ($message['role'] ?? 'user');

            if (! $withTools && ($role === 'tool' || $role === 'function')) {
                continue;
            }

            $normalized = [
                'role' => $role,
                // Préserve null (assistant tool_calls / messages tool), sinon
                // aplatit en chaîne.
                'content' => ($message['content'] ?? '') === null ? null : (string) ($message['content'] ?? ''),
            ];

            if ($role === 'assistant' && ! empty($message['tool_calls']) && $withTools) {
                $normalized['tool_calls'] = $message['tool_calls'];
            }
            if ($role === 'tool') {
                $normalized['tool_call_id'] = (string) ($message['tool_call_id'] ?? 'call_'.Str::uuid());
            }

            $out[] = $normalized;
        }

        // Évite un historique vide
        if ($out === []) {
            $out[] = ['role' => 'user', 'content' => ''];
        }

        return $out;
    }

    /**
     * Timeout proportionnel au nombre de messages (léger).
     */
    private function timeout(int $messageCount): int
    {
        $base = (int) config('deepseek.timeout.base', 120);

        return max(30, $base + $messageCount * 5);
    }

    /**
     * Conversion USD → crédits (même logique que OpenRouterService).
     */
    private function usdToCredits(float $usd): int
    {
        if ($usd <= 0) {
            return 0;
        }

        $infra = (float) config('openrouter.cost_infrastructure', 0.15);
        $margin = (float) config('openrouter.cost_margin', 0.60);
        $rate = (float) config('openrouter.rate_fcfa_per_usd', 620);

        return (int) ceil($usd * (1 + $infra) * (1 + $margin) * $rate);
    }
}
