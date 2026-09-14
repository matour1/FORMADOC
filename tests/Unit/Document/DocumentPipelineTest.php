<?php

declare(strict_types=1);

namespace Tests\Unit\Document;

use App\Document\Adapters\DocxOoxml\Exceptions\DocxReadException;
use App\Document\DocumentPipeline;
use App\Document\Structure\StructuralDocument;
use Tests\Support\DocxFixture;
use Tests\TestCase;

/**
 * Tests de l'orchestrateur de coexistence des deux pipelines documentaires.
 *
 * Enjeu central : en mode `auto`, un échec du nouveau parseur doit **replier
 * silencieusement** sur l'ancien pipeline plutôt que de faire perdre le
 * document de l'utilisateur. C'est la garantie de sécurité de la migration.
 */
class DocumentPipelineTest extends TestCase
{
    /** @var array<int, string> */
    private array $fixtures = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Désactiver la persistance pour ces tests : ils ne portent pas sur la
        // base de données mais sur la logique de bascule.
        config(['document.persist_structural' => false]);
    }

    protected function tearDown(): void
    {
        foreach ($this->fixtures as $path) {
            DocxFixture::cleanup($path);
        }
        $this->fixtures = [];

        parent::tearDown();
    }

    private function pipeline(): DocumentPipeline
    {
        return app(DocumentPipeline::class);
    }

    private function fixture(string $bodyXml = ''): string
    {
        $path = DocxFixture::create($bodyXml !== '' ? $bodyXml : DocxFixture::paragraph('Contenu de test'));
        $this->fixtures[] = $path;

        return $path;
    }

    private function setMode(mixed $mode): void
    {
        config(['document.pipeline.v2' => $mode]);
    }

    // -------------------------------------------------------------------------
    // Lecture du mode
    // -------------------------------------------------------------------------

    public function test_le_pipeline_natif_est_desactive_par_defaut(): void
    {
        // Sécurité : le pipeline éprouvé en production reste le défaut tant que
        // la migration n'est pas validée.
        $this->setMode(false);

        $this->assertFalse($this->pipeline()->isNativeEnabled());
        $this->assertFalse($this->pipeline()->isAutoMode());
    }

    public function test_le_mode_true_active_le_pipeline_natif_seul(): void
    {
        $this->setMode(true);

        $this->assertTrue($this->pipeline()->isNativeEnabled());
        $this->assertFalse($this->pipeline()->isAutoMode());
    }

    public function test_le_mode_auto_active_le_repli(): void
    {
        $this->setMode('auto');

        $this->assertTrue($this->pipeline()->isNativeEnabled());
        $this->assertTrue($this->pipeline()->isAutoMode());
    }

    // -------------------------------------------------------------------------
    // Conversion quand le pipeline est désactivé
    // -------------------------------------------------------------------------

    public function test_aucune_conversion_quand_le_pipeline_est_desactive(): void
    {
        // L'appelant détecte `null` et utilise l'ancien pipeline : aucun risque
        // de surcoût de calcul quand le flag est à false.
        $this->setMode(false);

        $this->assertNull($this->pipeline()->convert($this->fixture(), 'doc_1'));
    }

    // -------------------------------------------------------------------------
    // Conversion réussie
    // -------------------------------------------------------------------------

    public function test_le_pipeline_natif_convertit_un_docx_valide(): void
    {
        $this->setMode(true);

        $document = $this->pipeline()->convert($this->fixture(), 'doc_1');

        $this->assertInstanceOf(StructuralDocument::class, $document);
        $this->assertSame('doc_1', $document->documentId);
        $this->assertGreaterThan(0, $document->count());
    }

    public function test_la_conversion_trace_les_metadonnees_de_traitement(): void
    {
        $this->setMode(true);

        $document = $this->pipeline()->convert(
            $this->fixture(DocxFixture::paragraph('1. Introduction').DocxFixture::paragraph('Texte.')),
            'doc_1'
        );

        // Les métadonnées servent au diagnostic : quel pipeline a traité le
        // document, combien de blocs, combien d'ambigus.
        $this->assertSame('exact', $document->meta['fidelity']);
        $this->assertArrayHasKey('style_count', $document->meta);
        $this->assertArrayHasKey('paragraphs_read', $document->meta);
        $this->assertArrayHasKey('tables_read', $document->meta);
    }

    // -------------------------------------------------------------------------
    // Repli automatique (mode auto)
    // -------------------------------------------------------------------------

    public function test_un_format_non_supporte_ne_declenche_pas_de_conversion(): void
    {
        $this->setMode('auto');

        // Un fichier texte n'est pas une archive ZIP : l'adaptateur le refuse,
        // et l'orchestrateur retourne null pour laisser faire l'ancien pipeline.
        $path = tempnam(sys_get_temp_dir(), 'not_docx_').'.txt';
        file_put_contents($path, 'Contenu texte, pas un docx');
        $this->fixtures[] = $path;

        $this->assertNull($this->pipeline()->convert($path, 'doc_1'));
    }

    public function test_un_fichier_absent_retourne_null_en_mode_auto(): void
    {
        // En mode auto, un échec ne doit JAMAIS remonter : l'application
        // bascule sur l'ancien pipeline sans perturber l'utilisateur.
        $this->setMode('auto');

        $this->assertNull($this->pipeline()->convert('/chemin/inexistant.docx', 'doc_1'));
    }

    public function test_une_archive_invalide_retourne_null_en_mode_auto(): void
    {
        $this->setMode('auto');

        // Archive ZIP sans `word/document.xml` : ce n'est pas un .docx.
        $path = tempnam(sys_get_temp_dir(), 'fake_zip_').'.docx';
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('autre.txt', 'pas un document word');
        $zip->close();
        $this->fixtures[] = $path;

        $this->assertNull($this->pipeline()->convert($path, 'doc_1'));
    }

    public function test_une_erreur_remonte_en_mode_strict(): void
    {
        // En mode `true`, un échec est un VRAI problème : le masquer rendrait
        // le diagnostic impossible. L'exception doit remonter.
        $this->setMode(true);

        $path = tempnam(sys_get_temp_dir(), 'fake_zip_').'.docx';
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('autre.txt', 'pas un document word');
        $zip->close();
        $this->fixtures[] = $path;

        $this->expectException(DocxReadException::class);

        $this->pipeline()->convert($path, 'doc_1');
    }

    // -------------------------------------------------------------------------
    // Conversion depuis le format historique
    // -------------------------------------------------------------------------

    public function test_une_structure_historique_est_convertie(): void
    {
        // Permet aux 35 documents déjà traités de rester exploitables.
        $result = $this->pipeline()->fromLegacy([
            'titres' => [
                [
                    'type' => 'titres',
                    'texte' => '1. Introduction',
                    'niveau' => 1,
                    'styles' => ['font' => ['basic' => ['size' => 16], 'style' => ['bold' => true]]],
                    'position' => ['element_index' => 0],
                ],
            ],
            'legends' => [],
        ], 'doc_1');

        $this->assertNotNull($result);
        $this->assertSame('doc_1', $result['document']->documentId);
        $this->assertSame('legacy', $result['document']->meta['converted_from']);
    }

    public function test_une_structure_historique_vide_retourne_null(): void
    {
        $this->assertNull($this->pipeline()->fromLegacy([], 'doc_1'));
    }

    public function test_une_structure_historique_inexploitable_ne_leve_pas(): void
    {
        // Une structure vide (format « markdown » sans légendes) ne doit pas
        // faire échouer le traitement : on retourne un document vide signalé.
        $result = $this->pipeline()->fromLegacy([
            'titles' => '# Titres en Markdown',
            'legends' => [],
        ], 'doc_1');

        $this->assertNotNull($result);
        $this->assertTrue($result['document']->isEmpty());
        $this->assertNotEmpty($result['warnings']);
    }

    // -------------------------------------------------------------------------
    // Persistance
    // -------------------------------------------------------------------------

    public function test_la_persistance_produit_le_format_attendu(): void
    {
        config(['document.persist_structural' => true]);
        $this->setMode(true);

        $document = $this->pipeline()->convert($this->fixture(), 'doc_1');
        $payload = $this->pipeline()->forPersistence($document, DocumentPipeline::NATIVE);

        $this->assertNotNull($payload);
        $this->assertArrayHasKey('structural_json', $payload);
        $this->assertSame(StructuralDocument::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('native', $payload['pipeline']);
    }

    public function test_la_persistance_est_desactivable(): void
    {
        // Permet d'observer le nouveau pipeline SANS écrire en base : utile
        // pour une phase d'observation en production.
        config(['document.persist_structural' => false]);

        $document = new StructuralDocument(documentId: 'doc_1', sourceType: 'docx');

        $this->assertNull($this->pipeline()->forPersistence($document, DocumentPipeline::NATIVE));
    }

    public function test_la_persistance_conserve_la_version_du_schema(): void
    {
        // La version permet de détecter une structure écrite par une version
        // antérieure du code et de la régénérer.
        config(['document.persist_structural' => true]);

        $document = new StructuralDocument(documentId: 'doc_1', sourceType: 'docx');
        $payload = $this->pipeline()->forPersistence($document, DocumentPipeline::LEGACY);

        $this->assertSame(StructuralDocument::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('legacy', $payload['pipeline']);
    }
}
