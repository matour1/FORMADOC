<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Chat;

use App\Services\Chat\CapabilitiesService;
use App\Services\Chat\ChatToolsService;
use App\Services\Chat\DocumentEditService;
use App\Services\DocumentGeneration\CoverGenerationService;
use App\Services\DocumentGeneration\CoverPageRenderer;
use App\Services\OpenRouter\OpenRouterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Tests de l'inventaire des capacités de FORMADOC injecté dans le prompt
 * système du chat.
 *
 * L'exigence : « l'IA doit tout connaître sur l'app : ce qu'elle peut faire
 * ou ne pas faire, et se mettre à jour après chaque amélioration. »
 * Le service génère l'inventaire à partir du code réel (outils, opérations,
 * conversions, skills, limitations) → chaque amélioration met à jour
 * automatiquement ce que l'IA sait faire.
 */
class CapabilitiesServiceTest extends TestCase
{
    use RefreshDatabase;

    private CapabilitiesService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $openRouter = Mockery::mock(OpenRouterService::class);
        $coverGeneration = Mockery::mock(CoverGenerationService::class);
        $coverPageRenderer = Mockery::mock(CoverPageRenderer::class);

        $tools = new ChatToolsService(
            $openRouter,
            $coverGeneration,
            $coverPageRenderer,
            new DocumentEditService,
        );

        $this->service = new CapabilitiesService($tools, new DocumentEditService);
    }

    public function test_tools_liste_tous_les_outils(): void
    {
        $tools = $this->service->tools();

        $names = array_column($tools, 'name');
        $this->assertContains('cover_page_generate', $names);
        $this->assertContains('document_reconstruct', $names);
        $this->assertContains('document_analyze', $names);
        $this->assertContains('document_to_docx', $names);
        $this->assertContains('document_edit', $names);
        $this->assertContains('document_to_pdf', $names);
        $this->assertContains('document_create', $names);

        // Tools d'édition structurelle (R6) — ils rejoignent la liste des
        // capacités puisqu'ils sont réellement exposés au modèle.
        $this->assertContains('rewrite_paragraph', $names);
        $this->assertContains('delete_block', $names);

        $this->assertCount(17, $tools);

        foreach ($tools as $tool) {
            $this->assertNotEmpty($tool['description']);
        }
    }

    public function test_document_operations_reflète_le_service(): void
    {
        $ops = $this->service->documentOperations();

        $names = array_column($ops, 'operation');
        $this->assertContains('replace_text', $names);
        $this->assertContains('edit_title', $names);
        $this->assertContains('append_text', $names);
        $this->assertContains('change_title_level', $names);
        $this->assertContains('change_title_color', $names);
        $this->assertContains('change_font', $names);
        $this->assertContains('format_complete', $names);
        $this->assertContains('to_pdf', $names);
        $this->assertContains('pdf_to_docx', $names);
    }

    public function test_conversions_disponibles(): void
    {
        $convs = $this->service->conversions();

        $this->assertCount(2, $convs);

        $pairs = array_map(
            fn (array $c): string => $c['from'].'->'.$c['to'],
            $convs
        );
        $this->assertContains('docx->pdf', $pairs);
        $this->assertContains('pdf->docx', $pairs);

        foreach ($convs as $conv) {
            $this->assertNotEmpty($conv['description']);
        }
    }

    public function test_limitations_incluent_des_options_manuelles(): void
    {
        $limits = $this->service->limitations();

        $this->assertGreaterThanOrEqual(4, count($limits));

        foreach ($limits as $limit) {
            $this->assertArrayHasKey('limitation', $limit);
            $this->assertArrayHasKey('manual_option', $limit);
            $this->assertNotEmpty($limit['limitation']);
            $this->assertNotEmpty($limit['manual_option']);
        }
    }

    public function test_claude_skills_liste_les_4_skills(): void
    {
        $skills = $this->service->claudeSkills();

        $this->assertCount(4, $skills);

        $names = array_column($skills, 'skill');
        $this->assertContains('docx', $names);
        $this->assertContains('xlsx', $names);
        $this->assertContains('pptx', $names);
        $this->assertContains('pdf', $names);
    }

    public function test_to_system_prompt_contient_tous_les_blocs(): void
    {
        $prompt = $this->service->toSystemPrompt();

        $this->assertStringContainsString('CAPACITÉS DE FORMADOC', $prompt);
        $this->assertStringContainsString('OUTILS DISPONIBLES', $prompt);
        $this->assertStringContainsString('document_analyze', $prompt);
        $this->assertStringContainsString('document_to_docx', $prompt);
        $this->assertStringContainsString('OPÉRATIONS D\'ÉDITION DE DOCUMENT', $prompt);
        $this->assertStringContainsString('change_title_level', $prompt);
        $this->assertStringContainsString('change_title_color', $prompt);
        $this->assertStringContainsString('change_font', $prompt);
        $this->assertStringContainsString('format_complete', $prompt);
        $this->assertStringContainsString('pdf_to_docx', $prompt);
        $this->assertStringContainsString('CONVERSIONS DE FORMAT', $prompt);
        $this->assertStringContainsString('docx → pdf', $prompt);
        $this->assertStringContainsString('pdf → docx', $prompt);
        $this->assertStringContainsString('SKILLS CLAUDE DOCUMENTAIRES', $prompt);
        $this->assertStringContainsString('LIMITATIONS ET OPTIONS MANUELLES', $prompt);
        $this->assertStringContainsString('Option manuelle :', $prompt);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
