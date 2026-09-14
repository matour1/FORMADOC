<?php

declare(strict_types=1);

namespace Tests\Unit\Services\OpenRouter;

use App\Services\OpenRouter\DeepSeekFallbackService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Q-FALLBACK — Service fallback DeepSeek direct (function calling).
 *
 * Vérifie que :
 * - le format de réponse DeepSeek est normalisé (même structure qu'OpenRouter) ;
 * - les tool_calls imbriqués ({function:{name,arguments}}) sont aplatis
 *   AVANT d'être passés à l'executor ;
 * - la boucle multi-tours exécute les outils et cumule les coûts ;
 * - le provider retourné est 'deepseek_fallback'.
 */
class DeepSeekFallbackServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('deepseek.api_key', 'sk-test-deepseek');
        Config::set('deepseek.api_url', 'https://deepseek.test/v1');
        Config::set('deepseek.model', 'deepseek-chat');
        Config::set('deepseek.max_retries', 0);
        Config::set('openrouter.rate_fcfa_per_usd', 620.0);
        Config::set('openrouter.cost_infrastructure', 0.15);
        Config::set('openrouter.cost_margin', 0.60);
        Config::set('openrouter.pricing.deepseek/deepseek-chat', ['input' => 0.2574, 'output' => 1.029]);
    }

    private function fakeResponse(array $overrides = []): array
    {
        return array_merge([
            'id' => 'gen-'.uniqid(),
            'model' => 'deepseek-chat',
            'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => 'Réponse finale'],
                'finish_reason' => 'stop',
            ]],
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50, 'total_tokens' => 150],
        ], $overrides);
    }

    private function fakeChat(array $responses): void
    {
        $appel = 0;
        Http::fake([
            'deepseek.test/*' => function () use ($responses, &$appel) {
                $index = min($appel, count($responses) - 1);
                $appel++;

                return Http::response($responses[$index], 200);
            },
        ]);
    }

    public function test_tool_call_imbrique_normalise_avant_executeur(): void
    {
        $tour1 = $this->fakeResponse([
            'choices' => [[
                'index' => 0,
                'message' => [
                    'role' => 'assistant',
                    'content' => null,
                    'tool_calls' => [[
                        'id' => 'call_ds_1',
                        'type' => 'function',
                        'function' => ['name' => 'document_analyze', 'arguments' => '{"source_path":"x.docx"}'],
                    ]],
                ],
                'finish_reason' => 'tool_calls',
            ]],
        ]);
        $tour2 = $this->fakeResponse();

        $this->fakeChat([$tour1, $tour2]);

        $service = new DeepSeekFallbackService;

        $received = null;
        $result = $service->chat([
            ['role' => 'user', 'content' => 'Analyse le document'],
        ], [
            'tools' => [['type' => 'function', 'function' => ['name' => 'document_analyze']]],
            'executor' => function (array $toolCall) use (&$received): array {
                $received = $toolCall;

                return ['result' => 'Structure détectée'];
            },
        ]);

        $this->assertNotNull($received, 'L\'executor doit être appelé');
        $this->assertSame('document_analyze', $received['name']);
        $this->assertSame('{"source_path":"x.docx"}', $received['arguments']);
        $this->assertArrayNotHasKey('function', $received);

        $this->assertSame('deepseek_fallback', $result['provider']);
        $this->assertSame(1, $result['tool_turns']);
        $this->assertSame('deepseek/deepseek-chat', $result['model']);
        $this->assertArrayHasKey('cost_usd', $result);
        $this->assertArrayHasKey('cost_credits', $result);
        $this->assertArrayHasKey('cost_usd_per_turn', $result);
        $this->assertCount(2, $result['cost_usd_per_turn']);
    }

    public function test_le_cout_cumule_tous_les_tours_de_la_boucle(): void
    {
        $tour1 = $this->fakeResponse([
            'choices' => [[
                'index' => 0,
                'message' => [
                    'role' => 'assistant',
                    'content' => null,
                    'tool_calls' => [[
                        'id' => 'call_ds_2',
                        'type' => 'function',
                        'function' => ['name' => 'structure_correct', 'arguments' => '{}'],
                    ]],
                ],
                'finish_reason' => 'tool_calls',
            ]],
        ]);
        $tour2 = $this->fakeResponse([
            'usage' => ['prompt_tokens' => 250, 'completion_tokens' => 120, 'total_tokens' => 370],
        ]);

        $this->fakeChat([$tour1, $tour2]);

        $service = new DeepSeekFallbackService;

        $result = $service->chat([
            ['role' => 'user', 'content' => 'Corrige la structure'],
        ], [
            'tools' => [['type' => 'function', 'function' => ['name' => 'structure_correct']]],
            'executor' => fn (): array => ['result' => 'fait'],
        ]);

        $coutTour1 = 100 / 1_000_000 * 0.2574 + 50 / 1_000_000 * 1.029;
        $coutTour2 = 250 / 1_000_000 * 0.2574 + 120 / 1_000_000 * 1.029;
        $totalUsd = $coutTour1 + $coutTour2;

        $this->assertEqualsWithDelta(round($totalUsd, 6), $result['cost_usd'], 1e-9);
        $this->assertSame(1, $result['tool_turns']);
    }
}
