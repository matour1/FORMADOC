<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Chat;

use App\Document\Editing\ToolWhitelist;
use App\Models\CoverPageTemplate;
use App\Models\User;
use App\Services\Anthropic\ClaudeSkillsService;
use App\Services\Chat\ChatToolsService;
use App\Services\Chat\DocumentEditService;
use App\Services\DocumentGeneration\CoverGenerationService;
use App\Services\DocumentGeneration\CoverPageRenderer;
use App\Services\OpenRouter\OpenRouterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use Tests\TestCase;

/**
 * Tests des outils actionnables du chat IA (exigence A).
 *
 * - schemas() : 9 outils déclarés (7 internes + 2 externes)
 * - availableTools() : identifiants des outils
 * - execute() : outil inconnu → erreur, arguments JSON → décodés
 * - web_search : délègue à OpenRouter (web_search_options), citations ajoutées
 * - image_generate : image base64 → fichier stocké
 * - cover_page_generate : gabarit introuvable → erreur propre
 * - structure_correct : délègue à StructureCorrectionService
 * - document_edit / document_to_pdf / document_create : édition PJ et
 *   génération Word/PDF
 */
class ChatToolsServiceTest extends TestCase
{
    use RefreshDatabase;

    private ChatToolsService $service;

    private OpenRouterService $openRouter;

    private CoverPageRenderer $coverPageRenderer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->openRouter = Mockery::mock(OpenRouterService::class);
        $coverGeneration = Mockery::mock(CoverGenerationService::class);
        $this->coverPageRenderer = Mockery::mock(CoverPageRenderer::class);

