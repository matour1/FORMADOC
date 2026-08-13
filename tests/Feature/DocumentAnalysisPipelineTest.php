<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentStructure;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use Tests\TestCase;

/**
 * Test de bout en bout du pipeline d'analyse (Phase 1).
 *
 * Simule un upload réel de DOCX puis vérifie :
 *   upload → stockage → extraction texte → détection titres (LLM mocké)
 *   → détection légendes (regex) → sauvegarde structure → affichage.
 */
class DocumentAnalysisPipelineTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    private function createTestDocx(string $filename = 'rapport_test.docx'): string
    {
        $phpWord = new PhpWord();
        $phpWord->addTitleStyle(1, ['bold' => true, 'size' => 16]);
        $phpWord->addTitleStyle(2, ['bold' => true, 'size' => 14]);

        $section = $phpWord->addSection();
        $section->addTitle('Introduction', 1);
        $section->addText("L'objectif de ce rapport est de présenter le projet.");
        $section->addTitle('1. Contexte', 1);
        $section->addText('Le contexte du projet est le suivant.');
        $section->addTitle('1.1 Institution', 2);
        $section->addText('L\'institution exige des normes de mise en forme.');
        $section->addText('Figure 1: Architecture de la plateforme');
        $section->addText('Le schéma ci-dessus illustre l\'architecture.');
        $section->addText('Tableau 1: Résultats comparatifs');

        $path = storage_path('app/test_tmp_' . uniqid() . '.docx');
        IOFactory::createWriter($phpWord, 'Word2007')->save($path);
        return $path;
    }

    public function test_pipeline_complet_upload_analyse_affichage(): void
    {
        // Mock de l'API DeepSeek (aucun appel réseau réel)
        Http::fake([
            'api.deepseek.com/*' => Http::response([
                'choices' => [
                    ['message' => ['content' => "# Introduction\n## 1. Contexte\n### 1.1 Institution"]],
                ],
            ], 200),
        ]);

        $docxPath = $this->createTestDocx();

        // Upload simulé
        $response = $this->post('/documents/upload', [
            'document' => new \Illuminate\Http\UploadedFile(
                $docxPath,
                'rapport_test.docx',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                null,
                true // test mode
            ),
        ]);

        // Redirection vers la page du document
        $response->assertRedirect();

        $document = Document::first();
        $this->assertNotNull($document);
        $this->assertSame('detected', $document->status);

        // La structure a été sauvegardée
        $structure = DocumentStructure::where('document_id', $document->id)->first();
        $this->assertNotNull($structure);

        $data = $structure->structure;
        $this->assertArrayHasKey('titles', $data);
        $this->assertStringContainsString('# Introduction', $data['titles']);
        $this->assertStringContainsString('## 1. Contexte', $data['titles']);

        // Les légendes ont été détectées par regex
        $this->assertArrayHasKey('legends', $data);
        $legendTypes = array_column($data['legends'], 'type');
        $this->assertContains('Figure', $legendTypes);
        $this->assertContains('Tableau', $legendTypes);

        // L'API a bien été appelée avec le bon modèle
        Http::assertSent(function (Request $request) {
            return $request['model'] === config('deepseek.model');
        });

        // La page d'affichage est accessible et montre le résultat
        $page = $this->get("/documents/{$document->id}");
        $page->assertOk();
        $page->assertSee('Introduction');
        $page->assertSee('Figure');
        $page->assertSee('Architecture de la plateforme');
    }

    public function test_upload_sans_fichier_est_refuse(): void
    {
        $response = $this->post('/documents/upload', []);
        $response->assertSessionHasErrors('document');
    }

    public function test_upload_fichier_non_word_est_refuse(): void
    {
        $response = $this->post('/documents/upload', [
            'document' => \Illuminate\Http\UploadedFile::fake()->create('malware.exe', 10),
        ]);

        $response->assertSessionHasErrors('document');
        $this->assertDatabaseCount('documents', 0);
    }

    public function test_upload_fichier_trop_gros_est_refuse(): void
    {
        $response = $this->post('/documents/upload', [
            'document' => \Illuminate\Http\UploadedFile::fake()->create(
                'gros.docx',
                60 * 1024 // 60 Mo > 50 Mo max
            ),
        ]);

        $response->assertSessionHasErrors('document');
        $this->assertDatabaseCount('documents', 0);
    }
}
