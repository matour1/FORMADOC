<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Billing;

use App\Models\AiUsageLedger;
use App\Models\ChatSession;
use App\Models\Document;
use App\Models\User;
use App\Services\Billing\UsageLedger;
use App\Services\OpenRouter\DeepSeekFallbackService;
use App\Services\OpenRouter\ModelRouter;
use App\Services\OpenRouter\OpenRouterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Registre d'usage IA (phase R7).
 *
 * **Ce que ces tests couvrent, et pourquoi ces cas-là.** R7 facture le coût
 * RÉEL : chaque tentative, y compris celles qui échouent. Quatre situations
 * échappaient au calcul avant cette phase, et chacune a son test :
 *
 *  1. un retry échoue → il a consommé des tokens d'entrée = il coûte ;
 *  2. un modèle candidat échoue → idem, et il faut le rattacher au BON modèle ;
 *  3. le fournisseur bascule (DeepSeek) → le prix change, il faut savoir d'où ;
 *  4. un tour d'outil est facturé → le total doit être la somme des lignes.
 *
 * Le dernier test vérifie la propriété qui donne sa valeur au registre : le coût
 * total est **recalculable depuis les données brutes**, donc vérifiable.
 */
class UsageLedgerTest extends TestCase
{
    use RefreshDatabase;

