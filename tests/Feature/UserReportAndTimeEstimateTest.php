<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Document\Structure\Block;
use App\Document\Structure\BlockCategory;
use App\Document\Structure\BlockType;
use App\Document\Structure\CrossRef;
use App\Document\Structure\StructuralDocument;
use App\Document\Structure\TableData;
use App\Models\Document;
use App\Models\DocumentStructure;
use App\Models\User;
use App\Services\DocumentGeneration\UserReportBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Vérifie le rapport utilisateur structuré (F1 / §9) et l'estimation du temps (F2 / §2, §10).
 */
class UserReportAndTimeEstimateTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    public function test_la_page_d_upload_expose_l_estimation_de_duree_apres_selection_du_fichier(): void
    {
        $response = $this->get(route('documents.create'));

        $response->assertOk();
        $response->assertSee('id="file-time-estimate"', false);
        $response->assertSee('id="file-time-estimate-text"', false);
        $response->assertSee('estimateProcessingRange(', false);
        $response->assertSee('Durée estimée :', false);
    }

    public function test_le_rapport_utilisateur_structure_recense_les_corrections_et_les_avertissements(): void
    {
        $document = Document::create([
            'filename' => 'memoire_complet.docx',
            'path' => 'documents/memoire_complet.docx',
            'status' => 'detected',
            'metadata' => [
                'user_id' => $this->user->id,
                'size' => 128000,
                'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ],
        ]);

        $structural = new StructuralDocument(
            documentId: (string) $document->hash_id,
            sourceType: 'docx',
            blocks: [
                new Block(
                    blockId: 'b1',
                    type: BlockType::TocEntry,
                    text: '1. Introduction .......... 3',
                    confidence: 1.0,
                ),
                new Block(
                    blockId: 'b2',
                    type: BlockType::Heading,
                    text: '1. Introduction',
                    confidence: 0.95,
                    headingLevel: 1,
                ),
                new Block(
                    blockId: 'b3',
                    type: BlockType::Heading,
                    text: 'Titre déduit à confirmer',
                    confidence: 0.65,
                    headingLevel: 2,
                ),
                new Block(
                    blockId: 'b4',
                    type: BlockType::Table,
                    text: 'Cellule A | Cellule B',
                    confidence: 1.0,
                    tableData: new TableData(
                        rows: 2,
                        cols: 2,
                        cells: [['Cellule A', 'Cellule B'], ['10', '20']],
                    ),
                ),
                new Block(
                    blockId: 'b5',
                    type: BlockType::Caption,
                    text: 'Tableau 5 : Répartition budgétaire',
                    confidence: 0.95,
                    category: BlockCategory::Table,
                    originalNumber: '5',
                ),
                new Block(
                    blockId: 'b6',
                    type: BlockType::Figure,
                    text: '',
                    confidence: 1.0,
                    imageRef: 'rId4.img',
                ),
                new Block(
                    blockId: 'b7',
                    type: BlockType::Shape,
                    text: 'Organigramme de direction',
                    confidence: 1.0,
                ),
                new Block(
                    blockId: 'b8',
                    type: BlockType::Paragraph,
                    text: 'Comme illustré à la Figure 99 introuvable.',
                    confidence: 1.0,
                    crossRef: new CrossRef(
                        matchedText: 'Figure 99',
                        targetCategory: BlockCategory::Figure,
                        targetOriginalNumber: '99',
                        resolvedBlockId: null,
                        resolutionConfidence: null,
                    ),
                ),
            ],
            meta: [
                'fidelity' => 'exact',
                'empty_paragraphs_normalized' => 4,
                'cover_page' => [
                    'supprime' => 6,
                    'raison' => 'saut de page explicite avant SOMMAIRE',
                ],
            ],
        );

        DocumentStructure::create([
            'document_id' => $document->id,
            'structure' => [
                'titres' => [['texte' => '1. Introduction', 'niveau' => 1]],
            ],
            'structural_json' => $structural->toArray(),
            'schema_version' => StructuralDocument::SCHEMA_VERSION,
            'pipeline' => 'native',
            'ambiguities' => [],
        ]);

        $report = app(UserReportBuilder::class)->build($document->fresh());

        $this->assertSame((string) $document->hash_id, $report['document_id']);
        $this->assertSame('memoire_complet.docx', $report['filename']);
        $this->assertSame('native', $report['pipeline']);
        $this->assertSame(route('documents.preview', $document), $report['original_url']);
        $this->assertSame(route('documents.report', $document), $report['report_download_url']);

        $totals = $report['corrections_totals'];
        $this->assertSame(2, $totals['headings_formatted']);
        $this->assertSame(1, $totals['headings_confirmed']);
        $this->assertSame(1, $totals['headings_to_validate']);
        $this->assertSame(1, $totals['tables_formatted']);
        $this->assertSame(1, $totals['figures_and_images']);
        $this->assertSame(1, $totals['captions_detected']);
        $this->assertSame(1, $totals['captions_renumbered']);
        $this->assertSame(1, $totals['toc_entries_removed']);
        $this->assertSame(4, $totals['empty_paragraphs_normalized']);
        $this->assertSame(6, $totals['cover_page_removed_blocks']);
        $this->assertSame(1, $totals['shapes_preserved']);

        $this->assertTrue($report['has_warnings']);
        $warningCodes = array_column($report['warnings'], 'code');
        $this->assertContains('cover_page_removed', $warningCodes);
        $this->assertContains('ambiguous_blocks', $warningCodes);
        $this->assertContains('uncaptioned_figures', $warningCodes);
        $this->assertContains('cross_references', $warningCodes);
        $this->assertContains('shapes_preserved', $warningCodes);
    }

    public function test_le_rapport_est_affiche_sur_show_et_export_et_telechargeable_en_json(): void
    {
        $document = Document::create([
            'filename' => 'rapport_stage.docx',
            'path' => 'documents/rapport_stage.docx',
            'status' => 'detected',
            'metadata' => [
                'user_id' => $this->user->id,
                'size' => 64000,
                'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ],
        ]);

        $structural = new StructuralDocument(
            documentId: (string) $document->hash_id,
            sourceType: 'docx',
            blocks: [
                new Block(
                    blockId: 'b1',
                    type: BlockType::Heading,
                    text: '1. Introduction',
                    confidence: 0.95,
                    headingLevel: 1,
                ),
            ],
            meta: [
                'fidelity' => 'exact',
                'empty_paragraphs_normalized' => 3,
                'cover_page' => [
                    'supprime' => 4,
                    'raison' => 'page de garde détectée',
                ],
            ],
        );

        DocumentStructure::create([
            'document_id' => $document->id,
            'structure' => [
                'titres' => [['texte' => '1. Introduction', 'niveau' => 1]],
            ],
            'structural_json' => $structural->toArray(),
            'schema_version' => StructuralDocument::SCHEMA_VERSION,
            'pipeline' => 'native',
            'ambiguities' => [],
        ]);

        // 1. Affiché sur documents.show
        $show = $this->get(route('documents.show', $document));
        $show->assertOk();
        $show->assertSee('Rapport de traitement');
        $show->assertSee('Télécharger le rapport (JSON)');
        $show->assertSee('Document original');
        $show->assertSee(route('documents.report', $document), false);

        // 2. Affiché sur documents.export
        $export = $this->get(route('documents.export', $document));
        $export->assertOk();
        $export->assertSee('Rapport de traitement');
        $export->assertSee('Télécharger le rapport (JSON)');

        // 3. Téléchargeable via documents.report
        $download = $this->get(route('documents.report', $document));
        $download->assertOk();
        $download->assertHeader('Content-Type', 'application/json; charset=UTF-8');
        $download->assertHeader('Content-Disposition', 'attachment; filename="rapport_stage_rapport.json"');

        $payload = json_decode((string) $download->getContent(), true);
        $this->assertIsArray($payload);
        $this->assertSame((string) $document->hash_id, $payload['document_id']);
        $this->assertSame(1, $payload['corrections_totals']['headings_formatted']);
        $this->assertSame(3, $payload['corrections_totals']['empty_paragraphs_normalized']);
        $this->assertSame(4, $payload['corrections_totals']['cover_page_removed_blocks']);

        // 4. Protection IDOR : un autre utilisateur reçoit un 404
        $otherUser = User::factory()->create();
        $this->actingAs($otherUser)
            ->get(route('documents.report', $document))
            ->assertNotFound();
    }
}
