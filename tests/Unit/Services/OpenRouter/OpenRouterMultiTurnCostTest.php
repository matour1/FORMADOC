<?php

declare(strict_types=1);

namespace Tests\Unit\Services\OpenRouter;

use App\Services\OpenRouter\ModelRouter;
use App\Services\OpenRouter\OpenRouterService;
use Illuminate\Http\Client\Request;
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

    public function test_le_tool_call_imbrique_est_normalise_avant_lexecuteur(): void
    {
        // Tour 1 : le modèle demande un outil au format OpenAI/DeepSeek
        // imbriqué ({function:{name,arguments}}).
        $tour1 = $this->fakeResponse([
            'choices' => [[
                'index' => 0,
                'message' => [
                    'role' => 'assistant',
                    'content' => null,
                    'tool_calls' => [[
                        'id' => 'call_1',
                        'type' => 'function',
                        'function' => ['name' => 'web_search', 'arguments' => '{"query":"test"}'],
                    ]],
                ],
                'finish_reason' => 'tool_calls',
            ]],
        ]);
        // Tour 2 : réponse finale
        $tour2 = $this->fakeResponse();

        $this->fakeChat([$tour1, $tour2]);

        $service = new OpenRouterService(new ModelRouter);

        $received = null;
        $result = $service->chat('function_calling', [
            ['role' => 'user', 'content' => 'Recherche web'],
        ], 'default', [
            'tools' => [['type' => 'function', 'function' => ['name' => 'web_search']]],
            'executor' => function (array $toolCall) use (&$received): array {
                $received = $toolCall;

                return ['result' => 'résultat'];
            },
        ]);

        // L'executor a reçu le nom APLATI (pas de clé 'function' imbriquée)
        $this->assertNotNull($received, 'L\'executor doit être appelé avec le tool_call');
        $this->assertSame('web_search', $received['name']);
        $this->assertSame('{"query":"test"}', $received['arguments']);
        $this->assertArrayNotHasKey('function', $received);

        // La boucle a bien tourné 1 fois
        $this->assertSame(1, $result['tool_turns']);
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
                        'function' => ['name' => 'web_search', 'arguments' => '{"query":"test"}'],
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

        $service = new OpenRouterService(new ModelRouter);

        $result = $service->chat('function_calling', [
            ['role' => 'user', 'content' => 'Recherche web + réponse'],
        ], 'default', [
            'tools' => [['type' => 'function', 'function' => ['name' => 'web_search']]],
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

        $service = new OpenRouterService(new ModelRouter);

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
                            'function' => ['name' => 'structure_correct', 'arguments' => '{}'],
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

        $service = new OpenRouterService(new ModelRouter);

        $result = $service->chat('function_calling', [
            ['role' => 'user', 'content' => 'Corrige la structure'],
        ], 'default', [
            'tools' => [['type' => 'function', 'function' => ['name' => 'structure_correct']]],
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
        $service = new OpenRouterService(new ModelRouter);

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
                            'function' => ['name' => 'web_search', 'arguments' => '{}'],
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
            'tools' => [['type' => 'function', 'function' => ['name' => 'web_search']]],
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

    /**
     * Le callback `when` de PendingRequest::retry() (Laravel 13) est appelé
     * avec ($exception, $request, $method) — PAS ($attempt, $exception).
     * Avant le correctif, la closure typée `fn (int $attempt, ...)` jetait
     * un TypeError dès la première réponse 5xx → le chat plantait.
     */
    public function test_le_callback_retry_accepte_la_signature_laravel_13(): void
    {
        Config::set('openrouter.max_retries', 2);
        Config::set('openrouter.retry_delays_ms', [0, 0, 0, 0]);

        $appel = 0;
        Http::fake([
            'openrouter.test/*' => function () use (&$appel) {
                $appel++;
                if ($appel === 1) {
                    // 503 Service Unavailable → retryable (>= 500)
                    return Http::response(['error' => 'overloaded'], 503);
                }

                return Http::response($this->fakeResponse(), 200);
            },
        ]);

        $service = new OpenRouterService(new ModelRouter);

        // Doit réussir après le retry (avant le correctif : TypeError)
        $result = $service->chat('chat_text', [
            ['role' => 'user', 'content' => 'Retry test'],
        ], 'default');

        $this->assertSame('Réponse finale', $result['content']);
        $this->assertSame(2, $appel);
    }

    /**
     * Q-TOOLCHOICE : un modèle qui ignore tool_choice=required (constaté avec
     * deepseek/deepseek-chat via OpenRouter) répond en texte au lieu d'appeler
     * un outil. Sans détection, la demande restait sans effet (aucun fichier
     * généré, aucun lien) tout en débitant des crédits.
     *
     * Comportement attendu : basculer sur le candidat suivant, qui honore la
     * contrainte.
     */
    public function test_bascule_si_le_modele_ignore_tool_choice_required(): void
    {
        Config::set('openrouter.models.function_calling.default', [
            'deepseek/deepseek-chat',
            'openai/gpt-4o-mini',
        ]);

        $appel = 0;
        Http::fake([
            'openrouter.test/*' => function (Request $request) use (&$appel) {
                $appel++;
                $model = (string) (($request->data()['model'] ?? ''));

                // Le modèle fautif répond en texte, sans aucun tool_call
                if ($model === 'deepseek/deepseek-chat') {
                    return Http::response($this->fakeResponse([
                        'model' => $model,
                        'choices' => [[
                            'index' => 0,
                            'message' => [
                                'role' => 'assistant',
                                'content' => 'Dites-moi ce que vous souhaitez faire !',
                            ],
                            'finish_reason' => 'stop',
                        ]],
                    ]), 200);
                }

                // Les autres modèles respectent la contrainte
                return Http::response($this->fakeResponse([
                    'model' => $model,
                    'choices' => [[
                        'index' => 0,
                        'message' => [
                            'role' => 'assistant',
                            'content' => null,
                            'tool_calls' => [[
                                'id' => 'call_1',
                                'type' => 'function',
                                'function' => ['name' => 'document_analyze', 'arguments' => '{"source_path":"chat/attachments/1/a.docx"}'],
                            ]],
                        ],
                        'finish_reason' => 'tool_calls',
                    ]],
                ]), 200);
            },
        ]);

        $service = new OpenRouterService(new ModelRouter);

        $executed = [];
        $result = $service->chat('function_calling', [
            ['role' => 'user', 'content' => 'Analyse ma pièce jointe'],
        ], 'default', [
            'tools' => [['type' => 'function', 'function' => ['name' => 'document_analyze']]],
            'tool_choice' => 'required',
            'executor' => function (array $toolCall) use (&$executed): array {
                $executed[] = $toolCall['name'];

                return ['result' => 'analyse ok'];
            },
        ]);

        // L'outil a bien été exécuté malgré le premier modèle défaillant
        $this->assertContains('document_analyze', $executed);
        $this->assertNotSame('deepseek/deepseek-chat', $result['model']);
        $this->assertGreaterThanOrEqual(2, $appel, 'Un autre modèle doit être tenté');
    }

    /**
     * Sans la contrainte (mode auto), une réponse en texte reste valide : on ne
     * doit PAS basculer inutilement sur un autre modèle.
     */
    public function test_ne_bascule_pas_sans_tool_choice_required(): void
    {
        $appel = 0;
        Http::fake([
            'openrouter.test/*' => function () use (&$appel) {
                $appel++;

                return Http::response($this->fakeResponse(), 200);
            },
        ]);

        $service = new OpenRouterService(new ModelRouter);

        $result = $service->chat('function_calling', [
            ['role' => 'user', 'content' => 'Bonjour'],
        ], 'default', [
            'tools' => [['type' => 'function', 'function' => ['name' => 'document_analyze']]],
            'executor' => fn (array $toolCall): array => ['result' => 'ok'],
        ]);

        $this->assertSame(1, $appel);
        $this->assertSame('Réponse finale', $result['content']);
    }

    /**
     * Q-SYNTHESE : quand le budget de tours d'outils est épuisé, le modèle a
     * enchaîné des appels sans jamais rédiger de réponse. Sans appel de
     * synthèse, l'utilisateur ne recevait qu'un message neutre — sans analyse
     * ni lien de téléchargement. On vérifie qu'un appel SANS outils est fait
     * pour obtenir la réponse finale.
     */
    public function test_une_synthese_finale_est_demandee_si_le_budget_de_tours_est_epuise(): void
    {
        Config::set('openrouter.max_retries', 0);

        $appels = [];
        Http::fake([
            'openrouter.test/*' => function (Request $request) use (&$appels) {
                $data = $request->data();
                $appels[] = [
                    'has_tools' => ! empty($data['tools']),
                    'tool_choice' => $data['tool_choice'] ?? null,
                ];

                $dernier = end($appels);
                $estSynthese = ! $dernier['has_tools'];

                if (! $estSynthese) {
                    return Http::response($this->fakeResponse([
                        'choices' => [[
                            'index' => 0,
                            'message' => [
                                'role' => 'assistant',
                                'content' => null,
                                'tool_calls' => [[
                                    'id' => 'call_'.count($appels),
                                    'type' => 'function',
                                    'function' => ['name' => 'document_analyze', 'arguments' => '{}'],
                                ]],
                            ],
                            'finish_reason' => 'tool_calls',
                        ]],
                    ]), 200);
                }

                return Http::response($this->fakeResponse([
                    'choices' => [[
                        'index' => 0,
                        'message' => [
                            'role' => 'assistant',
                            'content' => "Voici l'analyse complète.\n- chat/generated/2026/09/19/document.docx",
                        ],
                        'finish_reason' => 'stop',
                    ]],
                ]), 200);
            },
        ]);

        $service = new OpenRouterService(new ModelRouter);

        $result = $service->chat('function_calling', [
            ['role' => 'user', 'content' => 'Analyse'],
        ], 'default', [
            'tools' => [['type' => 'function', 'function' => ['name' => 'document_analyze']]],
            'tool_choice' => 'required',
            'tool_loop_max_turns' => 3,
            'executor' => fn (array $toolCall): array => ['result' => 'ok'],
        ]);

        // Le dernier appel est une synthèse : aucun outil envoyé
        $dernier = end($appels);
        $this->assertFalse($dernier['has_tools'], 'La synthèse doit être demandée sans outils');
        $this->assertStringContainsString("Voici l'analyse complète", (string) $result['content']);
        $this->assertStringContainsString('chat/generated/', (string) $result['content']);
        $this->assertSame(3, $result['tool_turns']);
    }
}