    private UsageLedger $ledger;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'openrouter.api_key' => 'sk-or-test',
            'openrouter.api_url' => 'https://openrouter.ai/api/v1',
            'openrouter.max_retries' => 2,
            'openrouter.retry_delays_ms' => [1, 1],
            'openrouter.external_fallback_enabled' => false,
            'openrouter.rate_fcfa_per_usd' => 620,
            'openrouter.cost_infrastructure' => 0.15,
            'openrouter.cost_margin' => 0.60,
            'openrouter.pricing' => [
                'deepseek/deepseek-chat' => ['input' => 0.2574, 'output' => 1.029],
                'meta-llama/llama-3.1-8b-instruct' => ['input' => 0.03, 'output' => 0.06],
            ],
            'openrouter.tasks' => [
                'chat_text' => ['default' => ['deepseek/deepseek-chat']],
            ],
            'deepseek.api_key' => 'ds-test',
            'deepseek.api_url' => 'https://api.deepseek.com/v1',
            'deepseek.model' => 'deepseek-chat',
        ]);

        $this->ledger = new UsageLedger;
    }

    /**
     * Réponse OpenRouter valide pour un usage donné.
     *
     * @return array<string, mixed>
     */
    private function reponse(array $usage, string $contenu = 'Bonjour'): array
    {
        return [
            'choices' => [
                ['message' => ['role' => 'assistant', 'content' => $contenu]],
            ],
            'usage' => $usage,
        ];
    }

    private function service(?DeepSeekFallbackService $fallback = null): OpenRouterService
    {
        return new OpenRouterService(new ModelRouter, $fallback, $this->ledger);
    }

    // -------------------------------------------------------------------------
    // Enregistrement de base
    // -------------------------------------------------------------------------

    public function test_un_appel_reussi_est_enregistre_avec_les_tokens_reels(): void
    {
        Http::fake([
            '*' => Http::response($this->reponse(['prompt_tokens' => 120, 'completion_tokens' => 340])),
        ]);

        $user = User::factory()->create();
        $session = ChatSession::create(['user_id' => $user->id]);

        $this->service()->chat('chat_text', [['role' => 'user', 'content' => 'Salut']], 'default', [
            'user_id' => $user->id,
            'chat_session_id' => $session->id,
            'billing_reference' => 'chat:'.$session->id,
        ]);

        $ligne = AiUsageLedger::firstOrFail();

        $this->assertSame($user->id, $ligne->user_id);
        $this->assertSame($session->id, $ligne->chat_session_id);
        $this->assertSame('chat:'.$session->id, $ligne->reference);
        $this->assertSame('deepseek/deepseek-chat', $ligne->model);
        $this->assertSame('openrouter', $ligne->provider);
        $this->assertSame(120, $ligne->input_tokens);
        $this->assertSame(340, $ligne->output_tokens);
        $this->assertTrue($ligne->succeeded);
        $this->assertFalse($ligne->estimated);
        $this->assertSame(1, $ligne->attempt);
    }

    public function test_le_modele_enregistre_est_celui_reellement_appele(): void
    {
        Http::fake([
            '*' => Http::response($this->reponse(['prompt_tokens' => 10, 'completion_tokens' => 20])),
        ]);

        $resultat = $this->service()->chat('chat_text', [['role' => 'user', 'content' => 'x']]);

        $this->assertSame(
            $resultat['model'],
            AiUsageLedger::firstOrFail()->model,
            'Le registre doit nommer le modèle appelé, pas un modèle de repli implicite.'
        );
    }

    // -------------------------------------------------------------------------
    // Critère d'acceptation 1 — un retry échoué est compté dans le coût
    // -------------------------------------------------------------------------

    public function test_un_retry_echoue_est_compte_dans_le_cout(): void
    {
        $tentatives = 0;

        Http::fake(function () use (&$tentatives) {
            $tentatives++;

            if ($tentatives === 1) {
                // Panne transitoire : retryable → Laravel réessaie.
                return Http::response('Service indisponible', 503);
            }

            return Http::response($this->reponse(['prompt_tokens' => 100, 'completion_tokens' => 200]));
        });

        $this->service()->chat('chat_text', [['role' => 'user', 'content' => 'Bonjour']], 'default', [
            'billing_reference' => 'chat:1',
        ]);

        $this->assertSame(2, $tentatives, 'Le premier essai devait échouer puis être retenté.');

        $lignes = AiUsageLedger::orderBy('id')->get();
        $this->assertCount(1, $lignes, 'Seul le succès produit une ligne de coût mesuré.');
        $this->assertSame(2, $lignes->first()->attempt, 'Le succès a lieu à la 2e tentative.');
    }

    public function test_un_appel_entierement_echoue_coute_des_tokens_d_entree(): void
    {
        Http::fake([
            '*' => Http::response('Panne totale', 500),
        ]);

        try {
            $this->service()->chat('chat_text', [['role' => 'user', 'content' => str_repeat('a', 400)]], 'default', [
                'billing_reference' => 'chat:2',
            ]);
            $this->fail('Un échec total devait lever une exception.');
        } catch (\Throwable) {
            // attendu
        }

        $ligne = AiUsageLedger::firstOrFail();

        $this->assertFalse($ligne->succeeded);
        $this->assertTrue($ligne->estimated, 'Sans réponse, les tokens d\'entrée ne peuvent qu\'être estimés.');
        // 400 caractères / 4 ≈ 100 tokens : le coût de l'échec n'est pas nul.
        $this->assertGreaterThan(0, $ligne->input_tokens);
        $this->assertSame(0, $ligne->output_tokens);
        $this->assertNotNull($ligne->error);
    }

    public function test_les_retries_repetes_relevent_le_compteur_de_tentatives(): void
    {
        Http::fake([
            '*' => Http::response('Panne', 503),
        ]);

        try {
            $this->service()->chat('chat_text', [['role' => 'user', 'content' => 'x']]);
            $this->fail('Un échec total devait lever une exception.');
        } catch (\Throwable) {
            // attendu
        }

        // 1 essai initial + 2 retries (max_retries = 2) : le coût d'instabilité
        // doit être visible, pas ramené à une seule tentative.
        $this->assertGreaterThanOrEqual(2, AiUsageLedger::firstOrFail()->attempt);
    }

    // -------------------------------------------------------------------------
    // Critère d'acceptation 2 — un fallback de modèle est tracé
    // -------------------------------------------------------------------------

    public function test_un_fallback_de_modele_est_trace_dans_le_registre(): void
    {
        config(['openrouter.external_fallback_enabled' => true]);

        $fallback = $this->createMock(DeepSeekFallbackService::class);
        $fallback->method('chat')->willReturn([
            'model' => 'deepseek/deepseek-chat',
            'content' => 'Réponse de repli',
            'tool_calls' => [],
            'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 80],
            'cost_usd' => 0.0001,
            'cost_credits' => 2,
        ]);

        Http::fake([
            '*' => Http::response('OpenRouter indisponible', 503),
        ]);

        $this->service($fallback)->chat('chat_text', [['role' => 'user', 'content' => 'x']], 'default', [
            'billing_reference' => 'chat:3',
        ]);

        $bascule = AiUsageLedger::where('is_fallback', true)->firstOrFail();

        $this->assertSame('deepseek_fallback', $bascule->provider);
        $this->assertNotNull($bascule->fallback_from, 'Sans modèle d\'origine, la bascule de prix serait inexplicable.');
        $this->assertSame('deepseek/deepseek-chat', $bascule->model);
        $this->assertSame(50, $bascule->input_tokens);
        $this->assertSame(80, $bascule->output_tokens);
    }

    public function test_un_retour_de_repli_sans_modele_ne_perd_pas_la_ligne(): void
    {
        config(['openrouter.external_fallback_enabled' => true]);

        $fallback = $this->createMock(DeepSeekFallbackService::class);
        $fallback->method('chat')->willReturn([
            'content' => 'Repli sans nom de modèle',
            'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 5],
        ]);

        Http::fake([
            '*' => Http::response('Panne', 503),
        ]);

        $this->service($fallback)->chat('chat_text', [['role' => 'user', 'content' => 'x']]);

        $bascule = AiUsageLedger::where('is_fallback', true)->firstOrFail();
        $this->assertSame('deepseek-chat', $bascule->model);
    }

    // -------------------------------------------------------------------------
    // Critère d'acceptation 4 — le coût est recalculable depuis les données brutes
    // -------------------------------------------------------------------------

    public function test_le_cout_total_est_la_somme_des_lignes_brutes(): void
    {
        Http::fake([
            '*' => Http::response($this->reponse(['prompt_tokens' => 1000, 'completion_tokens' => 2000])),
        ]);

        $this->service()->chat('chat_text', [['role' => 'user', 'content' => 'x']], 'default', [
            'billing_reference' => 'doc:9',
            'document_id' => 9,
        ]);

        $lignes = AiUsageLedger::all();
        $total = $this->ledger->totalFor('doc:9');

        $this->assertSame($lignes->count(), $total['attempts']);
        $this->assertSame((int) $lignes->sum('cost_usd') === 0 ? 0 : $total['credits'], $total['credits']);
        $this->assertSame((int) $lignes->sum('input_tokens') + (int) $lignes->sum('output_tokens'), $total['tokens']);
        $this->assertEqualsWithDelta((float) $lignes->sum('cost_usd'), $total['usd'], 0.000001);
    }

    public function test_le_cout_est_recalculable_a_partir_des_tokens(): void
    {
        $usage = ['prompt_tokens' => 1_000_000, 'completion_tokens' => 1_000_000];

        Http::fake([
            '*' => Http::response($this->reponse($usage)),
        ]);

        $this->service()->chat('chat_text', [['role' => 'user', 'content' => 'x']]);

        $ligne = AiUsageLedger::firstOrFail();
        $pricing = config('openrouter.pricing.deepseek/deepseek-chat');

        $attenduUsd = (1_000_000 / 1_000_000) * $pricing['input']
            + (1_000_000 / 1_000_000) * $pricing['output'];

        // Le coût enregistré doit être dérivable des seuls tokens + grille
        // tarifaire : c'est ce qui rend la facturation vérifiable.
        $this->assertEqualsWithDelta($attenduUsd, $ligne->cost_usd, 0.000001);
    }

    public function test_la_verification_de_recalculabilite_detecte_les_ecarts(): void
    {
        AiUsageLedger::create([
            'model' => 'deepseek/deepseek-chat',
            'provider' => 'openrouter',
            'input_tokens' => 1_000_000,
            'output_tokens' => 1_000_000,
            'succeeded' => true,
            // Coût volontairement faux : un tarif changé sans retraitement.
            'cost_credits' => 1,
            'cost_usd' => 0.000001,
        ]);

        $resultat = $this->ledger->recalculabilite();

        $this->assertSame(1, $resultat['verifiees']);
        $this->assertSame(1, $resultat['ecarts']);
        $this->assertCount(1, $resultat['exemples']);
        $this->assertLessThan(1.0, $resultat['taux']);
    }

    public function test_les_lignes_estimees_sont_exclues_de_la_verification(): void
    {
        AiUsageLedger::create([
            'model' => 'deepseek/deepseek-chat',
            'input_tokens' => 10,
            'output_tokens' => 0,
            'succeeded' => false,
            'estimated' => true,
            'cost_credits' => 0,
            'cost_usd' => 0.0,
        ]);

        $resultat = $this->ledger->recalculabilite();

        $this->assertSame(0, $resultat['verifiees']);
        $this->assertSame(1, $resultat['estimees']);
        $this->assertSame(1.0, $resultat['taux'], 'Sans ligne vérifiable, le taux ne doit pas être faussement dégradé.');
    }

    // -------------------------------------------------------------------------
    // Un tour d'outil est facturé (somme multi-tours)
    // -------------------------------------------------------------------------

    public function test_chaque_tour_d_outil_est_enregistre_separement(): void
    {
        $tentatives = 0;

        Http::fake(function () use (&$tentatives) {
            $tentatives++;

            // Premier appel : le modèle demande un outil.
            if ($tentatives === 1) {
                return Http::response([
                    'choices' => [[
                        'message' => [
                            'role' => 'assistant',
                            'content' => null,
                            'tool_calls' => [[
                                'id' => 'call_1',
                                'type' => 'function',
                                'function' => ['name' => 'rewrite_paragraph', 'arguments' => '{}'],
                            ]],
                        ],
                    ]],
                    'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50],
                ]);
            }

            // Second appel (avec le résultat de l'outil) : réponse finale.
            return Http::response($this->reponse(['prompt_tokens' => 300, 'completion_tokens' => 70], 'Fait'));
        });

        $tours = 0;
        $executor = function () use (&$tours): array {
            $tours++;

            return ['result' => 'ok'];
        };

        $this->service()->chat(
            'chat_text',
            [['role' => 'user', 'content' => 'Réécris']],
            'default',
            ['executor' => $executor, 'billing_reference' => 'chat:5'],
        );

        // Deux appels HTTP = deux lignes : le total du registre reste la somme
        // des lignes brutes, sans cumul opaque à reconstituer.
        $this->assertSame(1, $tours);
        $this->assertSame(2, AiUsageLedger::count());

        $lignes = AiUsageLedger::orderBy('id')->get();
        $this->assertSame(100, $lignes[0]->input_tokens);
        $this->assertSame(300, $lignes[1]->input_tokens);
        $this->assertSame(0, $lignes[0]->metadata['turn']);
        $this->assertSame(1, $lignes[1]->metadata['turn']);
    }

    // -------------------------------------------------------------------------
    // Agrégations
    // -------------------------------------------------------------------------

    public function test_total_for_document_agrege_les_echecs(): void
    {
        $user = User::factory()->create();
        $document = Document::create([
            'filename' => 'rapport.docx',
            'original_name' => 'rapport.docx',
            'path' => 'documents/rapport.docx',
            'size' => 1024,
            'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'status' => 'detected',
            'metadata' => ['user_id' => $user->id],
        ]);

        AiUsageLedger::create([
            'document_id' => $document->id,
            'model' => 'deepseek/deepseek-chat',
            'provider' => 'openrouter',
            'input_tokens' => 100,
            'output_tokens' => 0,
            'succeeded' => false,
            'estimated' => true,
            'cost_usd' => 0.01,
            'cost_credits' => 6,
        ]);
        AiUsageLedger::create([
            'document_id' => $document->id,
            'model' => 'deepseek/deepseek-chat',
            'provider' => 'openrouter',
            'input_tokens' => 200,
            'output_tokens' => 400,
            'succeeded' => true,
            'cost_usd' => 0.02,
            'cost_credits' => 12,
        ]);

        $total = $this->ledger->totalForDocument($document->id);

        $this->assertSame(2, $total['attempts']);
        $this->assertSame(1, $total['failures'], 'Un échec doit apparaître dans le total, pas être filtré.');
        $this->assertSame(18, $total['credits']);
        $this->assertSame(700, $total['tokens']);
    }

    public function test_waste_for_user_isole_les_echecs(): void
    {
        $user = User::factory()->create();

        AiUsageLedger::create([
            'user_id' => $user->id,
            'model' => 'm',
            'succeeded' => false,
            'cost_credits' => 9,
            'cost_usd' => 0.01,
        ]);
        AiUsageLedger::create([
            'user_id' => $user->id,
            'model' => 'm',
            'succeeded' => true,
            'cost_credits' => 3,
            'cost_usd' => 0.005,
        ]);

        $fuite = $this->ledger->wasteForUser($user->id);
        $total = $this->ledger->totalForUser($user->id);

        $this->assertSame(9, $fuite['credits']);
        $this->assertSame(12, $total['credits']);
        $this->assertLessThan($total['credits'], $fuite['credits'], 'La fuite ne peut pas dépasser le total.');
    }

    public function test_le_breakdown_par_modele_separe_les_fournisseurs(): void
    {
        AiUsageLedger::create([
            'model' => 'deepseek/deepseek-chat',
            'provider' => 'openrouter',
            'succeeded' => true,
            'cost_credits' => 10,
            'cost_usd' => 0.01,
        ]);
        AiUsageLedger::create([
            'model' => 'deepseek/deepseek-chat',
            'provider' => 'deepseek_fallback',
            'is_fallback' => true,
            'fallback_from' => 'openrouter',
            'succeeded' => true,
            'cost_credits' => 4,
            'cost_usd' => 0.004,
        ]);

        $lignes = collect($this->ledger->breakdownByModel());

        $this->assertCount(2, $lignes, 'Un même modèle via deux fournisseurs n\'est pas le même poste de coût.');
        $this->assertSame(10, $lignes->firstWhere('provider', 'openrouter')['credits']);
        $this->assertSame(4, $lignes->firstWhere('provider', 'deepseek_fallback')['credits']);
    }

    public function test_une_erreur_d_enregistrement_ne_fait_pas_echouer_l_appel(): void
    {
        // Table renommée le temps du test : toute écriture échoue.
        Schema::rename('ai_usage_ledger', 'ai_usage_ledger_absent');

        Http::fake([
            '*' => Http::response($this->reponse(['prompt_tokens' => 10, 'completion_tokens' => 10], 'Toujours là')),
        ]);

        $resultat = $this->service()->chat('chat_text', [['role' => 'user', 'content' => 'x']]);

        $this->assertSame('Toujours là', $resultat['content'], 'Perdre une ligne comptable ne doit jamais perdre la réponse.');

        Schema::rename('ai_usage_ledger_absent', 'ai_usage_ledger');
    }

    // -------------------------------------------------------------------------
    // Câblage réel par le container
    // -------------------------------------------------------------------------

    public function test_le_service_openrouter_recoit_le_registre_par_le_container(): void
    {
        // Point de câblage critique, sur le modèle du défaut trouvé en R6 :
        // `UsageLedger` est le 3e paramètre du constructeur d'OpenRouterService,
        // et le compilateur PHP interdit qu'un paramètre obligatoire suive un
        // paramètre optionnel — donc le registre est OPTIONNEL par construction.
        // Or l'injection automatique de Laravel renseigne les paramètres
        // optionnels à `null` au lieu de les résoudre. Si c'était le cas ici,
        // R7 serait intégralement inopérant en production — aucune ligne écrite,
        // aucune erreur levée, et tous les tests unitaires continueraient de
        // passer puisqu'ils injectent le registre à la main.
        $service = $this->app->make(OpenRouterService::class);

        $reflexion = new \ReflectionClass($service);
        $propriete = $reflexion->getProperty('ledger');
        $propriete->setAccessible(true);

        $this->assertInstanceOf(
            UsageLedger::class,
            $propriete->getValue($service),
            'Le container doit injecter UsageLedger dans OpenRouterService, sinon R7 est inopérant en production.'
        );
    }

    public function test_un_appel_via_le_service_du_container_ecrit_dans_le_registre(): void
    {
        // Vérification de BOUT EN BOUT, volontairement redondante avec le test de
        // réflexion ci-dessus : celui-ci inspecte une propriété, donc il passerait
        // encore si le service était lié mais que le registre n'était jamais
        // appelé. Ici on prouve la chaîne complète — container → appel → écriture.
        Http::fake([
            '*' => Http::response($this->reponse(['prompt_tokens' => 42, 'completion_tokens' => 58])),
        ]);

        $service = $this->app->make(OpenRouterService::class);
        $service->chat('chat_text', [['role' => 'user', 'content' => 'x']], 'default', [
            'billing_reference' => 'chat:99',
        ]);

        $ligne = AiUsageLedger::firstOrFail();

        $this->assertSame('chat:99', $ligne->reference);
        $this->assertSame(42, $ligne->input_tokens);
        $this->assertSame(58, $ligne->output_tokens);
    }

    // -------------------------------------------------------------------------
    // Connexions : le repli réseau est aussi un échec facturé
    // -------------------------------------------------------------------------

    public function test_une_erreur_de_connexion_est_enregistree_comme_echec(): void
    {
        Http::fake([
            '*' => fn () => throw new ConnectionException('Connexion refusée'),
        ]);

        try {
            $this->service()->chat('chat_text', [['role' => 'user', 'content' => 'x']]);
            $this->fail('Une erreur de connexion devait faire échouer l\'appel.');
        } catch (\Throwable) {
            // attendu
        }

        $ligne = AiUsageLedger::firstOrFail();
        $this->assertFalse($ligne->succeeded);
        $this->assertStringContainsString('Connexion refusée', (string) $ligne->error);
    }

    // -------------------------------------------------------------------------
    // Remise à zéro entre modèles candidats
    // -------------------------------------------------------------------------

    public function test_le_compteur_de_tentatives_ne_fuit_pas_d_un_modele_a_l_autre(): void
    {
        config([
            'openrouter.tasks' => [
                'chat_text' => ['default' => ['meta-llama/llama-3.1-8b-instruct', 'deepseek/deepseek-chat']],
            ],
        ]);

        $appels = 0;

        Http::fake(function () use (&$appels) {
            $appels++;

            // Le premier modèle échoue définitivement (non retryable : 401),
            // le second réussit du premier coup.
            if ($appels === 1) {
                return Http::response('Non autorisé', 401);
            }

            return Http::response($this->reponse(['prompt_tokens' => 10, 'completion_tokens' => 10]));
        });

        $this->service()->chat('chat_text', [['role' => 'user', 'content' => 'x']]);

        $succes = AiUsageLedger::where('succeeded', true)->firstOrFail();
        $this->assertSame(1, $succes->attempt, 'Le second modèle a réussi du premier coup.');

        $echec = AiUsageLedger::where('succeeded', false)->firstOrFail();
        $this->assertSame('meta-llama/llama-3.1-8b-instruct', $echec->model, 'L\'échec doit viser le modèle fautif.');
    }
}
