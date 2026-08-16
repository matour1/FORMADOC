<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\GeneratedDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\SimpleType\Jc;
use Tests\TestCase;

/**
 * Test de bout en bout du flux Couverture (Phase 3).
 *
 * Simule : upload du rapport → upload d'une couverture d'exemple + valeurs
 * (nom, titre, encadrant, date) → génération d'un DOCX avec couverture
 * préfixée → téléchargement. Vérifie que les zones détectées sont remplacées
 * (label conservé) et que le document produit reste un DOCX valide.
 */
class CoverGenerationFlowTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    /**
     * Rapport d'exemple (HeadingN → règles déterministes, pas de LLM).
     */
    private function createReportDocx(): string
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
        $section->addText('Figure 1: Architecture de la plateforme');

        $path = storage_path('app/test_tmp_' . uniqid() . '.docx');
        IOFactory::createWriter($phpWord, 'Word2007')->save($path);

        return $path;
    }

    /**
     * Couverture d'exemple : les 4 zones annoncées par des mots-clés.
     */
    private function createCoverDocx(): string
    {
        $phpWord = new PhpWord();
        $section = $phpWord->addSection();

        $section->addText('REPUBLIQUE DU CAMEROUN', ['size' => 14, 'bold' => true], ['alignment' => Jc::CENTER]);
        $section->addText('UNIVERSITE DE DOUALA', ['size' => 12], ['alignment' => Jc::CENTER]);
        $section->addText("Thème : CONCEPTION D'UNE APPLICATION WEB", ['size' => 16, 'bold' => true], ['alignment' => Jc::CENTER]);
        $section->addText('Présenté par : JEAN DUPONT', ['size' => 11]);
        $section->addText('Encadré par : Dr. MARTIN', ['size' => 11]);
        $section->addText('Année académique 2024-2025', ['size' => 11]);

        $path = storage_path('app/test_tmp_cover_' . uniqid() . '.docx');
        IOFactory::createWriter($phpWord, 'Word2007')->save($path);

        return $path;
    }

    /**
     * Extrait tout le texte d'un DOCX (toutes sections).
     */
    private function extractText(string $path): string
    {
        $phpWord = IOFactory::load($path);
        $text = '';

        foreach ($phpWord->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                if (!method_exists($element, 'getText')) {
                    continue;
                }

                $content = $element->getText();
                // Certains éléments (ex. champ TOC) renvoient un tableau
                if (is_array($content)) {
                    $content = implode(' ', array_filter($content, 'is_string'));
                }

                $text .= (string) $content . "\n";
            }
        }

        // Le lecteur ré-encode l'apostrophe ASCII en « &#039; »
        return html_entity_decode($text, ENT_QUOTES | ENT_HTML401, 'UTF-8');
    }

    public function test_generation_avec_couverture_remplace_les_valeurs(): void
    {
        // Rapport : les règles déterministes suffisent → pas de LLM
        Http::fake();

        // 1) Upload du rapport
        $this->post('/documents/upload', [
            'document' => new UploadedFile(
                $this->createReportDocx(),
                'rapport_test.docx',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                null,
                true
            ),
        ])->assertRedirect();

        $document = \App\Models\Document::firstOrFail();
        $this->assertSame('detected', $document->status);

        // 2) Génération AVEC couverture
        $response = $this->post("/documents/{$document->id}/generate-cover", [
            'cover' => new UploadedFile(
                $this->createCoverDocx(),
                'couverture.docx',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                null,
                true
            ),
            'nom' => 'MARIE CURIE',
            'titre' => 'MACHINE LEARNING APPLIQUÉ',
            'encadrant' => 'Dr. DUPONT',
            'date' => '2025-2026',
        ]);

        // 3) Réponse = téléchargement d'un DOCX
        $response->assertOk();
        $this->assertStringContainsString(
            'attachment',
            $response->headers->get('Content-Disposition', '')
        );

        // 4) Le DOCX généré est persistant et valide, avec les valeurs remplacées
        $generated = GeneratedDocument::where('document_id', $document->id)->firstOrFail();
        $this->assertSame('generated', $generated->status);
        $this->assertFileExists($generated->output_path);

        $text = $this->extractText($generated->output_path);

        // Valeurs remplacées
        $this->assertStringContainsString('MARIE CURIE', $text);
        $this->assertStringContainsString('MACHINE LEARNING APPLIQUÉ', $text);
        $this->assertStringContainsString('Dr. DUPONT', $text);
        $this->assertStringContainsString('2025-2026', $text);

        // Labels conservés
        $this->assertStringContainsString('Présenté par', $text);
        $this->assertStringContainsString('Thème', $text);
        $this->assertStringContainsString('Encadré par', $text);
        $this->assertStringContainsString('Année académique', $text);

        // Valeur d'origine de la couverture remplacée
        $this->assertStringNotContainsString('JEAN DUPONT', $text);

        // Le corps du rapport est toujours présent après la couverture
        $this->assertStringContainsString('Introduction', $text);

        // Aucun appel au LLM (la couverture est déterministe)
        Http::assertNothingSent();
    }

    public function test_generation_sans_couverture_est_refusee(): void
    {
        $this->post('/documents/upload', [
            'document' => new UploadedFile(
                $this->createReportDocx(),
                'rapport_test.docx',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                null,
                true
            ),
        ])->assertRedirect();

        $document = \App\Models\Document::firstOrFail();

        $response = $this->post("/documents/{$document->id}/generate-cover", []);

        $response->assertSessionHasErrors('cover');
    }
}
