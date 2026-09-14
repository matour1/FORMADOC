<?php

declare(strict_types=1);

namespace Tests\Unit\Document\Editing;

use App\Document\Editing\EditLock;
use App\Document\Editing\EditOrchestrator;
use App\Document\Editing\ToolWhitelist;
use App\Document\Structure\Block;
use App\Document\Structure\BlockCategory;
use App\Document\Structure\BlockType;
use App\Document\Structure\StructuralDocument;
use App\Document\Structure\TableData;
use App\Models\Document;
use App\Models\DocumentEditLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests de l'orchestrateur d'édition : les six garde-fous du §9, combinés.
 *
 * Ce niveau est le plus important : chaque tool pris isolément peut être correct
 * alors que la chaîne complète laisse passer une action dangereuse. Les critères
 * d'acceptation de R6 sont donc vérifiés ici.
 */
class EditOrchestratorTest extends TestCase
{
    use RefreshDatabase;

    private function documentEnBase(): Document
    {
        return Document::create([
            'filename' => 'rapport.docx',
            'path' => 'documents/rapport.docx',
            'status' => 'detected',
            'metadata' => ['user_id' => null],
        ]);
    }

    private function paragraphe(string $id, string $text = 'Texte'): Block
    {
        return new Block(blockId: $id, type: BlockType::Paragraph, text: $text);
    }

    private function tableau(string $id): Block
    {
        return new Block(
            blockId: $id,
            type: BlockType::Table,
            tableData: TableData::fromGrid([['A', 'B'], ['C', 'D']]),
        );
    }

    private function legende(string $id, string $text = 'Tableau 1 : Bilan', ?string $linked = null): Block
    {
        return new Block(
            blockId: $id,
            type: BlockType::Caption,
            text: $text,
            category: BlockCategory::Table,
            originalNumber: '1',
            linkedBlockId: $linked,
        );
    }

    private function document(array $blocks, string $id = '1'): StructuralDocument
    {
        return new StructuralDocument(documentId: $id, sourceType: 'docx', blocks: $blocks);
    }

    // -------------------------------------------------------------------------
    // Critère d'acceptation : tool hors liste blanche rejeté (§9.14)
    // -------------------------------------------------------------------------

    public function test_un_tool_hors_liste_blanche_est_rejete(): void
    {
        $doc = $this->documentEnBase();

        $resultat = (new EditOrchestrator)->apply(
            $this->document([$this->paragraphe('b_0001')]),
            $doc->id,
            'drop_database',
            ['block_id' => 'b_0001'],
        );

        $this->assertFalse($resultat['applied']);
        $this->assertStringContainsString('non autorisé', (string) $resultat['error']);
    }

    public function test_un_tool_inconnu_ne_modifie_rien(): void
    {
        $doc = $this->documentEnBase();
        $document = $this->document([$this->paragraphe('b_0001', 'Origine')]);

        $resultat = (new EditOrchestrator)->apply($document, $doc->id, 'tool_inexistant', []);

        $this->assertSame('Origine', $resultat['document']->blockById('b_0001')->text);
        $this->assertSame(1, $resultat['document']->count());
    }

    public function test_ask_user_clarification_n_est_pas_un_tool_d_edition(): void
    {
        // Il est partagé avec la classification : il ne modifie pas le document.
        $doc = $this->documentEnBase();

        $resultat = (new EditOrchestrator)->apply(
            $this->document([$this->paragraphe('b_0001')]),
            $doc->id,
            'ask_user_clarification',
            [],
        );

        $this->assertFalse($resultat['applied']);
    }

    public function test_la_liste_blanche_expose_les_cinq_tools(): void
    {
        $this->assertCount(5, ToolWhitelist::editingToolNames());
        $this->assertContains('ask_user_clarification', ToolWhitelist::names());
    }

    // -------------------------------------------------------------------------
    // Critère d'acceptation : snapshot obligatoire (§9.7)
    // -------------------------------------------------------------------------

    public function test_une_action_destructive_cree_un_snapshot(): void
    {
        $doc = $this->documentEnBase();

        $resultat = (new EditOrchestrator)->apply(
            $this->document([$this->paragraphe('b_0001'), $this->paragraphe('b_0002')]),
            $doc->id,
            'delete_block',
            ['block_id' => 'b_0001'],
            'chat:1',
            true,
        );

        $this->assertTrue($resultat['applied']);
        $this->assertNotNull($resultat['snapshot_id']);
    }

