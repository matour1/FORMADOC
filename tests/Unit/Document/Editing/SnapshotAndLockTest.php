<?php

declare(strict_types=1);

namespace Tests\Unit\Document\Editing;

use App\Document\Editing\EditingException;
use App\Document\Editing\EditLock;
use App\Document\Editing\SnapshotManager;
use App\Document\Structure\Block;
use App\Document\Structure\BlockType;
use App\Document\Structure\StructuralDocument;
use App\Models\Document;
use App\Models\DocumentEditLock;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests des garde-fous d'édition : snapshots (§9.7) et verrou (§9.8).
 *
 * Ces deux mécanismes protègent contre les deux façons de perdre du travail :
 * une action irréversible (snapshot) et deux traitements concurrents (verrou).
 * Ils sont donc testés avec la base réelle, sans mock — c'est la contrainte
 * d'unicité en base qui garantit le verrou, un mock ne la testerait pas.
 */
class SnapshotAndLockTest extends TestCase
{
    use RefreshDatabase;

    private function document(int $blocks = 3): StructuralDocument
    {
        $blocs = [];

        for ($i = 1; $i <= $blocks; $i++) {
            $blocs[] = new Block(
                blockId: sprintf('b_%04d', $i),
                type: BlockType::Paragraph,
                text: "Paragraphe {$i}",
            );
        }

        return new StructuralDocument(documentId: 'doc-1', sourceType: 'docx', blocks: $blocs);
    }

    /**
     * Crée un document en base, dont l'identifiant sert aux snapshots et verrous.
     */
    private function documentEnBase(): Document
    {
        return Document::create([
            'filename' => 'rapport.docx',
            'path' => 'documents/rapport.docx',
            'status' => 'detected',
            'metadata' => ['user_id' => null],
        ]);
    }

    // -------------------------------------------------------------------------
    // Snapshots (§9.7)
    // -------------------------------------------------------------------------

    public function test_un_snapshot_est_enregistre_avec_son_contenu(): void
    {
        $doc = $this->documentEnBase();

        // L'identifiant du document structurel doit correspondre à la ligne en
        // base : c'est ce qui rattache le snapshot (clé étrangère) au document.
        $document = new StructuralDocument(
            documentId: (string) $doc->id,
            sourceType: 'docx',
            blocks: [
                new Block(blockId: 'b_0001', type: BlockType::Paragraph, text: 'Un'),
                new Block(blockId: 'b_0002', type: BlockType::Paragraph, text: 'Deux'),
                new Block(blockId: 'b_0003', type: BlockType::Paragraph, text: 'Trois'),
            ],
        );

        $snapshot = (new SnapshotManager)->capture($document, 'delete_block', ['tool' => 'delete_block']);

        $this->assertNotNull($snapshot->id);
        $this->assertSame(3, $snapshot->block_count);
        $this->assertSame('delete_block', $snapshot->tool());
    }

    public function test_un_snapshot_permet_de_restaurer_l_etat_anterieur(): void
    {
        // C'est la propriété qui compte : l'annulation doit rendre le document
        // exactement tel qu'il était.
        $doc = $this->documentEnBase();

        $document = new StructuralDocument(
            documentId: (string) $doc->id,
            sourceType: 'docx',
            blocks: [
                new Block(blockId: 'b_0001', type: BlockType::Paragraph, text: 'À conserver'),
                new Block(blockId: 'b_0002', type: BlockType::Paragraph, text: 'À supprimer'),
            ],
        );

        $gestionnaire = new SnapshotManager;
        $gestionnaire->capture($document, 'avant suppression');

        // Simulation de la suppression.
        $apres = $document->removeBlock('b_0002');
        $this->assertSame(1, $apres->count());

        $restaure = $gestionnaire->restore($doc->id);

        $this->assertNotNull($restaure);
        $this->assertSame(2, $restaure['document']->count());
        $this->assertSame('À supprimer', $restaure['document']->blockById('b_0002')->text);
    }

    public function test_la_restauration_conserve_le_contenu_a_l_identique(): void
    {
        $doc = $this->documentEnBase();

        $document = new StructuralDocument(
            documentId: (string) $doc->id,
            sourceType: 'docx',
            blocks: [
                new Block(blockId: 'b_0001', type: BlockType::Heading, text: 'Chapitre — accents éàü', headingLevel: 2),
                new Block(blockId: 'b_0002', type: BlockType::Paragraph, text: 'Montant : 1 725 000 FCFA'),
            ],
        );

        $gestionnaire = new SnapshotManager;
        $gestionnaire->capture($document, 'test');

        $restaure = $gestionnaire->restore($doc->id);

        $this->assertSame('Chapitre — accents éàü', $restaure['document']->blockById('b_0001')->text);
        $this->assertSame('Montant : 1 725 000 FCFA', $restaure['document']->blockById('b_0002')->text);
        $this->assertSame(2, $restaure['document']->blockById('b_0001')->headingLevel);
    }

