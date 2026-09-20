<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Document\Structure\BlockType;
use App\Document\Structure\StructuralDocument;
use App\Models\Document;
use App\Models\DocumentStructure;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Support\DocxFixture;
use Tests\TestCase;

/**
 * Vérifie que le nouveau pipeline documentaire s'exécute bien à l'upload et
 * que sa persistance obéit au feature flag.
 *
 * C'est le test qui prouve que la refonte est RÉELLEMENT branchée — et pas
 * seulement écrite mais jamais appelée.
 */
class DocumentPipelineIntegrationTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> */
    private array $fixtures = [];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'document.persist_structural' => true,
            // Le pipeline natif n'exige aucun appel IA : la classification
            // déterministe suffit pour ce test d'intégration.
            'document.pipeline.v2' => true,
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->fixtures as $path) {
            DocxFixture::cleanup($path);
        }
        $this->fixtures = [];

        parent::tearDown();
    }

    private function user(): User
    {
        return User::factory()->create(['credits_balance' => 100]);
    }

    /**
     * Crée un `.docx` de test et l'envoie via la route d'upload.
     */
    private function upload(User $user, string $bodyXml): Document
    {
        $path = DocxFixture::create($bodyXml);
        $this->fixtures[] = $path;

        $response = $this->actingAs($user)->post('/documents/upload', [
            'document' => new UploadedFile(
                $path,
                'rapport_test.docx',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                null,
                true // test file
            ),
            'title_method' => 'regex',
        ]);

        $response->assertRedirect();

        return Document::latest('id')->firstOrFail();
    }

    // -------------------------------------------------------------------------
    // Exécution du pipeline à l'upload
    // -------------------------------------------------------------------------

    public function test_l_upload_persiste_le_json_structurel_quand_le_pipeline_est_actif(): void
    {
        $document = $this->upload($this->user(), DocxFixture::paragraph('1. Introduction'));

        $structure = DocumentStructure::where('document_id', $document->id)->first();

        $this->assertNotNull($structure);
        $this->assertNotNull($structure->structural_json, 'Le JSON structurel doit être persisté');
        $this->assertSame(StructuralDocument::SCHEMA_VERSION, $structure->schema_version);
        $this->assertSame('native', $structure->pipeline);
    }

    public function test_l_ancien_format_est_conserve_en_parallele(): void
    {
        // Principe du strangleur : les DEUX formats coexistent, l'ancien n'est
        // jamais supprimé tant que la migration n'est pas validée.
        $document = $this->upload($this->user(), DocxFixture::paragraph('1. Introduction'));

        $structure = DocumentStructure::where('document_id', $document->id)->first();

        $this->assertNotNull($structure->structure, 'L\'ancien format doit rester présent');
        $this->assertArrayHasKey('body_complet', $structure->structure);
    }

    public function test_le_json_structurel_contient_les_blocs_detectes(): void
    {
        $document = $this->upload(
            $this->user(),
            DocxFixture::paragraph('1. Introduction')
            .DocxFixture::paragraph('Ceci est un paragraphe de contenu.')
            .DocxFixture::paragraph('Figure 1 : Schéma du système')
        );

        $structure = DocumentStructure::where('document_id', $document->id)->first();
        $structural = StructuralDocument::fromArray($structure->structural_json);

        $this->assertGreaterThanOrEqual(3, $structural->count());
        $this->assertNotEmpty($structural->headings(), 'Le titre doit être détecté');

        $captions = $structural->blocksOfType(BlockType::Caption);
        $this->assertCount(1, $captions);
        $this->assertSame('1', $captions[0]->originalNumber);
    }

    public function test_la_structure_persistee_est_serialisable_et_relisible(): void
    {
        $document = $this->upload($this->user(), DocxFixture::paragraph('1. Introduction'));

        $structure = DocumentStructure::where('document_id', $document->id)->first();
        $structural = $structure->structuralDocument();

        $this->assertInstanceOf(StructuralDocument::class, $structural);
        $this->assertSame((string) $document->hash_id, $structural->documentId);
    }

    public function test_le_modele_expose_la_structure_native(): void
    {
        $document = $this->upload($this->user(), DocxFixture::paragraph('1. Introduction'));

        $structure = DocumentStructure::where('document_id', $document->id)->first();

        $this->assertTrue($structure->wasProcessedByNativePipeline());
        $this->assertNotNull($structure->structuralDocument());
    }

    // -------------------------------------------------------------------------
    // Feature flag
    // -------------------------------------------------------------------------

    public function test_aucun_json_structurel_quand_le_pipeline_est_desactive(): void
    {
        // Comportement par défaut : l'ancien pipeline seul. Le document est
        // traité normalement, sans nouvelle colonne renseignée.
        config(['document.pipeline.v2' => false]);

        $document = $this->upload($this->user(), DocxFixture::paragraph('1. Introduction'));

        $structure = DocumentStructure::where('document_id', $document->id)->first();

        $this->assertNotNull($structure->structure, 'Le traitement legacy doit avoir eu lieu');
        $this->assertNull($structure->structural_json);
        $this->assertFalse($structure->wasProcessedByNativePipeline());
    }

    public function test_aucune_persistance_quand_elle_est_desactivee(): void
    {
        // Permet d'observer le nouveau pipeline SANS écrire en base.
        config(['document.persist_structural' => false]);

        $document = $this->upload($this->user(), DocxFixture::paragraph('1. Introduction'));

        $structure = DocumentStructure::where('document_id', $document->id)->first();

        $this->assertNull($structure->structural_json);
    }

    public function test_un_document_illisible_est_rejete_sans_structure_orpheline(): void
    {
        // Un fichier réellement illisible pour les DEUX pipelines est rejeté :
        // le contrôleur nettoie le document et son fichier (P2-1). On vérifie
        // qu'aucune structure orpheline ne subsiste en base.
        config(['document.pipeline.v2' => true]);

        $zipPath = tempnam(sys_get_temp_dir(), 'invalid_docx_').'.docx';
        $zip = new \ZipArchive;
        $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('autre.txt', 'ce n\'est pas un document word');
        // Un `document.xml` invalide fait échouer la lecture des deux pipelines
        // tout en passant la vérification de signature ZIP.
        $zip->addFromString('word/document.xml', '<xml>invalide<<<');
        $zip->close();
        $this->fixtures[] = $zipPath;

        $this->actingAs($this->user())->post('/documents/upload', [
            'document' => new UploadedFile(
                $zipPath,
                'corrompu.docx',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                null,
                true
            ),
            'title_method' => 'regex',
        ]);

        // Aucun document ne doit rester en base : le nettoyage P2-1 a bien joué.
        $this->assertSame(0, Document::count(), 'Le document corrompu doit être nettoyé');
        $this->assertSame(0, DocumentStructure::count(), 'Aucune structure orpheline');
    }

    public function test_aucune_structure_native_pour_un_fichier_non_docx(): void
    {
        // Cas du mode « auto » : format non supporté → conversion impossible,
        // l'ancien pipeline fait foi sans que l'utilisateur voie une erreur.
        config(['document.pipeline.v2' => 'auto']);

        $textPath = tempnam(sys_get_temp_dir(), 'not_docx_').'.txt';
        file_put_contents($textPath, "Rapport de test\n\nIntroduction\n\nContenu.");
        $this->fixtures[] = $textPath;

        $response = $this->actingAs($this->user())->post('/documents/upload', [
            'document' => new UploadedFile(
                $textPath,
                'rapport.txt',
                'text/plain',
                null,
                true
            ),
            'title_method' => 'regex',
        ]);

        $response->assertRedirect();
        $document = Document::latest('id')->firstOrFail();
        $structure = DocumentStructure::where('document_id', $document->id)->first();

        $this->assertNotNull($structure, 'Le document doit être traité par l\'ancien pipeline');
        $this->assertNull($structure->structural_json);
    }

    // -------------------------------------------------------------------------
    // Non-régression du parcours utilisateur
    // -------------------------------------------------------------------------

    public function test_le_statut_du_document_reste_inchange_avec_le_pipeline_actif(): void
    {
        // Le nouveau pipeline est un AJOUT : il ne doit rien changer au parcours
        // visible par l'utilisateur.
        $document = $this->upload($this->user(), DocxFixture::paragraph('1. Introduction'));

        $this->assertSame('detected', $document->status);
    }

    public function test_l_upload_est_refuse_sans_authentification(): void
    {
        // Rappel du correctif de sécurité P0-1 : les routes documents sont
        // protégées. Le branchement du nouveau pipeline ne doit pas l'oublier.
        $this->post('/documents/upload', [])->assertRedirect(route('login'));
    }

    /**
     * ⚠️ Régression trouvée en activant le pipeline : la page de validation
     * affichait l'intitulé de la section de frontispice comme TYPE d'élément.
     *
     * `documents/show.blade.php` appelait `BlockCategory::listTitle()` — qui
     * renvoie « LISTE DES FIGURES » (le titre de la section qui recense les
     * figures) — à la place de `keyword()`, qui renvoie « Figure » (le type de
     * l'élément lui-même). La colonne « Type » du tableau des légendes
     * annonçait donc « LISTE DES FIGURES » au lieu de « Figure ».
     *
     * Le défaut restait invisible tant que le pipeline v2 était désactivé : la
     * vue empruntait l'autre branche et lisait le format historique.
     */
    public function test_la_page_de_validation_affiche_le_type_des_legendes(): void
    {
        $document = $this->upload(
            $this->user(),
            DocxFixture::paragraph('Figure 1 : Architecture de la plateforme'),
        );

        $page = $this->get("/documents/{$document->hash_id}");

        $page->assertOk();
        // Le type de l'élément, et non l'intitulé de la section de frontispice.
        $page->assertSee('Figure');
        $page->assertDontSee('LISTE DES FIGURES');
    }
}
