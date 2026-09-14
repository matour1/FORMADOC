<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Document\Editing\SnapshotManager;
use App\Document\Structure\Block;
use App\Document\Structure\BlockType;
use App\Document\Structure\StructuralDocument;
use App\Models\Document;
use App\Models\DocumentStructure;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests de l'interface d'annulation (tâche R6.17).
 *
 * Deux enjeux :
 *  - **sécurité** : l'annulation modifie le contenu d'un document, elle doit donc
 *    être soumise à la même vérification d'appartenance que le reste (la faille
 *    IDOR corrigée en début de refonte visait exactement ce type de route) ;
 *  - **affichage** : le bloc n'apparaît que s'il y a quelque chose à annuler, et
 *    il nomme chaque action pour que l'utilisateur sache ce qu'il restaure.
 */
class DocumentUndoEditTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Crée un utilisateur et un document qui lui appartient.
     *
     * @return array{0: User, 1: Document}
     */
    private function documentAvecStructure(bool $avecSnapshot = true): array
    {
        $user = User::create([
            'name' => 'Testeur',
            'email' => 'testeur_'.uniqid().'@example.com',
            'password' => bcrypt('password'),
        ]);

        $document = Document::create([
            'filename' => 'rapport.docx',
            'path' => 'documents/rapport.docx',
            'status' => 'detected',
            'metadata' => ['user_id' => $user->id],
        ]);

        $structural = new StructuralDocument(
            documentId: (string) $document->id,
            sourceType: 'docx',
            blocks: [
                new Block(blockId: 'b_0001', type: BlockType::Paragraph, text: 'Premier'),
                new Block(blockId: 'b_0002', type: BlockType::Paragraph, text: 'Second'),
            ],
        );

        DocumentStructure::create([
            'document_id' => $document->id,
            'structure' => [],
            'structural_json' => $structural->toArray(),
            'schema_version' => StructuralDocument::SCHEMA_VERSION,
            'pipeline' => 'native',
        ]);

        if ($avecSnapshot) {
            // Un snapshot, comme si une action destructive venait d'être faite.
            app(SnapshotManager::class)
                ->capture($structural, 'delete_block sur 1 bloc(s)', ['tool' => 'delete_block']);
        }

        return [$user, $document];
    }

    // -------------------------------------------------------------------------
    // Sécurité (faille IDOR)
    // -------------------------------------------------------------------------

    public function test_un_utilisateur_ne_peut_pas_annuler_sur_le_document_d_un_autre(): void
    {
        // L'annulation modifie le contenu : elle doit être protégée exactement
        // comme l'affichage. Un 404 (et non un 403) ne révèle pas l'existence du
        // document — c'est la convention retenue par ce projet.
        [$proprietaire, $document] = $this->documentAvecStructure();

        $intrus = User::create([
            'name' => 'Intrus',
            'email' => 'intrus_'.uniqid().'@example.com',
            'password' => bcrypt('password'),
        ]);

        $this->actingAs($intrus)
            ->post(route('documents.undo-edit', $document))
            ->assertNotFound();
    }

    public function test_un_visiteur_non_connecte_ne_peut_pas_annuler(): void
    {
        [, $document] = $this->documentAvecStructure();

        $this->post(route('documents.undo-edit', $document))
            ->assertRedirect(route('login'));
    }

    // -------------------------------------------------------------------------
    // Annulation
    // -------------------------------------------------------------------------

    public function test_le_proprietaire_peut_restaurer_le_dernier_etat(): void
    {
        [$user, $document] = $this->documentAvecStructure();

        // Le document courant a été modifié après le snapshot.
        $structure = DocumentStructure::where('document_id', $document->id)->first();
        $courant = $structure->structuralDocument()->removeBlock('b_0002');
        $structure->update(['structural_json' => $courant->toArray()]);

        $this->actingAs($user)
            ->post(route('documents.undo-edit', $document))
            ->assertRedirect();

        $restaure = DocumentStructure::where('document_id', $document->id)->first();
        $document2 = $restaure->structuralDocument();

        $this->assertSame(2, $document2->count());
        $this->assertSame('Second', $document2->blockById('b_0002')->text);
    }

    public function test_l_annulation_affiche_un_message_de_confirmation(): void
    {
        [$user, $document] = $this->documentAvecStructure();

        $this->actingAs($user)
            ->post(route('documents.undo-edit', $document))
            ->assertSessionHas('status');
    }

    public function test_annuler_sans_snapshot_affiche_une_erreur(): void
    {
        // Message d'erreur plutôt qu'un échec silencieux : l'utilisateur doit
        // comprendre qu'il n'y a rien à annuler.
        [$user, $document] = $this->documentAvecStructure(avecSnapshot: false);

        $this->actingAs($user)
            ->post(route('documents.undo-edit', $document))
            ->assertSessionHas('error');
    }

    public function test_un_snapshot_precis_peut_etre_restaure(): void
    {
        // L'interface propose plusieurs états : restaurer un état ancien doit
        // fonctionner, pas seulement le dernier.
        [$user, $document] = $this->documentAvecStructure();

        $gestionnaire = app(SnapshotManager::class);
        $ancien = $gestionnaire->latest($document->id);

        // Second état, plus récent.
        $structure = DocumentStructure::where('document_id', $document->id)->first();
        $modifie = $structure->structuralDocument()->removeBlock('b_0002');
        $gestionnaire->capture($modifie, 'rewrite_paragraph');

        $this->actingAs($user)
            ->post(route('documents.undo-edit', $document), ['snapshot_id' => $ancien->id])
            ->assertRedirect();

        $restaure = DocumentStructure::where('document_id', $document->id)->first()->structuralDocument();

        $this->assertSame(2, $restaure->count());
    }

    // -------------------------------------------------------------------------
    // Affichage
    // -------------------------------------------------------------------------

    public function test_la_page_n_affiche_pas_le_bloc_sans_historique(): void
    {
        // Un bloc « Annuler » vide laisserait croire à une fonctionnalité en
        // panne : mieux vaut ne rien afficher.
        [$user, $document] = $this->documentAvecStructure(avecSnapshot: false);

        $this->actingAs($user)
            ->get(route('documents.show', $document))
            ->assertOk()
            ->assertDontSee('Annuler une modification');
    }

    public function test_la_page_affiche_le_bloc_avec_un_historique(): void
    {
        [$user, $document] = $this->documentAvecStructure();

        $this->actingAs($user)
            ->get(route('documents.show', $document))
            ->assertOk()
            ->assertSee('Annuler une modification')
            ->assertSee('delete_block', false);
    }

    public function test_la_page_indique_que_l_annulation_remplace_le_contenu(): void
    {
        // L'utilisateur doit savoir que restaurer un état ancien écrase le
        // contenu actuel — et que l'annulation n'est pas elle-même annulable.
        [$user, $document] = $this->documentAvecStructure();

        $this->actingAs($user)
            ->get(route('documents.show', $document))
            ->assertOk()
            ->assertSee('remplace le contenu actuel');
    }

    public function test_la_page_liste_le_nombre_de_blocs_de_chaque_etat(): void
    {
        // Le nombre de blocs aide à choisir le bon état quand plusieurs existent.
        [$user, $document] = $this->documentAvecStructure();

        $this->actingAs($user)
            ->get(route('documents.show', $document))
            ->assertOk()
            ->assertSee('2 blocs');
    }
}
