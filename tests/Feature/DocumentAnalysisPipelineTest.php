<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentStructure;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use Tests\TestCase;

/**
 * Test de bout en bout du pipeline d'analyse (Phase E — DocAnalyzer).
 *
 * Simule un upload réel de DOCX puis vérifie :
 *   upload → stockage → analyse (DocAnalyzer : règles déterministes)
 *   → détection légendes (regex) → sauvegarde structure JSON → affichage.
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
        // Le DOCX utilise des styles HeadingN (addTitle) : les règles
        // déterministes suffisent → l'API DeepSeek ne doit PAS être appelée.
        Http::fake();

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

        // La structure a été sauvegardée (format DocAnalyzer : catégories)
        $structure = DocumentStructure::where('document_id', $document->id)->first();
        $this->assertNotNull($structure);

        $data = $structure->structure;
        $this->assertArrayHasKey('titres', $data);
        $this->assertArrayHasKey('sous_titres', $data);
        $this->assertArrayHasKey('legends', $data);

        // Les titres HeadingN sont détectés par les règles (déterministe)
        $titres = array_column($data['titres'], 'texte');
        $this->assertContains('Introduction', $titres);
        $this->assertContains('1. Contexte', $titres);

        $sousTitres = array_column($data['sous_titres'], 'texte');
        $this->assertContains('1.1 Institution', $sousTitres);

        // Les légendes ont été détectées par regex
        $legendTypes = array_column($data['legends'], 'type');
        $this->assertContains('Figure', $legendTypes);
        $this->assertContains('Tableau', $legendTypes);

        // Les règles suffisent → AUCUN appel à l'API
        Http::assertNothingSent();

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

    public function test_upload_avec_title_method_regex_est_stocke(): void
    {
        Http::fake();

        $docxPath = $this->createTestDocx();

        $response = $this->post('/documents/upload', [
            'document' => new \Illuminate\Http\UploadedFile(
                $docxPath,
                'rapport_regex.docx',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                null,
                true
            ),
            'title_method' => 'regex',
        ]);

        $response->assertRedirect();

        $document = Document::first();
        $this->assertNotNull($document);
        $this->assertSame('detected', $document->status);
        $this->assertSame('regex', $document->metadata['title_method']);

        // Les règles + regex suffisent → pas d'appel IA
        Http::assertNothingSent();
    }

    public function test_upload_avec_title_method_ia_appelle_l_ia(): void
    {
        // L'IA est appelée car la méthode 'ia' force le recours à DeepSeek
        $iaJson = json_encode([
            'titres' => [
                ['texte' => 'Introduction', 'position' => ['section_index' => 0, 'element_index' => 0, 'parent' => 'body']],
            ],
            'sous_titres' => [],
            'en_tetes' => [],
            'pieds_de_page' => [],
            'tableaux' => [],
            'images' => [],
            'elements_flottants' => [],
        ], JSON_UNESCAPED_UNICODE);

        Http::fake([
            'api.deepseek.com/*' => Http::response([
                'choices' => [['message' => ['content' => $iaJson]]],
            ], 200),
        ]);

        $docxPath = $this->createTestDocx();

        $response = $this->post('/documents/upload', [
            'document' => new \Illuminate\Http\UploadedFile(
                $docxPath,
                'rapport_ia.docx',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                null,
                true
            ),
            'title_method' => 'ia',
        ]);

        $response->assertRedirect();

        $document = Document::first();
        $this->assertNotNull($document);
        $this->assertSame('ia', $document->metadata['title_method']);

        // La méthode IA force l'appel à DeepSeek
        Http::assertSentCount(1);
    }

    public function test_upload_sans_use_ai_n_appelle_pas_l_ia(): void
    {
        // Phase 4 : l'IA est OPTIONNELLE. Sans la case « use_ai », aucun appel
        // externe n'est émis, même si des ambiguïtés existent.
        Http::fake();

        $docxPath = $this->createTestDocx();

        $response = $this->post('/documents/upload', [
            'document' => new \Illuminate\Http\UploadedFile(
                $docxPath,
                'rapport_sans_ai.docx',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                null,
                true
            ),
            'title_method' => 'regex',
            // 'use_ai' volontairement absent (comportement par défaut)
        ]);

        $response->assertRedirect();

        $document = Document::first();
        $this->assertNotNull($document);
        $this->assertFalse($document->metadata['use_ai']);

        $structure = DocumentStructure::where('document_id', $document->id)->first();
        $this->assertNotNull($structure);
        // Aucune trace IA dans la structure
        $this->assertArrayNotHasKey('ai_corrections', $structure->structure);

        Http::assertNothingSent();
    }

    public function test_upload_avec_use_ai_appelle_l_ia_correcteur(): void
    {
        // Phase 4 : la case « Utiliser l'assistance IA » active le
        // post-processeur AiCorrectionService (corrections ciblées).
        // title_method=regex → DocAnalyzer n'appelle PAS l'IA ; seul le
        // correcteur envoie une requête (payload réduit aux éléments ambigus).
        $iaJson = json_encode(['corrections' => []], JSON_UNESCAPED_UNICODE);

        Http::fake([
            'api.deepseek.com/*' => Http::response([
                'choices' => [['message' => ['content' => $iaJson]]],
            ], 200),
        ]);

        $docxPath = $this->createTestDocx();

        $response = $this->post('/documents/upload', [
            'document' => new \Illuminate\Http\UploadedFile(
                $docxPath,
                'rapport_avec_ai.docx',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                null,
                true
            ),
            'title_method' => 'regex',
            'use_ai' => '1',
        ]);

        $response->assertRedirect();

        $document = Document::first();
        $this->assertNotNull($document);
        $this->assertTrue($document->metadata['use_ai']);

        // Le correcteur IA a été sollicité (1 appel unique)
        Http::assertSentCount(1);

        $structure = DocumentStructure::where('document_id', $document->id)->first();
        $this->assertNotNull($structure);
        // L'IA n'a proposé aucune correction → la structure déterministe est
        // conservée strictement à l'identique (aucune clé de traçabilité).
        $this->assertArrayNotHasKey('ai_corrections', $structure->structure);
    }

    public function test_upload_title_method_invalide_est_refuse(): void
    {
        $docxPath = $this->createTestDocx();

        $response = $this->post('/documents/upload', [
            'document' => new \Illuminate\Http\UploadedFile(
                $docxPath,
                'rapport_invalide.docx',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                null,
                true
            ),
            'title_method' => 'magique',
        ]);

        $response->assertSessionHasErrors('title_method');
        $this->assertDatabaseCount('documents', 0);
    }

    public function test_upload_fichier_txt_est_analyse_avec_regex(): void
    {
        Http::fake();

        // Fichier texte brut : pas de styles Word → la passe regex détecte
        // les titres numérotés, sans appel IA.
        $txtPath = storage_path('app/test_tmp_txt_' . uniqid() . '.txt');
        file_put_contents($txtPath, "RAPPORT DE STAGE\n\n1. Introduction\nCeci est un paragraphe.\n1.1 Contexte\nFin du rapport.\n");

        $response = $this->post('/documents/upload', [
            'document' => new \Illuminate\Http\UploadedFile(
                $txtPath,
                'rapport_texte.txt',
                'text/plain',
                null,
                true
            ),
            'title_method' => 'regex',
        ]);

        $response->assertRedirect();

        $document = Document::first();
        $this->assertNotNull($document);
        $this->assertSame('detected', $document->status);
        $this->assertSame('regex', $document->metadata['title_method']);

        $structure = DocumentStructure::where('document_id', $document->id)->first();
        $this->assertNotNull($structure);

        $data = $structure->structure;
        $titres = array_column($data['titres'], 'texte');
        $this->assertContains('1. Introduction', $titres);

        $sousTitres = array_column($data['sous_titres'], 'texte');
        $this->assertContains('1.1 Contexte', $sousTitres);

        // La passe regex a suffi → aucun appel IA
        Http::assertNothingSent();
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

    public function test_generation_docx_depuis_la_structure(): void
    {
        Http::fake();

        // 1. Upload + analyse (structure sauvegardée)
        $docxPath = $this->createTestDocx();
        $this->post('/documents/upload', [
            'document' => new \Illuminate\Http\UploadedFile(
                $docxPath,
                'rapport_test.docx',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                null,
                true
            ),
        ]);

        $document = Document::first();
        $this->assertNotNull($document);

        // 2. Génération du DOCX reconstruit
        $response = $this->post("/documents/{$document->id}/generate");
        $response->assertOk();

        // 3. Le fichier généré est un DOCX valide et rechargable
        $generated = \App\Models\GeneratedDocument::where('document_id', $document->id)->first();
        $this->assertNotNull($generated);
        $this->assertSame('generated', $generated->status);
        $this->assertFileExists($generated->output_path);

        $reloaded = IOFactory::load($generated->output_path);
        $this->assertInstanceOf(\PhpOffice\PhpWord\PhpWord::class, $reloaded);
    }
}
