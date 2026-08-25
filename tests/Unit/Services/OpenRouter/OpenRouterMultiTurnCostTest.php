<?php

declare(strict_types=1);

namespace Tests\Unit\Services\OpenRouter;

use App\Services\OpenRouter\OpenRouterService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * P1-2 — Cumul du coût multi-tours OpenRouter (cost gap).
 *
 * La boucle de function calling effectue plusieurs appels HTTP (1 appel
 * initial + N tours d'outils). Chaque appel consomme des tokens facturés.
 * Avant le correctif, seul le coût du DERNIER tour était retourné →
 * les tours intermédiaires étaient facturés à perte.
 *
 * Ces tests vérifient que `cost_usd` et `cost_credits` cumulent TOUS les tours.
 */
class OpenRouterMultiTurnCostTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Config::set('openrouter.api_key', 'test-key-openrouter');
        Config::set('openrouter.api_url', 'https://openrouter.test/api/v1');
        Config::set('openrouter.rate_fcfa_per_usd', 620.0);
        Config::set('openrouter.cost_infrastructure', 0.15);
        Config::set('openrouter.cost_margin', 0.60);
    }

    /**
     * Réponse OpenRouter simulée.
     */
    private function fakeResponse(array $overrides = []): array
    {
        return array_merge([
            'id' => 'gen-'.uniqid(),
            'model' => 'deepseek/deepseek-chat',
            'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => 'Réponse finale'],
                'finish_reason' => 'stop',
            ]],
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50, 'total_tokens' => 150],
        ], $overrides);
    }

    /**
     * Fake HTTP robuste : renvoie les réponses dans l'ordre, peu importe le
     * nombre d'appels (pas de limite de séquence).
     */
    private function fakeChat(array $responses): void
    {
        $appel = 0;
        Http::fake([
            'openrouter.test/*' => function () use ($responses, &$appel) {
                $index = min($appel, count($responses) - 1);
                $appel++;

                return Http::response($responses[$index], 200);
            },
        ]);
    }

    /**
     * Crédits FCFA pour un coût USD donné (même formule que le service).
     */
    private function creditsFor(float $usd): int
    {
        return (int) ceil($usd * (1 + 0.15) * (1 + 0.60) * 620);
    }

    public function test_le_cout_cumule_tous_les_tours_de_la_boucle_d_outils(): void
    {
        // Tour 1 : le modèle demande un outil
        $tour1 = $this->fakeResponse([
            'choices' => [[
                'index' => 0,
                'message' => [
                    'role' => 'assistant',
                    'content' => null,
                    'tool_calls' => [[
                        'id' => 'call_1',
                        'type' => 'function',
                        'function' => ['name' => 'web.search', 'arguments' => '{"query":"test"}'],
                    ]],
                ],
                'finish_reason' => 'tool_calls',
            ]],
        ]);
        // Tour 2 : réponse finale
        $tour2 = $this->fakeResponse([
            'usage' => ['prompt_tokens' => 250, 'completion_tokens' => 120, 'total_tokens' => 370],
        ]);

        $this->fakeChat([$tour1, $tour2]);

        $service = new OpenRouterService(new \App\Services\OpenRouter\ModelRouter());

        $result = $service->chat('function_calling', [
            ['role' => 'user', 'content' => 'Recherche web + réponse'],
        ], 'default', [
            'tools' => [['type' => 'function', 'function' => ['name' => 'web.search']]],
            'executor' => fn () => ['result' => 'résultat de la recherche'],
        ]);

        // Prix deepseek : input 0.2574 $/1M, output 1.029 $/1M
        $coutTour1 = 100 / 1_000_000 * 0.2574 + 50 / 1_000_000 * 1.029;
        $coutTour2 = 250 / 1_000_000 * 0.2574 + 120 / 1_000_000 * 1.029;
        $totalUsd = $coutTour1 + $coutTour2;

        // Le cumul inclut les DEUX tours (le service arrondit à 6 décimales)
        $this->assertEqualsWithDelta(round($totalUsd, 6), $result['cost_usd'], 1e-9);
        $this->assertGreaterThan($coutTour2, $result['cost_usd']);

        // Crédits = somme des crédits de chaque tour (arrondi par tour)
        $expectedCredits = $this->creditsFor($coutTour1) + $this->creditsFor($coutTour2);
        $this->assertSame($expectedCredits, $result['cost_credits']);

        // Le détail par tour est exposé
        $this->assertCount(2, $result['cost_usd_per_turn']);
        $this->assertEqualsWithDelta(round($coutTour1, 6), $result['cost_usd_per_turn'][0], 1e-9);
        $this->assertEqualsWithDelta(round($coutTour2, 6), $result['cost_usd_per_turn'][1], 1e-9);

        // La boucle a bien tourné 1 fois
        $this->assertSame(1, $result['tool_turns']);
    }

    public function test_sans_outil_le_cout_est_celui_du_tour_unique(): void
    {
        $this->fakeChat([$this->fakeResponse()]);

        $service = new OpenRouterService(new \App\Services\OpenRouter\ModelRouter());

        $result = $service->chat('chat_text', [
            ['role' => 'user', 'content' => 'Bonjour'],
        ], 'default');

        $this->assertArrayNotHasKey('tool_turns', $result);
        $this->assertCount(1, $result['cost_usd_per_turn']);

        $cout = 100 / 1_000_000 * 0.2574 + 50 / 1_000_000 * 1.029;
        $this->assertEqualsWithDelta(round($cout, 6), $result['cost_usd'], 1e-9);
        $this->assertSame($this->creditsFor($cout), $result['cost_credits']);
    }

    public function test_le_cout_cumule_sur_trois_tours_avec_executeur(): void
    {
        // 3 appels : initial (outil) → tour 1 (outil) → tour 2 (final)
        $responses = [
            $this->fakeResponse([
                'choices' => [[
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [[
                            'id' => 'call_a',
                            'type' => 'function',
                            'function' => ['name' => 'structure.correct', 'arguments' => '{}'],
                        ]],
                    ],
                    'finish_reason' => 'tool_calls',
                ]],
            ]),
            $this->fakeResponse([
                'choices' => [[
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [[
                            'id' => 'call_b',
                            'type' => 'function',
                            'function' => ['name' => 'table_of_contents', 'arguments' => '{}'],
                        ]],
                    ],
                    'finish_reason' => 'tool_calls',
                ]],
            ]),
            $this->fakeResponse([
                'usage' => ['prompt_tokens' => 500, 'completion_tokens' => 200, 'total_tokens' => 700],
            ]),
        ];

        $this->fakeChat($responses);

        $service = new OpenRouterService(new \App\Services\OpenRouter\ModelRouter());

        $result = $service->chat('function_calling', [
            ['role' => 'user', 'content' => 'Corrige la structure'],
        ], 'default', [
            'tools' => [['type' => 'function', 'function' => ['name' => 'structure.correct']]],
            'executor' => fn () => ['result' => 'fait'],
        ]);

        // 3 tours : 100/50, 100/50, 500/200 tokens
        $cout1 = 100 / 1_000_000 * 0.2574 + 50 / 1_000_000 * 1.029;
        $cout2 = 100 / 1_000_000 * 0.2574 + 50 / 1_000_000 * 1.029;
        $cout3 = 500 / 1_000_000 * 0.2574 + 200 / 1_000_000 * 1.029;
        $total = $cout1 + $cout2 + $cout3;

        $this->assertEqualsWithDelta(round($total, 6), $result['cost_usd'], 1e-9);
        $this->assertCount(3, $result['cost_usd_per_turn']);
        $this->assertSame(2, $result['tool_turns']);
        $this->assertSame(
            $this->creditsFor($cout1) + $this->creditsFor($cout2) + $this->creditsFor($cout3),
            $result['cost_credits']
        );
    }

    public function test_le_cumul_est_reset_entre_deux_appels_chat(): void
    {
        // Deux appels chat successifs sur la même instance : le cumul du
        // premier ne doit pas polluer le second.
        $service = new OpenRouterService(new \App\Services\OpenRouter\ModelRouter());

        // Appel 1 : multi-tours (2 réponses HTTP)
        $this->fakeChat([
            $this->fakeResponse([
                'choices' => [[
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [[
                            'id' => 'call_1',
                            'type' => 'function',
                            'function' => ['name' => 'web.search', 'arguments' => '{}'],
                        ]],
                    ],
                    'finish_reason' => 'tool_calls',
                ]],
            ]),
            $this->fakeResponse(),
        ]);

        $result1 = $service->chat('function_calling', [
            ['role' => 'user', 'content' => 'Premier message'],
        ], 'default', [
            'tools' => [['type' => 'function', 'function' => ['name' => 'web.search']]],
            'executor' => fn () => ['result' => 'ok'],
        ]);
        $this->assertCount(2, $result1['cost_usd_per_turn']);

        // Appel 2 : un seul tour (nouveau fake, séquence indépendante)
        $this->fakeChat([$this->fakeResponse()]);

        $result2 = $service->chat('chat_text', [
            ['role' => 'user', 'content' => 'Second message'],
        ], 'default');

        $this->assertCount(1, $result2['cost_usd_per_turn']);
        // Le coût du second appel est celui du tour unique, pas le cumul du 1er
        $cout1 = 100 / 1_000_000 * 0.2574 + 50 / 1_000_000 * 1.029;
        $this->assertEqualsWithDelta(round($cout1, 6), $result2['cost_usd'], 1e-9);
    }
}