        $this->service = new ChatToolsService(
            $this->openRouter,
            $coverGeneration,
            $this->coverPageRenderer,
            new DocumentEditService,
        );
    }

    public function test_available_tools_liste_les_11_outils(): void
    {
        $tools = $this->service->availableTools();

        $this->assertContains('cover_page_generate', $tools);
        $this->assertContains('document_reconstruct', $tools);
        $this->assertContains('document_analyze', $tools);
        $this->assertContains('document_to_docx', $tools);
        $this->assertContains('table_of_contents', $tools);
        $this->assertContains('structure_correct', $tools);
        $this->assertContains('web_search', $tools);
        $this->assertContains('image_generate', $tools);
        $this->assertContains('document_edit', $tools);
        $this->assertContains('document_to_pdf', $tools);
        $this->assertContains('document_create', $tools);

        // Tools d'édition structurelle (R6) : ils éditent un document ANALYSÉ
        // et persisté, contrairement à document_edit qui travaille sur une
        // pièce jointe. Ils proviennent de `ToolWhitelist`.
        $this->assertContains('rewrite_paragraph', $tools);
        $this->assertContains('insert_block', $tools);
        $this->assertContains('modify_table', $tools);
        $this->assertContains('delete_block', $tools);
        $this->assertContains('regenerate_section', $tools);
        $this->assertContains('undo_last_action', $tools);

        $this->assertCount(17, $tools);
    }

    public function test_les_tools_d_edition_correspondent_a_la_liste_blanche(): void
    {
        // Cohérence structurelle : aucun tool d'édition ne peut être exposé au
        // modèle sans figurer dans la liste blanche, et inversement. C'est ce
        // qui rend impossible l'ajout d'un outil non autorisé par inadvertance.
        $exposes = $this->service->availableTools();

        foreach (ToolWhitelist::editingToolNames() as $nom) {
            $this->assertContains($nom, $exposes, "Le tool « {$nom} » n'est pas exposé au modèle.");
        }

        $this->assertContains('undo_last_action', $exposes);
    }

    public function test_schemas_sont_des_functions_openai(): void
    {
        $schemas = $this->service->schemas();

        foreach ($schemas as $schema) {
            $this->assertSame('function', $schema['type']);
            $this->assertArrayHasKey('name', $schema['function']);
            $this->assertArrayHasKey('description', $schema['function']);
            $this->assertArrayHasKey('parameters', $schema['function']);
        }
    }

    public function test_execute_outil_inconnu_retourne_erreur(): void
    {
        $result = $this->service->execute(
            ['name' => 'outil.inconnu', 'arguments' => '{}'],
            null,
            'default',
        );

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('Outil inconnu', $result['error']);
    }

    public function test_execute_arguments_json_invalides_ne_crashe_pas(): void
    {
        // arguments invalides → décodés en [] → outil inconnu
        $result = $this->service->execute(
            ['name' => 'unknown.tool', 'arguments' => 'not-json{{{'],
            null,
            'default',
        );

        $this->assertArrayHasKey('error', $result);
    }

    public function test_web_search_delegue_a_openrouter_avec_citations(): void
    {
        $this->openRouter->shouldReceive('chat')
            ->once()
            ->withArgs(function (string $task, array $messages, string $plan, array $options) {
                return $task === 'web_search'
                    && $messages[0]['content'] === 'Quel est le prix du riz au Cameroun ?'
                    && $plan === 'standard'
                    && isset($options['web_search_options']);
            })
            ->andReturn([
                'content' => 'Le riz coûte environ 3 500 FCFA le sac.',
                'raw' => [
                    'citations' => [
                        ['title' => 'Source 1', 'url' => 'https://example.com/1'],
                    ],
                ],
            ]);

        $result = $this->service->execute(
            [
                'name' => 'web_search',
                'arguments' => json_encode(['query' => 'Quel est le prix du riz au Cameroun ?']),
            ],
            null,
            'standard',
        );

        $this->assertArrayHasKey('result', $result);
        $this->assertStringContainsString('3 500 FCFA', $result['result']);
        $this->assertStringContainsString('Source 1', $result['result']);
        $this->assertStringContainsString('https://example.com/1', $result['result']);
    }

    public function test_web_search_requete_vide_retourne_erreur(): void
    {
        $result = $this->service->execute(
            ['name' => 'web_search', 'arguments' => '{"query": ""}'],
            null,
            'default',
        );

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('vide', $result['error']);
    }

    public function test_image_generate_stocke_le_fichier(): void
    {
        Storage::fake('local');

        $fakePng = base64_encode('fake-png-binary-data');

        $this->openRouter->shouldReceive('chat')
            ->once()
            ->withArgs(function (string $task, array $messages, string $plan, array $options) {
                return $task === 'image_generation'
                    && isset($options['response_format'])
                    && $options['response_format']['type'] === 'image';
            })
            ->andReturn([
                'content' => 'data:image/png;base64,'.$fakePng,
            ]);

        $result = $this->service->execute(
            [
                'name' => 'image_generate',
                'arguments' => json_encode(['prompt' => 'Un schéma de réseau', 'output_filename' => 'schema']),
            ],
            null,
            'premium',
        );

        $this->assertArrayHasKey('result', $result);
        $this->assertStringContainsString('Image générée', $result['result']);
        $this->assertStringContainsString('.png', $result['result']);
    }

    public function test_cover_page_generate_gabarit_introuvable_erreur(): void
    {
        $result = $this->service->execute(
            [
                'name' => 'cover_page_generate',
                'arguments' => json_encode(['template_id' => 999999, 'values' => []]),
            ],
            null,
            'default',
        );

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('introuvable', $result['error']);
    }

    public function test_cover_page_generate_genere_le_docx(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $template = CoverPageTemplate::create([
            'name' => 'Gabarit test',
            'description' => 'Test',
            'elements' => [
                ['type' => 'row', 'cells' => [
                    ['gridSpan' => 1, 'blocks' => [
                        ['kind' => 'text', 'text' => '{{titre}}', 'align' => 'center', 'size' => 20],
                    ]],
                ]],
            ],
            'page_style' => ['format' => 'A4', 'orientation' => 'portrait', 'margins' => ['top' => 25, 'bottom' => 25, 'left' => 25, 'right' => 25]],
            'is_public' => true,
            'user_id' => $user->id,
        ]);

        // Le rendu de la page de garde est délégué au renderer (mocké)
        $this->coverPageRenderer->shouldReceive('render')
            ->once()
            ->withArgs(function (PhpWord $phpWord, $tpl, array $values) {
                return $tpl instanceof CoverPageTemplate
                    && ($values['titre'] ?? null) === 'Mon rapport';
            });

        $result = $this->service->execute(
            [
                'name' => 'cover_page_generate',
                'arguments' => json_encode([
                    'template_id' => $template->id,
                    'values' => ['titre' => 'Mon rapport'],
                    'output_filename' => 'page_garde_test',
                ]),
            ],
            $user,
            'default',
        );

        $this->assertArrayHasKey('result', $result);
        $this->assertStringContainsString('Page de garde générée', $result['result']);
        $this->assertStringContainsString('.docx', $result['result']);
    }

    public function test_structure_correct_applique_les_corrections(): void
    {
        $result = $this->service->execute(
            [
                'name' => 'structure_correct',
                'arguments' => json_encode([
                    'structure' => [
                        'titres' => [
                            ['id' => 't1', 'text' => 'Introduction', 'level' => 1],
                        ],
                    ],
                    'corrections' => ['t1' => 2],
                ]),
            ],
            null,
            'default',
        );

        $this->assertArrayHasKey('result', $result);
        $this->assertStringContainsString('Structure corrigée', $result['result']);
    }

    public function test_table_of_contents_genere_le_docx(): void
    {
        Storage::fake('local');

        $result = $this->service->execute(
            [
                'name' => 'table_of_contents',
                'arguments' => json_encode([
                    'title' => 'SOMMAIRE',
                    'output_filename' => 'sommaire_test',
                ]),
            ],
            null,
            'default',
        );

        $this->assertArrayHasKey('result', $result);
        $this->assertStringContainsString('Sommaire généré', $result['result']);
        $this->assertStringContainsString('.docx', $result['result']);

        $relative = str_replace('chat/generated/', '', explode(' : ', $result['result'])[1]);
        Storage::disk('local')->assertExists('chat/generated/'.$relative);
    }

    public function test_document_edit_chemin_invalide_refuse(): void
    {
        $result = $this->service->execute(
            [
                'name' => 'document_edit',
                'arguments' => json_encode([
                    'source_path' => 'config/app.php', // hors PJ → refusé
                    'operation' => 'replace_text',
                    'search' => 'x',
                    'replacement' => 'y',
                ]),
            ],
            null,
            'default',
        );

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('invalide', $result['error']);
    }

    public function test_document_edit_operation_inconnue_erreur(): void
    {
        Storage::fake('local');

        // Crée un vrai DOCX de pièce jointe
        $phpWord = new PhpWord;
        $phpWord->addTitleStyle(1, ['bold' => true, 'size' => 16]);
        $section = $phpWord->addSection();
        $section->addTitle('Intro', 1);
        $section->addText('Contenu');
        Storage::disk('local')->makeDirectory('chat/attachments/1');
        $writer = IOFactory::createWriter($phpWord, 'Word2007');
        $writer->save(Storage::disk('local')->path('chat/attachments/1/test.docx'));

        $result = $this->service->execute(
            [
                'name' => 'document_edit',
                'arguments' => json_encode([
                    'source_path' => 'chat/attachments/1/test.docx',
                    'operation' => 'delete_all',
                ]),
            ],
            null,
            'default',
        );

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('inconnue', $result['error']);
    }

    public function test_document_create_genere_un_docx(): void
    {
        Storage::fake('local');

        $result = $this->service->execute(
            [
                'name' => 'document_create',
                'arguments' => json_encode([
                    'content' => "# Titre\n\nUn paragraphe.\n\n- item 1\n- item 2",
                    'format' => 'word',
                    'output_filename' => 'notes_test',
                ]),
            ],
            null,
            'default',
        );

        $this->assertArrayHasKey('result', $result);
        $this->assertStringContainsString('DOCX généré', $result['result']);
        $this->assertStringContainsString('.docx', $result['result']);

        $relative = str_replace('chat/generated/', '', explode(' : ', $result['result'])[1]);
        Storage::disk('local')->assertExists('chat/generated/'.$relative);
    }

    public function test_document_create_contenu_vide_erreur(): void
    {
        $result = $this->service->execute(
            [
                'name' => 'document_create',
                'arguments' => json_encode(['content' => '   ', 'format' => 'word']),
            ],
            null,
            'default',
        );

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('vide', $result['error']);
    }

    public function test_document_create_format_inconnu_erreur(): void
    {
        $result = $this->service->execute(
            [
                'name' => 'document_create',
                'arguments' => json_encode(['content' => 'Texte', 'format' => 'xls']),
            ],
            null,
            'default',
        );

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('Format inconnu', $result['error']);
    }

    public function test_document_analyze_analyse_une_piece_jointe(): void
    {
        Storage::fake('local');

        // Crée un vrai DOCX de pièce jointe
        $phpWord = new PhpWord;
        $phpWord->addTitleStyle(1, ['bold' => true, 'size' => 16]);
        $phpWord->addTitleStyle(2, ['bold' => true, 'size' => 14]);
        $section = $phpWord->addSection();
        $section->addTitle('Introduction', 1);
        $section->addText('Contenu du rapport.');
        $section->addTitle('Conclusion', 1);

        Storage::disk('local')->makeDirectory('chat/attachments/1');
        $writer = IOFactory::createWriter($phpWord, 'Word2007');
        $writer->save(Storage::disk('local')->path('chat/attachments/1/analyse.docx'));

        $result = $this->service->execute(
            [
                'name' => 'document_analyze',
                'arguments' => json_encode([
                    'source_path' => 'chat/attachments/1/analyse.docx',
                    'method' => 'regex',
                ]),
            ],
            null,
            'default',
        );

        $this->assertArrayHasKey('result', $result);
        $this->assertStringContainsString('Structure détectée', $result['result']);
        $this->assertStringContainsString('titres', strtolower($result['result']));
    }

    public function test_document_analyze_source_path_manquant_erreur(): void
    {
        $result = $this->service->execute(
            [
                'name' => 'document_analyze',
                'arguments' => json_encode(['method' => 'regex']),
            ],
            null,
            'default',
        );

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('source_path', $result['error']);
    }

    public function test_document_analyze_chemin_invalide_erreur(): void
    {
        $result = $this->service->execute(
            [
                'name' => 'document_analyze',
                'arguments' => json_encode([
                    'source_path' => 'config/app.php',
                    'method' => 'regex',
                ]),
            ],
            null,
            'default',
        );

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('invalide', $result['error']);
    }

    public function test_fallback_claude_declenche_sur_echec_outil_interne(): void
    {
        // Mock du ClaudeSkillsService éligible → le fallback doit être utilisé
        $claudeSkills = Mockery::mock(ClaudeSkillsService::class);
        $claudeSkills->shouldReceive('isEligible')->andReturn(true);
        $claudeSkills->shouldReceive('generate')
            ->once()
            ->andReturn([
                'path' => 'claude-skills/document_modifie.docx',
                'filename' => 'document_modifie.docx',
                'cost_credits' => 10,
                'model' => 'claude-sonnet',
            ]);

        $user = User::factory()->create();

        $openRouter = Mockery::mock(OpenRouterService::class);
        $coverGeneration = Mockery::mock(CoverGenerationService::class);
        $coverPageRenderer = Mockery::mock(CoverPageRenderer::class);

        $service = new ChatToolsService(
            $openRouter,
            $coverGeneration,
            $coverPageRenderer,
            new DocumentEditService,
            $claudeSkills,
        );

        $result = $service->execute(
            [
                'name' => 'document_edit',
                'arguments' => json_encode([
                    'source_path' => 'chat/attachments/1/introuvable.docx', // échec interne
                    'operation' => 'replace_text',
                    'search' => 'a',
                    'replacement' => 'b',
                ]),
            ],
            $user,
            'standard',
        );

        $this->assertArrayHasKey('result', $result);
        $this->assertStringContainsString('Claude Skills', $result['result']);
        $this->assertStringContainsString('claude-skills/', $result['result']);
    }

    public function test_fallback_claude_non_eligible_retourne_options_manuelles(): void
    {
        // Mock non éligible → le fallback ne doit PAS être utilisé
        $claudeSkills = Mockery::mock(ClaudeSkillsService::class);
        $claudeSkills->shouldReceive('isEligible')->andReturn(false);

        $user = User::factory()->create();

        $openRouter = Mockery::mock(OpenRouterService::class);
        $coverGeneration = Mockery::mock(CoverGenerationService::class);
        $coverPageRenderer = Mockery::mock(CoverPageRenderer::class);

        $service = new ChatToolsService(
            $openRouter,
            $coverGeneration,
            $coverPageRenderer,
            new DocumentEditService,
            $claudeSkills,
        );

        $result = $service->execute(
            [
                'name' => 'document_edit',
                'arguments' => json_encode([
                    'source_path' => 'chat/attachments/1/introuvable.docx',
                    'operation' => 'replace_text',
                    'search' => 'a',
                    'replacement' => 'b',
                ]),
            ],
            $user,
            'standard',
        );

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('introuvable', $result['error']);
        $this->assertStringNotContainsString('Claude Skills', $result['error']);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
