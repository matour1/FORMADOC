<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Chat;

use App\Models\CoverPageTemplate;
use App\Models\User;
use App\Services\Chat\ChatToolsService;
use App\Services\DocumentGeneration\CoverGenerationService;
use App\Services\DocumentGeneration\CoverPageRenderer;
use App\Services\OpenRouter\OpenRouterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

/**
 * Tests des outils actionnables du chat IA (exigence A).
 *
 * - schemas() : 6 outils déclarés (4 internes + 2 externes)
 * - availableTools() : identifiants des outils
 * - execute() : outil inconnu → erreur, arguments JSON → décodés
 * - web.search : délègue à OpenRouter (web_search_options), citations ajoutées
 * - image.generate : image base64 → fichier stocké
 * - cover_page.generate : gabarit introuvable → erreur propre
 * - structure.correct : délègue à StructureCorrectionService
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
        );
    }

    public function test_available_tools_liste_les_6_outils(): void
    {
        $tools = $this->service->availableTools();

        $this->assertContains('cover_page.generate', $tools);
        $this->assertContains('document.reconstruct', $tools);
        $this->assertContains('table_of_contents', $tools);
        $this->assertContains('structure.correct', $tools);
        $this->assertContains('web.search', $tools);
        $this->assertContains('image.generate', $tools);
        $this->assertCount(6, $tools);
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
                'name' => 'web.search',
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
            ['name' => 'web.search', 'arguments' => '{"query": ""}'],
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
                'name' => 'image.generate',
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
                'name' => 'cover_page.generate',
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
            ->withArgs(function (\PhpOffice\PhpWord\PhpWord $phpWord, $tpl, array $values) {
                return $tpl instanceof CoverPageTemplate
                    && ($values['titre'] ?? null) === 'Mon rapport';
            });

        $result = $this->service->execute(
            [
                'name' => 'cover_page.generate',
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
                'name' => 'structure.correct',
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

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