    public function test_l_historique_est_rendu_du_plus_recent_au_plus_ancien(): void
    {
        $doc = $this->documentEnBase();
        $gestionnaire = new SnapshotManager;

        $gestionnaire->capture(new StructuralDocument((string) $doc->id, 'docx', [
            new Block(blockId: 'b_0001', type: BlockType::Paragraph, text: 'Premier'),
        ]), 'premier');

        $gestionnaire->capture(new StructuralDocument((string) $doc->id, 'docx', [
            new Block(blockId: 'b_0001', type: BlockType::Paragraph, text: 'Second'),
        ]), 'second');

        $historique = $gestionnaire->history($doc->id);

        $this->assertCount(2, $historique);
        $this->assertSame('second', $historique[0]->reason);
    }

    public function test_la_restauration_du_dernier_snapshot_est_celle_attendue(): void
    {
        $doc = $this->documentEnBase();
        $gestionnaire = new SnapshotManager;

        $gestionnaire->capture(new StructuralDocument((string) $doc->id, 'docx', [
            new Block(blockId: 'b_0001', type: BlockType::Paragraph, text: 'Vieux'),
        ]), 'vieux');

        $gestionnaire->capture(new StructuralDocument((string) $doc->id, 'docx', [
            new Block(blockId: 'b_0001', type: BlockType::Paragraph, text: 'Récent'),
        ]), 'récent');

        $this->assertSame('Récent', $gestionnaire->restore($doc->id)['document']->blockById('b_0001')->text);
    }

    public function test_has_snapshot_distingue_les_documents(): void
    {
        $doc = $this->documentEnBase();
        $autre = $this->documentEnBase();
        $gestionnaire = new SnapshotManager;

        $this->assertFalse($gestionnaire->hasSnapshot($doc->id));

        $gestionnaire->capture(new StructuralDocument((string) $doc->id, 'docx', [
            new Block(blockId: 'b_0001', type: BlockType::Paragraph, text: 'Contenu'),
        ]), 'test');

        // Le snapshot est rattaché à UN document : l'autre ne doit pas le voir.
        $this->assertTrue($gestionnaire->hasSnapshot($doc->id));
        $this->assertFalse($gestionnaire->hasSnapshot($autre->id));
    }

    public function test_forget_supprime_l_historique(): void
    {
        $doc = $this->documentEnBase();
        $gestionnaire = new SnapshotManager;

        $gestionnaire->capture(new StructuralDocument((string) $doc->id, 'docx', []), 'test');
        $this->assertNotNull($gestionnaire->latest($doc->id));

        $gestionnaire->forget($doc->id);

        $this->assertNull($gestionnaire->latest($doc->id));
    }

    public function test_restaurer_sans_snapshot_retourne_null(): void
    {
        // L'appelant doit pouvoir distinguer « rien à annuler » d'une erreur.
        $doc = $this->documentEnBase();

        $this->assertNull((new SnapshotManager)->restore($doc->id));
    }

    // -------------------------------------------------------------------------
    // Verrou d'édition (§9.8)
    // -------------------------------------------------------------------------

    public function test_un_premier_processus_acquiert_le_verrou(): void
    {
        $doc = $this->documentEnBase();
        $verrou = new EditLock;

        $this->assertNull($verrou->acquire($doc->id, 'chat:1', 'édition'));

        $this->assertNotNull(DocumentEditLock::where('document_id', $doc->id)->first());
    }

    public function test_un_second_processus_est_bloque(): void
    {
        // Critère d'acceptation R6.8 : « deux processus concurrents : le second
        // est bloqué par le verrou ».
        $doc = $this->documentEnBase();
        $verrou = new EditLock;

        $verrou->acquire($doc->id, 'chat:1', 'édition en cours');
        $blocage = $verrou->acquire($doc->id, 'pipeline', 'traitement');

        $this->assertNotNull($blocage);
        $this->assertSame('édition en cours', $blocage);
    }

    public function test_le_meme_processus_peut_rafraichir_son_verrou(): void
    {
        // Un traitement long ne doit pas expirer en cours de route.
        $doc = $this->documentEnBase();
        $verrou = new EditLock;

        $verrou->acquire($doc->id, 'chat:1', 'début');
        $this->assertNull($verrou->acquire($doc->id, 'chat:1', 'suite'));
    }

