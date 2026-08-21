<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Anthropic;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Anthropic\ClaudeSkillsService;
use App\Services\Billing\UsageCostCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Tests de l'intégration EXPÉRIMENTALE des Skills documentaires Claude
 * (exigence B — docx/xlsx/pptx/pdf, réservée Pro).
 *
 * Couverture :
 * - SKILLS : docx, xlsx, pptx, pdf
 * - isConfigured / isEligible (Pro uniquement)
 * - generate() : flux complet HTTP (messages → file_id → download → stockage)
 * - échecs : skill inconnu, clé absente, aucun fichier, erreur API
 * - estimateCredits() : tokens + conteneur (min 5 min), coefficient ×2, min 1
 */
class ClaudeSkillsServiceTest extends TestCase
{
    use RefreshDatabase;

    private ClaudeSkillsService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ClaudeSkillsService(new UsageCostCalculator());

        // Configuration de test explicite
        config([
            'anthropic.api_key' => 'test-anthropic-key',
            'anthropic.api_url' => 'https://api.anthropic.com/v1',
            'anthropic.model' => 'claude-sonnet-4-6',
            'anthropic.credit_multiplier' => 2.0,
            'anthropic.container_cost_per_minute' => 0.05 / 60,
            'anthropic.container_min_minutes' => 5,
            'openrouter.rate_fcfa_per_usd' => 620,
        ]);
    }

    /* ------------------------------------------------------------------
     |  Skills disponibles
     | ------------------------------------------------------------------ */

    public function test_skills_contient_docx_xlsx_pptx_pdf(): void
    {
        $this->assertSame(
            ['docx', 'xlsx', 'pptx', 'pdf'],
            ClaudeSkillsService::SKILLS,
        );
    }

    /* ------------------------------------------------------------------
     |  Configuration / éligibilité
     | ------------------------------------------------------------------ */

    public function test_is_configured_faux_sans_cle_api(): void
    {
        config(['anthropic.api_key' => '']);

        $this->assertFalse($this->service->isConfigured());
    }

    public function test_is_configured_vrai_avec_cle_api(): void
    {
        $this->assertTrue($this->service->isConfigured());
    }

    public function test_is_eligible_utilisateur_null_faux(): void
    {
        $this->assertFalse($this->service->isEligible(null));
    }

    public function test_is_eligible_plan_pro_vrai(): void
    {
        $user = $this->makeUserWithPlan('pro');

        $this->assertTrue($this->service->isEligible($user));
    }

    public function test_is_eligible_plan_standard_faux(): void
    {
        $user = $this->makeUserWithPlan('standard');

        $this->assertFalse($this->service->isEligible($user));
    }

    public function test_is_eligible_sans_abonnement_faux(): void
    {
        $user = User::factory()->create();

        $this->assertFalse($this->service->isEligible($user));
    }

    public function test_is_eligible_faux_sans_cle_api(): void
    {
        $user = $this->makeUserWithPlan('pro');
        config(['anthropic.api_key' => '']);

        $this->assertFalse($this->service->isEligible($user));
    }

    /* ------------------------------------------------------------------
     |  generate() — flux nominal
     | ------------------------------------------------------------------ */

    public function test_generate_genere_et_stocke_le_fichier(): void
    {
        Storage::fake('local');
        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response([
                'content' => [
                    [
                        'type' => 'bash_code_execution_tool_result',
                        'content' => [
                            ['type' => 'document', 'file_id' => 'file_01TEST'],
                        ],
                    ],
                ],
                'usage' => ['input_tokens' => 1000, 'output_tokens' => 500],
            ]),
            'https://api.anthropic.com/v1/files/file_01TEST/content' => Http::response('fake-docx-bytes'),
        ]);

        $user = $this->makeUserWithPlan('pro');

        $result = $this->service->generate($user, 'docx', 'Génère un rapport de stage', 'mon_rapport');

        $this->assertSame('mon_rapport.docx', $result['filename']);
        $this->assertStringStartsWith('claude-skills/', $result['path']);
        $this->assertStringEndsWith('/mon_rapport.docx', $result['path']);
        $this->assertSame('claude-sonnet-4-6', $result['model']);
        $this->assertGreaterThanOrEqual(1, $result['cost_credits']);

        Storage::disk('local')->assertExists($result['path']);
        $this->assertSame('fake-docx-bytes', Storage::disk('local')->get($result['path']));

        // Le POST a bien envoyé le container skills + le tool code execution
        Http::assertSent(function ($request) {
            if ($request->url() !== 'https://api.anthropic.com/v1/messages') {
                return false;
            }

            $body = $request->data();

            return isset($body['container']['skills'][0]['skill_id'])
                && $body['container']['skills'][0]['skill_id'] === 'docx'
                && isset($body['tools'][0]['name']);
        });
    }

    public function test_generate_avec_file_id_direct_sur_le_bloc(): void
    {
        Storage::fake('local');
        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response([
                'content' => [
                    [
                        'type' => 'bash_code_execution_tool_result',
                        'file_id' => 'file_01DIRECT',
                    ],
                ],
                'usage' => ['input_tokens' => 100, 'output_tokens' => 100],
            ]),
            'https://api.anthropic.com/v1/files/file_01DIRECT/content' => Http::response('xlsx-bytes'),
        ]);

        $user = $this->makeUserWithPlan('pro');

        $result = $this->service->generate($user, 'xlsx', 'Génère un tableau', 'tableau');

        $this->assertSame('tableau.xlsx', $result['filename']);
        Storage::disk('local')->assertExists($result['path']);
    }

    /* ------------------------------------------------------------------
     |  generate() — échecs
     | ------------------------------------------------------------------ */

    public function test_generate_skill_inconnu_leve_runtime_exception(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Skill documentaire inconnu');

        $this->service->generate(User::factory()->create(), 'exe', 'prompt');
    }

    public function test_generate_sans_cle_api_leve_runtime_exception(): void
    {
        config(['anthropic.api_key' => '']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Clé API Anthropic non configurée');

        $this->service->generate(User::factory()->create(), 'docx', 'prompt');
    }

    public function test_generate_erreur_api_leve_runtime_exception(): void
    {
        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response([], 500),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Échec de la génération Claude');

        $this->service->generate($this->makeUserWithPlan('pro'), 'docx', 'prompt');
    }

    public function test_generate_aucun_fichier_leve_runtime_exception(): void
    {
        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response([
                'content' => [
                    ['type' => 'text', 'text' => 'Désolé, je ne peux pas générer de fichier.'],
                ],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 10],
            ]),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Aucun fichier généré');

        $this->service->generate($this->makeUserWithPlan('pro'), 'docx', 'prompt');
    }

    public function test_generate_fichier_vide_leve_runtime_exception(): void
    {
        Http::fake([
            'https://api.anthropic.com/v1/messages' => Http::response([
                'content' => [
                    [
                        'type' => 'bash_code_execution_tool_result',
                        'content' => [
                            ['type' => 'document', 'file_id' => 'file_01EMPTY'],
                        ],
                    ],
                ],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 10],
            ]),
            'https://api.anthropic.com/v1/files/file_01EMPTY/content' => Http::response(''),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Fichier généré vide');

        $this->service->generate($this->makeUserWithPlan('pro'), 'docx', 'prompt');
    }

    /* ------------------------------------------------------------------
     |  estimateCredits()
     | ------------------------------------------------------------------ */

    public function test_estimate_credits_tokens_plus_conteneur_docx(): void
    {
        // 1000 tokens input + 500 output, conteneur 5 min (min), ×2, taux 620
        // modèle : 0.003 + 0.0075 = 0.0105 $ ; conteneur : 0.0041667 $
        // total 0.0146667 × 2 × 620 = 18.19 → 19 crédits
        $credits = $this->service->estimateCredits([
            'usage' => ['input_tokens' => 1000, 'output_tokens' => 500],
        ], 'docx');

        $this->assertSame(19, $credits);
    }

    public function test_estimate_credits_conteneur_pdf_plus_long(): void
    {
        // pdf → 8 min de conteneur : (0.05/60)×8 = 0.0066667 $
        // total 0.0171667 × 2 × 620 = 21.29 → 22 crédits
        $credits = $this->service->estimateCredits([
            'usage' => ['input_tokens' => 1000, 'output_tokens' => 500],
        ], 'pdf');

        $this->assertSame(22, $credits);
    }

    public function test_estimate_credits_jamais_inferieur_a_1(): void
    {
        config([
            'anthropic.container_cost_per_minute' => 0,
            'anthropic.credit_multiplier' => 1.0,
        ]);

        $credits = $this->service->estimateCredits([], 'docx');

        $this->assertSame(1, $credits);
    }

    public function test_estimate_credits_coefficient_rentabilite_applique(): void
    {
        // Sans marge (×1) : 0.0041667 × 620 = 2.58 → 3
        // Avec marge (×2)  : 0.0041667 × 2 × 620 = 5.17 → 6
        $base = $this->service->estimateCredits([], 'docx');

        config(['anthropic.credit_multiplier' => 1.0]);
        $sansMarge = $this->service->estimateCredits([], 'docx');

        $this->assertGreaterThan($sansMarge, $base);
    }

    /* ------------------------------------------------------------------
     |  Helpers
     | ------------------------------------------------------------------ */

    private function makeUserWithPlan(string $slug): User
    {
        $user = User::factory()->create();
        $plan = Plan::factory()->create(['slug' => $slug, 'is_active' => true]);
        Subscription::factory()->create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => 'active',
        ]);

        return $user;
    }
}
