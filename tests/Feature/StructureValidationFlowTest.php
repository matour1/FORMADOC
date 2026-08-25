<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentStructure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use Tests\TestCase;

/**
 * Test de bout en bout de la Phase 4 — validation des ambiguïtés.
 *
 * Parcourt : upload → détection (déterministe) → détection des ambiguïtés
 * (numérotation vs niveau) → affichage → validation utilisateur → correction
 * de la structure.
 */
class StructureValidationFlowTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Les routes documents sont protégées par auth (P0-1)
        $user = \App\Models\User::factory()->create();
        $this->actingAs($user);
    }

    /**
     * Rapport avec une ambiguïté : « 1.1 Institution » est stylé Heading1
     * (niveau 1) alors que sa numérotation « 1.1 » suggère un niveau 2.
     * Les deux catégories critiques (titres, sous_titres) sont non vides →
     * les règles suffisent, aucun appel LLM.
     */
    private function createReportDocx(): string
    {
        $phpWord = new PhpWord();
        $phpWord->addTitleStyle(1, ['bold' => true, 'size' => 16]);
        $phpWord->addTitleStyle(2, ['bold' => true, 'size' => 14]);

        $section = $phpWord->addSection();
        $section->addTitle('1. Introduction', 1);
        $section->addText("L'objectif de ce rapport est de présenter le projet.");
        $section->addTitle('1.1 Institution', 1); // ← ambigu (numérotation = niveau 2)
        $section->addText('Le contexte institutionnel est le suivant.');
        $section->addTitle('2.1 Historique', 2);

        $path = storage_path('app/test_tmp_' . uniqid() . '.docx');
        IOFactory::createWriter($phpWord, 'Word2007')->save($path);

        return $path;
    }

    private function uploadReport(): Document
    {
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

        return Document::firstOrFail();
    }

    public function test_la_detection_produit_une_ambiguite_et_la_valider_corrige_la_structure(): void
    {
        $document = $this->uploadReport();
        $this->assertSame('detected', $document->status);

        // L'ambiguïté est détectée et persistée
        $structure = DocumentStructure::where('document_id', $document->id)->firstOrFail();
        $ambiguities = $structure->ambiguities;
        $this->assertCount(1, $ambiguities);
        $this->assertSame('1.1 Institution', $ambiguities[0]['texte']);
        $this->assertSame(1, $ambiguities[0]['niveau_detecte']);
        $this->assertSame(2, $ambiguities[0]['niveau_suggere']);

        // Aucun LLM : la détection d'ambiguïtés est déterministe
        Http::assertNothingSent();

        // L'interface de validation affiche l'ambiguïté
        $page = $this->get("/documents/{$document->id}");
        $page->assertOk();
        $page->assertSee('Validation des ambiguïtés');
        $page->assertSee('1.1 Institution');

        // L'utilisateur valide : rétrograder « 1.1 Institution » en niveau 2
        $id = $ambiguities[0]['id'];
        $this->post("/documents/{$document->id}/validate", [
            'corrections' => [$id => '2'],
        ])->assertRedirect();

        // La structure est corrigée et le statut passe à « validated »
        $document->refresh();
        $this->assertSame('validated', $document->status);

        $structure->refresh();
        $this->assertSame([], $structure->ambiguities);
        $this->assertSame([$id => '2'], $structure->validated_corrections);

        $this->assertNotContains('1.1 Institution', array_column($structure->structure['titres'], 'texte'));
        $this->assertContains('1.1 Institution', array_column($structure->structure['sous_titres'], 'texte'));
    }

    public function test_valider_sans_correction_confirme_la_structure(): void
    {
        $document = $this->uploadReport();

        $this->post("/documents/{$document->id}/validate", [])->assertRedirect();

        $document->refresh();
        $this->assertSame('validated', $document->status);

        $structure = DocumentStructure::where('document_id', $document->id)->firstOrFail();
        $this->assertSame([], $structure->ambiguities);
    }

    public function test_une_valeur_de_correction_invalide_est_refusee(): void
    {
        $document = $this->uploadReport();

        $structure = DocumentStructure::where('document_id', $document->id)->firstOrFail();
        $id = $structure->ambiguities[0]['id'];

        $response = $this->post("/documents/{$document->id}/validate", [
            'corrections' => [$id => '99'],
        ]);

        $response->assertSessionHasErrors('corrections.' . $id);
        $this->assertSame('detected', $document->refresh()->status);
    }
}