    public function test_une_action_non_destructive_ne_cree_pas_de_snapshot(): void
    {
        // Un snapshot inutile consommerait du stockage sans bénéfice.
        $doc = $this->documentEnBase();

        $resultat = (new EditOrchestrator)->apply(
            $this->document([$this->paragraphe('b_0001')]),
            $doc->id,
            'rewrite_paragraph',
            ['block_id' => 'b_0001', 'text' => 'Nouveau'],
        );

        $this->assertTrue($resultat['applied']);
        $this->assertNull($resultat['snapshot_id']);
    }

    public function test_l_etat_anterieur_est_restaurable_apres_une_suppression(): void
    {
        // C'est le bénéfice concret du snapshot : l'utilisateur peut annuler.
        $doc = $this->documentEnBase();
        $orchestrateur = new EditOrchestrator;

        $resultat = $orchestrateur->apply(
            $this->document([
                $this->paragraphe('b_0001', 'À garder'),
                $this->paragraphe('b_0002', 'À supprimer'),
            ]),
            $doc->id,
            'delete_block',
            ['block_id' => 'b_0002'],
            'chat:1',
            true,
        );

        $this->assertSame(1, $resultat['document']->count());

        $restaure = $orchestrateur->undo($doc->id);

        $this->assertNotNull($restaure);
        $this->assertSame(2, $restaure['document']->count());
        $this->assertSame('À supprimer', $restaure['document']->blockById('b_0002')->text);
    }

    public function test_l_historique_des_annulations_est_expose(): void
    {
        $doc = $this->documentEnBase();
        $orchestrateur = new EditOrchestrator;

        $orchestrateur->apply(
            $this->document([$this->paragraphe('b_0001'), $this->paragraphe('b_0002')]),
            $doc->id,
            'delete_block',
            ['block_id' => 'b_0001'],
            'chat:1',
            true,
        );

        $historique = $orchestrateur->undoHistory($doc->id);

        $this->assertCount(1, $historique);
        $this->assertSame('delete_block', $historique[0]['tool']);
        $this->assertSame(2, $historique[0]['block_count']);
    }

    // -------------------------------------------------------------------------
    // Critère d'acceptation : verrou (§9.8)
    // -------------------------------------------------------------------------

    public function test_un_second_processus_est_bloque(): void
    {
        $doc = $this->documentEnBase();
        $orchestrateur = new EditOrchestrator;

        // Un autre processus détient le verrou.
        (new EditLock)->acquire($doc->id, 'pipeline', 'traitement automatique');

        $resultat = $orchestrateur->apply(
            $this->document([$this->paragraphe('b_0001')]),
            $doc->id,
            'rewrite_paragraph',
            ['block_id' => 'b_0001', 'text' => 'Nouveau'],
            'chat:1',
        );

        $this->assertFalse($resultat['applied']);
        $this->assertStringContainsString('cours de modification', (string) $resultat['error']);
    }

    public function test_le_verrou_est_libere_apres_application(): void
    {
        $doc = $this->documentEnBase();

        (new EditOrchestrator)->apply(
            $this->document([$this->paragraphe('b_0001')]),
            $doc->id,
            'rewrite_paragraph',
            ['block_id' => 'b_0001', 'text' => 'Nouveau'],
            'chat:1',
        );

        $this->assertNull(DocumentEditLock::where('document_id', $doc->id)->first());
    }

    public function test_le_verrou_est_libere_meme_en_cas_d_echec(): void
    {
        // Un échec ponctuel ne doit pas bloquer le document durablement.
        $doc = $this->documentEnBase();

        (new EditOrchestrator)->apply(
            $this->document([$this->paragraphe('b_0001')]),
            $doc->id,
            'rewrite_paragraph',
            ['block_id' => 'b_9999', 'text' => 'Nouveau'],
            'chat:1',
        );

        $this->assertNull(DocumentEditLock::where('document_id', $doc->id)->first());
    }

    // -------------------------------------------------------------------------
    // Critère d'acceptation : validation de sortie (§9.9)
    // -------------------------------------------------------------------------

    public function test_des_arguments_invalides_sont_rejetes_avec_une_raison(): void
    {
        $doc = $this->documentEnBase();

        $resultat = (new EditOrchestrator)->apply(
            $this->document([$this->paragraphe('b_0001')]),
            $doc->id,
            'rewrite_paragraph',
            [],  // block_id manquant
        );

        $this->assertFalse($resultat['applied']);
        $this->assertStringContainsString('block_id', (string) $resultat['error']);
    }

