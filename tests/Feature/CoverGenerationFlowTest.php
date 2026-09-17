<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Document;
use App\Models\GeneratedDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Les routes documents sont protégées par auth (P0-1)
        $user = User::factory()->create();
        $this->actingAs($user);
    }

    /**
     * Rapport d'exemple (HeadingN → règles déterministes, pas de LLM).
     */
    private function createReportDocx(): string
    {
        $phpWord = new PhpWord;
        $phpWord->addTitleStyle(1, ['bold' => true, 'size' => 16]);
        $phpWord->addTitleStyle(2, ['bold' => true, 'size' => 14]);

        $section = $phpWord->addSection();
        $section->addTitle('Introduction', 1);
        $section->addText("L'objectif de ce rapport est de présenter le projet.");
        $section->addTitle('1. Contexte', 1);
        $section->addText('Le contexte du projet est le suivant.');
        $section->addTitle('1.1 Institution', 2);
        $section->addText('Figure 1: Architecture de la plateforme');

        $path = storage_path('app/test_tmp_'.uniqid().'.docx');
        IOFactory::createWriter($phpWord, 'Word2007')->save($path);

        return $path;
    }

    /**
     * Couverture d'exemple : les 4 zones annoncées par des mots-clés.
     */
    private function createCoverDocx(): string
    {
        $phpWord = new PhpWord;
        $section = $phpWord->addSection();

        $section->addText('REPUBLIQUE DU CAMEROUN', ['size' => 14, 'bold' => true], ['alignment' => Jc::CENTER]);
        $section->addText('UNIVERSITE DE DOUALA', ['size' => 12], ['alignment' => Jc::CENTER]);
        $section->addText("Thème : CONCEPTION D'UNE APPLICATION WEB", ['size' => 16, 'bold' => true], ['alignment' => Jc::CENTER]);
        $section->addText('Présenté par : JEAN DUPONT', ['size' => 11]);
        $section->addText('Encadré par : Dr. MARTIN', ['size' => 11]);
        $section->addText('Année académique 2024-2025', ['size' => 11]);

        $path = storage_path('app/test_tmp_cover_'.uniqid().'.docx');
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
                if (! method_exists($element, 'getText')) {
                    continue;
                }

                $content = $element->getText();
                // Certains éléments (ex. champ TOC) renvoient un tableau
                if (is_array($content)) {
                    $content = implode(' ', array_filter($content, 'is_string'));
                }

                $text .= (string) $content."\n";
            }
        }

        // Le lecteur ré-encode l'apostrophe ASCII en « &#039; »
        return html_entity_decode($text, ENT_QUOTES | ENT_HTML401, 'UTF-8');
    }

    public function test_generation_avec_couverture_remplace_les_valeurs(): void
    {
        // ⚠️ MODULE RETIRÉ : la page de garde n'est plus produite dans cette
        // version du produit. Ce test est conservé sous forme d'invariant — la
        // route `/generate-cover` ne doit plus exister — plutôt que supprimé,
        // pour que la trace du retrait reste lisible dans l'historique.
        $this->post('/documents/upload', [
            'document' => new UploadedFile(
                $this->createReportDocx(),
                'rapport_test.docx',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                null,
                true
            ),
        ])->assertRedirect();

        $document = Document::firstOrFail();

        $this->post("/documents/{$document->hash_id}/generate-cover", [])
            ->assertNotFound();
    }

    public function test_generation_sans_couverture_est_refusee(): void
    {
        // Idem : l'ancien comportement (exiger un fichier de couverture) n'a plus
        // de sens puisque la fonctionnalité est retirée. On vérifie à la place
        // que la génération SANS couverture fonctionne — c'est désormais le seul
        // chemin de production.
        Http::fake();

        $this->post('/documents/upload', [
            'document' => new UploadedFile(
                $this->createReportDocx(),
                'rapport_test.docx',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                null,
                true
            ),
        ])->assertRedirect();

        $document = Document::firstOrFail();

        $this->post("/documents/{$document->hash_id}/generate")->assertOk();

        $generated = GeneratedDocument::where('document_id', $document->id)->firstOrFail();
        $this->assertFileExists($generated->output_path);

        @unlink($generated->output_path);
    }

    public function test_la_route_de_generation_avec_gabarit_de_couverture_n_existe_plus(): void
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

        $document = Document::firstOrFail();

        $this->post("/documents/{$document->hash_id}/generate-cover-page", [
            'cover_page_template_id' => 1,
        ])->assertNotFound();
    }

    public function test_le_builder_de_modeles_de_couverture_n_est_plus_accessible(): void
    {
        // Les routes `cover-templates.*` étaient partiellement publiques : elles
        // doivent toutes avoir disparu, sans exception.
        foreach (['/cover-templates', '/cover-templates/from-example'] as $url) {
            $this->get($url)->assertNotFound();
        }
    }
}