    public function test_seul_le_proprietaire_libere_le_verrou(): void
    {
        $doc = $this->documentEnBase();
        $verrou = new EditLock;

        $verrou->acquire($doc->id, 'chat:1');

        // Un autre processus ne doit pas relâcher le verrou : le propriétaire
        // croirait travailler en exclusivité alors que ce n'est plus le cas.
        $this->assertFalse($verrou->release($doc->id, 'pipeline'));
        $this->assertTrue($verrou->release($doc->id, 'chat:1'));
    }

    public function test_un_verrou_abandonne_est_libere(): void
    {
        // Un processus tué ne doit pas bloquer le document pour toujours.
        $doc = $this->documentEnBase();

        DocumentEditLock::create([
            'document_id' => $doc->id,
            'owner' => 'processus-mort',
            'reason' => 'interrompu',
            'acquired_at' => now()->subSeconds(EditLock::STALE_AFTER + 10),
        ]);

        $verrou = new EditLock;
        $blocage = $verrou->acquire($doc->id, 'chat:1', 'nouvelle tentative');

        $this->assertNull($blocage);
        $this->assertSame('chat:1', DocumentEditLock::where('document_id', $doc->id)->first()->owner);
    }

    public function test_un_verrou_recent_n_est_pas_considere_comme_abandonne(): void
    {
        $doc = $this->documentEnBase();

        DocumentEditLock::create([
            'document_id' => $doc->id,
            'owner' => 'chat:1',
            'acquired_at' => now()->subSeconds(5),
        ]);

        $this->assertTrue((new EditLock)->isLockedByOther($doc->id, 'chat:2'));
    }

    public function test_with_lock_libere_le_verrou_apres_l_action(): void
    {
        $doc = $this->documentEnBase();
        $verrou = new EditLock;

        $resultat = $verrou->withLock($doc->id, 'chat:1', static fn (): string => 'fait');

        $this->assertSame('fait', $resultat);
        $this->assertNull(DocumentEditLock::where('document_id', $doc->id)->first());
    }

    public function test_with_lock_libere_le_verrou_meme_en_cas_d_exception(): void
    {
        // Point critique : une exception ne doit pas laisser le document
        // verrouillé, sinon un échec ponctuel bloquerait le document jusqu'à
        // l'expiration du verrou.
        $doc = $this->documentEnBase();
        $verrou = new EditLock;

        try {
            $verrou->withLock($doc->id, 'chat:1', static function (): void {
                throw new \RuntimeException('échec simulé');
            });

            $this->fail('Une exception était attendue.');
        } catch (\RuntimeException) {
            // attendu
        }

        $this->assertNull(DocumentEditLock::where('document_id', $doc->id)->first());
    }

    public function test_with_lock_refuse_si_un_autre_processus_detient_le_verrou(): void
    {
        $doc = $this->documentEnBase();

        (new EditLock)->acquire($doc->id, 'pipeline', 'traitement automatique');

        $this->expectException(EditingException::class);
        $this->expectExceptionMessage('traitement automatique');

        (new EditLock)->withLock($doc->id, 'chat:1', static fn (): string => 'jamais exécuté', 'édition');
    }

    public function test_force_release_libere_quel_que_soit_le_proprietaire(): void
    {
        $doc = $this->documentEnBase();

        (new EditLock)->acquire($doc->id, 'chat:1');

        $this->assertTrue((new EditLock)->forceRelease($doc->id));
        $this->assertNull((new EditLock)->current($doc->id));
    }

    public function test_current_ignore_un_verrou_abandonne(): void
    {
        $doc = $this->documentEnBase();

        DocumentEditLock::create([
            'document_id' => $doc->id,
            'owner' => 'mort',
            'acquired_at' => now()->subSeconds(EditLock::STALE_AFTER + 1),
        ]);

        $this->assertNull((new EditLock)->current($doc->id));
    }

    public function test_un_document_ne_peut_avoir_qu_un_seul_verrou(): void
    {
        // La garantie est structurelle (contrainte d'unicité en base), pas
        // seulement déclarative : c'est ce qui la rend non contournable.
        $doc = $this->documentEnBase();

        DocumentEditLock::create([
            'document_id' => $doc->id,
            'owner' => 'premier',
            'acquired_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        DocumentEditLock::create([
            'document_id' => $doc->id,
            'owner' => 'second',
            'acquired_at' => now(),
        ]);
    }

    public function test_le_modele_de_verrou_expose_son_age(): void
    {
        $doc = $this->documentEnBase();

        $verrou = DocumentEditLock::create([
            'document_id' => $doc->id,
            'owner' => 'chat:1',
            'reason' => 'test',
            'acquired_at' => now()->subSeconds(30),
        ]);

        $this->assertGreaterThanOrEqual(29, $verrou->ageInSeconds());
        $this->assertFalse($verrou->isStale());
        $this->assertSame('chat:1 — test', $verrou->label());
    }
}