    public function test_un_type_de_bloc_inconnu_est_rejete(): void
    {
        $doc = $this->documentEnBase();

        $resultat = (new EditOrchestrator)->apply(
            $this->document([$this->paragraphe('b_0001')]),
            $doc->id,
            'insert_block',
            ['position_block_id' => 'b_0001', 'type' => 'video'],
        );

        $this->assertFalse($resultat['applied']);
        $this->assertStringContainsString('video', (string) $resultat['error']);
    }

    public function test_un_echec_du_tool_renvoie_l_erreur_sans_modifier_le_document(): void
    {
        $doc = $this->documentEnBase();

        $resultat = (new EditOrchestrator)->apply(
            $this->document([$this->paragraphe('b_0001', 'Origine')]),
            $doc->id,
            'rewrite_paragraph',
            ['block_id' => 'b_9999', 'text' => 'Nouveau'],
        );

        $this->assertFalse($resultat['applied']);
        $this->assertSame('Origine', $resultat['document']->blockById('b_0001')->text);
    }

    // -------------------------------------------------------------------------
    // Critère d'acceptation : confirmation des actions larges (§9.16)
    // -------------------------------------------------------------------------

    public function test_delete_block_d_un_seul_bloc_passe(): void
    {
        // Scénario attendu : « un delete_block sur 1 bloc passe ».
        $doc = $this->documentEnBase();

        $resultat = (new EditOrchestrator)->apply(
            $this->document([$this->paragraphe('b_0001'), $this->paragraphe('b_0002')]),
            $doc->id,
            'delete_block',
            ['block_id' => 'b_0001'],
            'chat:1',
            /** confirmed */ true,
        );

        $this->assertTrue($resultat['applied']);
        $this->assertFalse($resultat['confirmation_required']);
    }

    public function test_regenerate_section_sur_six_blocs_demande_confirmation(): void
    {
        // Scénario attendu : « regenerate_section sur 6 blocs demande confirmation ».
        $doc = $this->documentEnBase();

        $blocs = [];
        for ($i = 1; $i <= 8; $i++) {
            $blocs[] = $this->paragraphe(sprintf('b_%04d', $i));
        }

        $resultat = (new EditOrchestrator)->apply(
            $this->document($blocs),
            $doc->id,
            'regenerate_section',
            [
                'start_block_id' => 'b_0001',
                'end_block_id' => 'b_0006',
                'content' => ['Nouveau'],
            ],
            'chat:1',
            /** confirmed */ false,
        );

        $this->assertFalse($resultat['applied']);
        $this->assertTrue($resultat['confirmation_required']);
        $this->assertSame(6, $resultat['details']['affected_blocks']);
        // Rien n'a été modifié : le document est intact.
        $this->assertSame(8, $resultat['document']->count());
    }

    public function test_regenerate_section_sur_cinq_blocs_ne_demande_pas_confirmation(): void
    {
        // Le seuil est de 5 : cinq blocs passent, six demandent confirmation.
        $doc = $this->documentEnBase();

        $blocs = [];
        for ($i = 1; $i <= 8; $i++) {
            $blocs[] = $this->paragraphe(sprintf('b_%04d', $i));
        }

        $resultat = (new EditOrchestrator)->apply(
            $this->document($blocs),
            $doc->id,
            'regenerate_section',
            [
                'start_block_id' => 'b_0001',
                'end_block_id' => 'b_0005',
                'content' => ['Nouveau'],
            ],
            'chat:1',
        );

        $this->assertTrue($resultat['applied']);
        $this->assertFalse($resultat['confirmation_required']);
    }

    public function test_une_action_confirmee_s_applique(): void
    {
        $doc = $this->documentEnBase();

        $blocs = [];
        for ($i = 1; $i <= 8; $i++) {
            $blocs[] = $this->paragraphe(sprintf('b_%04d', $i));
        }

        $resultat = (new EditOrchestrator)->apply(
            $this->document($blocs),
            $doc->id,
            'regenerate_section',
            [
                'start_block_id' => 'b_0001',
                'end_block_id' => 'b_0006',
                'content' => ['Nouveau'],
            ],
            'chat:1',
            /** confirmed */ true,
        );

        $this->assertTrue($resultat['applied']);
        // 6 blocs remplacés par 1 + les 2 restants.
        $this->assertSame(3, $resultat['document']->count());
    }

