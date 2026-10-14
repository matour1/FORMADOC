<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Document;
use App\Models\Feedback;
use App\Models\GeneratedDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use Tests\TestCase;

/**
 * Test de bout en bout de la Phase 5 — parcours complet.
 *
 * Parcourt : upload (1) → validation (2) → traitement (3) → export (4)
 * → génération/téléchargement du DOCX → page d'avis.
 */
class ParcoursCompletFlowTest extends TestCase
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
     * Rapport sans ambiguïté : numérotation cohérente avec les niveaux.
     */
    private function createReportDocx(): string
    {
        $phpWord = new PhpWord;
        $phpWord->addTitleStyle(1, ['bold' => true, 'size' => 16]);
        $phpWord->addTitleStyle(2, ['bold' => true, 'size' => 14]);

        $section = $phpWord->addSection();
        $section->addTitle('1. Introduction', 1);
        $section->addText("L'objectif de ce rapport est de présenter le projet.");
        $section->addTitle('1.1 Institution', 2);
        $section->addText('Le contexte institutionnel est le suivant.');
        $section->addTitle('2. Contexte', 1);
        $section->addText('Le contexte du projet est le suivant.');
        $section->addText('Figure 1: Architecture de la plateforme');
        $section->addText('Tableau 1: Résultats comparatifs');

        $path = storage_path('app/test_tmp_'.uniqid().'.docx');
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

    public function test_parcours_complet_upload_validation_traitement_export_generation(): void
    {
        // 1. Upload → détection (statut « detected »)
        $document = $this->uploadReport();
        $this->assertSame('detected', $document->status);

        // 2. Page de validation (étape 2)
        $validation = $this->get("/documents/{$document->hash_id}");
        $validation->assertOk();
        $validation->assertSee('Étape 2 / 4');
        $validation->assertSee('Validation des ambiguïtés');
        $validation->assertSee('Valider et lancer le traitement');

        // 3. Validation → redirection vers le traitement (étape 3)
        $this->post("/documents/{$document->hash_id}/validate", [])
            ->assertRedirect(route('documents.processing', $document));

        $document->refresh();
        $this->assertSame('validated', $document->status);

        // 4. Page de traitement (étape 3)
        $processing = $this->get(route('documents.processing', $document));
        $processing->assertOk();
        $processing->assertSee('Traitement en cours');
        $processing->assertSee('Étape 3 / 4');

        // 5. Page d'export (étape 4)
        $export = $this->get(route('documents.export', $document));
        $export->assertOk();
        $export->assertSee('Votre document est prêt');
        $export->assertSee('Étape 4 / 4');
        $export->assertSee('Télécharger le DOCX');

        // 6. Génération → téléchargement du DOCX reconstruit
        $download = $this->post(route('documents.generate', $document));
        $download->assertOk();

        $generated = GeneratedDocument::where('document_id', $document->id)->first();
        $this->assertNotNull($generated);
        $this->assertSame('generated', $generated->status);
        $this->assertFileExists($generated->output_path);

        // 7. Page d'avis accessible
        $feedback = $this->get('/feedback');
        $feedback->assertOk();
        $feedback->assertSee('Votre avis nous intéresse');
    }

    public function test_le_feedback_peut_inclure_une_recommandation(): void
    {
        $this->post('/feedback', [
            'email' => 'etudiant@example.com',
            'avis' => 'Très bon outil de mise en forme automatique.',
            'note' => '5',
            'recommander' => '1',
            'problemes_rencontres' => null,
        ])->assertRedirect();

        $feedback = Feedback::firstOrFail();
        $this->assertSame('etudiant@example.com', $feedback->email);
        $this->assertSame(5, $feedback->note);
        $this->assertTrue($feedback->recommander);
    }

    /**
     * **La page d'export doit compter la MÊME structure que la validation.**
     *
     * Défaut réel mesuré le 2026-10-11 : la validation lit `structural_json`
     * (pipeline natif) alors que l'export ne lisait que l'ancien `structure`. Sur
     * un document de test, la validation affichait « 14 titres · 4 légendes » et
     * l'export « 8 titres · 0 légende » — pour le MÊME document. Or le DOCX
     * téléchargé est produit par le pipeline natif : c'est donc l'export qui
     * mentait sur ce que l'utilisateur allait obtenir.
     *
     * Le test compare les DEUX écrans : si une page repasse à une autre source,
     * les chiffres divergent et l'assertion casse. Contrôler une seule page ne
     * l'aurait pas vu — le défaut était justement leur désaccord.
     */
    public function test_export_et_validation_comptent_la_meme_structure(): void
    {
        $document = $this->uploadReport();

        $validation = $this->get("/documents/{$document->hash_id}");
        $validation->assertOk();

        $this->post("/documents/{$document->hash_id}/validate", [])->assertRedirect();

        $export = $this->get(route('documents.export', $document));
        $export->assertOk();

        // 3 titres (« Introduction », « Institution », « Contexte ») et 2 légendes
        // (« Figure 1 », « Tableau 1 »). Le comptage des LÉGENDES n'apparaît en
        // clair que sur la validation ; l'export l'affiche dans une cellule à part
        // (chiffre et libellé séparés), donc c'est le comptage des TITRES — continu
        // sur les deux écrans — qui sert de point de comparaison.
        $validation->assertSee('3 titres détectés');
        $validation->assertSee('2 légendes détectées');
        $export->assertSee('3 titres mis en forme');
    }
}