    public function test_le_seuil_de_confirmation_est_verifie_par_la_liste_blanche(): void
    {
        // §9.16 : « confirmation si delete_block ou regenerate_section > 5 blocs ».
        // Cinq blocs passent, six demandent confirmation.
        $this->assertFalse(ToolWhitelist::requiresConfirmation('delete_block', 5));
        $this->assertTrue(ToolWhitelist::requiresConfirmation('delete_block', 6));
        $this->assertFalse(ToolWhitelist::requiresConfirmation('regenerate_section', 5));
        $this->assertTrue(ToolWhitelist::requiresConfirmation('regenerate_section', 6));

        // Les tools non destructifs ne demandent jamais confirmation.
        $this->assertFalse(ToolWhitelist::requiresConfirmation('rewrite_paragraph', 100));
        $this->assertFalse(ToolWhitelist::requiresConfirmation('insert_block', 100));
    }

    // -------------------------------------------------------------------------
    // Critère d'acceptation : renumérotation automatique (§9.10)
    // -------------------------------------------------------------------------

    public function test_une_suppression_de_legende_declenche_la_renumerotation(): void
    {
        // Supprimer une légende transforme le tableau qu'elle décrivait en
        // élément autonome : il consomme alors SON PROPRE numéro au lieu de
        // refléter celui de la légende. Les compteurs changent, donc la
        // renumérotation est nécessaire.
        $doc = $this->documentEnBase();

        $resultat = (new EditOrchestrator)->apply(
            $this->document([
                $this->paragraphe('b_0001'),
                $this->tableau('b_0002'),
                $this->legende('b_0003', 'Tableau 1 : A', 'b_0002'),
                $this->tableau('b_0004'),
                $this->legende('b_0005', 'Tableau 2 : B', 'b_0004'),
            ]),
            $doc->id,
            'delete_block',
            ['block_id' => 'b_0003'],
            'chat:1',
            true,
        );

        $this->assertTrue($resultat['applied']);
        $this->assertTrue($resultat['renumbered']);

        $document = $resultat['document'];

        // Deux tableaux, deux numéros : la série reste contiguë et sans doublon,
        // ce qui est l'objectif de la renumérotation.
        $this->assertSame('1', $document->blockById('b_0002')->displayNumber());
        $this->assertSame('2', $document->blockById('b_0005')->displayNumber());
        $this->assertSame('2', $document->blockById('b_0004')->displayNumber());
    }

    public function test_une_reecriture_ne_declenche_pas_de_renumerotation(): void
    {
        // Réécrire un paragraphe ne change aucun compteur : renuméroter serait
        // un travail inutile (et un rapport de changements trompeur).
        $doc = $this->documentEnBase();

        $resultat = (new EditOrchestrator)->apply(
            $this->document([$this->paragraphe('b_0001'), $this->paragraphe('b_0002')]),
            $doc->id,
            'rewrite_paragraph',
            ['block_id' => 'b_0001', 'text' => 'Nouveau'],
        );

        $this->assertTrue($resultat['applied']);
        $this->assertFalse($resultat['renumbered']);
    }

    public function test_une_insertion_de_tableau_declenche_la_renumerotation(): void
    {
        $doc = $this->documentEnBase();

        $resultat = (new EditOrchestrator)->apply(
            $this->document([$this->paragraphe('b_0001'), $this->tableau('b_0002')]),
            $doc->id,
            'insert_block',
            [
                'position_block_id' => 'b_0001',
                'type' => 'table',
                'rows' => [['X'], ['Y']],
            ],
        );

        $this->assertTrue($resultat['applied']);
        $this->assertTrue($resultat['renumbered']);
    }

    // -------------------------------------------------------------------------
    // Forme de la réponse
    // -------------------------------------------------------------------------

    public function test_la_reponse_a_une_forme_constante(): void
    {
        // L'appelant (le chat) traite la réponse sans deviner sa structure :
        // succès et échec exposent les mêmes clés.
        $doc = $this->documentEnBase();
        $orchestrateur = new EditOrchestrator;

        $succes = $orchestrateur->apply(
            $this->document([$this->paragraphe('b_0001')]),
            $doc->id,
            'rewrite_paragraph',
            ['block_id' => 'b_0001', 'text' => 'Nouveau'],
        );

        $echec = $orchestrateur->apply(
            $this->document([$this->paragraphe('b_0001')]),
            $doc->id,
            'tool_inexistant',
            [],
        );

        $this->assertSame(array_keys($succes), array_keys($echec));
    }

    public function test_le_resume_decrit_l_action_effectuee(): void
    {
        $doc = $this->documentEnBase();

        $resultat = (new EditOrchestrator)->apply(
            $this->document([$this->paragraphe('b_0001')]),
            $doc->id,
            'rewrite_paragraph',
            ['block_id' => 'b_0001', 'text' => 'Nouveau'],
        );

        $this->assertStringContainsString('b_0001', $resultat['summary']);
    }
}
