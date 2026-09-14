<?php

declare(strict_types=1);

namespace Tests\Unit\Document\Editing;

use App\Document\Editing\EditingException;
use App\Document\Editing\Tools\DeleteBlockTool;
use App\Document\Editing\Tools\InsertBlockTool;
use App\Document\Editing\Tools\ModifyTableTool;
use App\Document\Editing\Tools\RegenerateSectionTool;
use App\Document\Editing\Tools\RewriteParagraphTool;
use App\Document\Structure\Block;
use App\Document\Structure\BlockCategory;
use App\Document\Structure\BlockType;
use App\Document\Structure\CrossRef;
use App\Document\Structure\StructuralDocument;
use App\Document\Structure\TableData;
use Tests\TestCase;

/**
 * Tests des cinq tools d'édition.
 *
 * Chaque tool doit faire **exactement** ce que sa description annonce, et refuser
 * explicitement ce qu'il ne peut pas faire. Le refus est aussi important que
 * l'action : un modèle qui réécrit un tableau perdrait des montants, et
 * l'utilisateur ne le verrait qu'après livraison.
 */
class EditToolsTest extends TestCase
{
    private function paragraph(string $id, string $text = 'Texte initial'): Block
    {
        return new Block(blockId: $id, type: BlockType::Paragraph, text: $text);
    }

    private function heading(string $id, string $text = 'Titre', int $level = 1): Block
    {
        return new Block(blockId: $id, type: BlockType::Heading, text: $text, headingLevel: $level);
    }

    private function table(string $id, array $grid = [['A', 'B'], ['C', 'D']]): Block
    {
        return new Block(
            blockId: $id,
            type: BlockType::Table,
            tableData: TableData::fromGrid($grid),
        );
    }

    private function caption(string $id, string $text = 'Figure 1 : Schéma', ?string $linked = null): Block
    {
        return new Block(
            blockId: $id,
            type: BlockType::Caption,
            text: $text,
            category: BlockCategory::Figure,
            linkedBlockId: $linked,
        );
    }

    private function document(array $blocks): StructuralDocument
    {
        return new StructuralDocument(documentId: '1', sourceType: 'docx', blocks: $blocks);
    }

    // -------------------------------------------------------------------------
    // rewrite_paragraph
    // -------------------------------------------------------------------------

    public function test_rewrite_paragraph_remplace_le_texte(): void
    {
        $resultat = (new RewriteParagraphTool)->apply(
            $this->document([$this->paragraph('b_0001')]),
            ['block_id' => 'b_0001', 'text' => 'Texte réécrit'],
        );

        $this->assertSame('Texte réécrit', $resultat['document']->blockById('b_0001')->text);
        $this->assertSame('Texte initial', $resultat['details']['before']);
    }

    public function test_rewrite_paragraph_refuse_un_tableau(): void
    {
        // Réécrire un tableau corromprait des données chiffrées (§15). Le refus
        // doit être explicite : l'utilisateur comprendra pourquoi.
        $this->expectException(EditingException::class);
        $this->expectExceptionMessage('structurante');

        (new RewriteParagraphTool)->apply(
            $this->document([$this->table('b_0001')]),
            ['block_id' => 'b_0001', 'text' => 'Nouveau'],
        );
    }

    public function test_rewrite_paragraph_refuse_une_legende(): void
    {
        $this->expectException(EditingException::class);

        (new RewriteParagraphTool)->apply(
            $this->document([$this->caption('b_0001')]),
            ['block_id' => 'b_0001', 'text' => 'Nouvelle légende'],
        );
    }

    public function test_rewrite_paragraph_accepte_un_titre(): void
    {
        $resultat = (new RewriteParagraphTool)->apply(
            $this->document([$this->heading('b_0001')]),
            ['block_id' => 'b_0001', 'text' => 'Nouveau titre'],
        );

        $this->assertSame('Nouveau titre', $resultat['document']->blockById('b_0001')->text);
    }

    public function test_rewrite_paragraph_refuse_un_texte_vide(): void
    {
        // Un texte vide viderait le bloc sans le supprimer : la confusion serait
        // visible au rendu, avec un paragraphe fantôme.
        $this->expectException(EditingException::class);
        $this->expectExceptionMessage('vide');

        (new RewriteParagraphTool)->apply(
            $this->document([$this->paragraph('b_0001')]),
            ['block_id' => 'b_0001', 'text' => '   '],
        );
    }

    public function test_rewrite_paragraph_signale_un_bloc_introuvable(): void
    {
        $this->expectException(EditingException::class);
        $this->expectExceptionMessage('b_9999');

        (new RewriteParagraphTool)->apply(
            $this->document([$this->paragraph('b_0001')]),
            ['block_id' => 'b_9999', 'text' => 'Texte'],
        );
    }

    public function test_rewrite_paragraph_accepte_les_variantes_de_cle(): void
    {
        // Le modèle produit « text », « content » ou « instruction » selon sa
        // lecture du champ : refuser l'une des formes ferait échouer un appel
        // correct sur le fond.
        $resultat = (new RewriteParagraphTool)->apply(
            $this->document([$this->paragraph('b_0001')]),
            ['block_id' => 'b_0001', 'instruction' => 'Texte via instruction'],
        );

        $this->assertSame('Texte via instruction', $resultat['document']->blockById('b_0001')->text);
    }

    // -------------------------------------------------------------------------
    // insert_block
    // -------------------------------------------------------------------------

    public function test_insert_block_ajoute_un_paragraphe(): void
    {
        $resultat = (new InsertBlockTool)->apply(
            $this->document([$this->paragraph('b_0001'), $this->paragraph('b_0002')]),
            ['position_block_id' => 'b_0001', 'type' => 'paragraph', 'content' => 'Nouveau'],
        );

        $document = $resultat['document'];

        $this->assertSame(3, $document->count());
        $this->assertSame('Nouveau', $document->blocks[1]->text);
    }

    public function test_insert_block_continue_la_numerotation_des_identifiants(): void
    {
        // Un identifiant hors format compliquerait le tri et le débogage.
        $resultat = (new InsertBlockTool)->apply(
            $this->document([$this->paragraph('b_0042')]),
            ['position_block_id' => 'b_0042', 'type' => 'paragraph', 'content' => 'Suite'],
        );

        $this->assertSame('b_0043', $resultat['details']['block_id']);
    }

    public function test_insert_block_accepte_un_titre_avec_niveau(): void
    {
        $resultat = (new InsertBlockTool)->apply(
            $this->document([$this->paragraph('b_0001')]),
            ['position_block_id' => 'b_0001', 'type' => 'heading', 'content' => 'Titre', 'heading_level' => 2],
        );

        $bloc = $resultat['document']->blocks[1];

        $this->assertSame(BlockType::Heading, $bloc->type);
        $this->assertSame(2, $bloc->headingLevel);
    }

    public function test_insert_block_plafonne_un_niveau_de_titre_excessif(): void
    {
        // Le gabarit gère trois niveaux (HeadingFormatter::MAX_LEVEL).
        $resultat = (new InsertBlockTool)->apply(
            $this->document([$this->paragraph('b_0001')]),
            ['position_block_id' => 'b_0001', 'type' => 'heading', 'content' => 'Titre', 'heading_level' => 9],
        );

        $this->assertSame(3, $resultat['document']->blocks[1]->headingLevel);
    }

    public function test_insert_block_refuse_un_tableau_sans_donnees(): void
    {
        $this->expectException(EditingException::class);
        $this->expectExceptionMessage('rows');

        (new InsertBlockTool)->apply(
            $this->document([$this->paragraph('b_0001')]),
            ['position_block_id' => 'b_0001', 'type' => 'table'],
        );
    }

    public function test_insert_block_cree_un_tableau_a_partir_de_lignes(): void
    {
        $resultat = (new InsertBlockTool)->apply(
            $this->document([$this->paragraph('b_0001')]),
            [
                'position_block_id' => 'b_0001',
                'type' => 'table',
                'rows' => [['Poste', 'Montant'], ['Fournitures', '125 000']],
            ],
        );

        $table = $resultat['document']->blocks[1]->tableData;

        $this->assertSame(2, $table->rows);
        $this->assertSame(2, $table->cols);
        $this->assertSame('125 000', $table->cell(1, 1));
    }

    public function test_insert_block_complete_les_lignes_de_longueurs_differentes(): void
    {
        // Une grille creuse produirait un tableau décalé au rendu.
        $resultat = (new InsertBlockTool)->apply(
            $this->document([$this->paragraph('b_0001')]),
            [
                'position_block_id' => 'b_0001',
                'type' => 'table',
                'rows' => [['A', 'B', 'C'], ['D']],
            ],
        );

        $table = $resultat['document']->blocks[1]->tableData;

        $this->assertSame(3, $table->cols);
        $this->assertSame('', $table->cell(1, 2));
    }

    public function test_insert_block_refuse_une_position_inexistante(): void
    {
        // Insérer « après un bloc » qui n'existe pas n'a pas de sens : refuser
        // est plus clair que de placer le contenu ailleurs silencieusement.
        $this->expectException(EditingException::class);

        (new InsertBlockTool)->apply(
            $this->document([$this->paragraph('b_0001')]),
            ['position_block_id' => 'b_9999', 'type' => 'paragraph', 'content' => 'X'],
        );
    }

    public function test_insert_block_refuse_un_type_interdit(): void
    {
        $this->expectException(EditingException::class);

        (new InsertBlockTool)->apply(
            $this->document([$this->paragraph('b_0001')]),
            ['position_block_id' => 'b_0001', 'type' => 'header', 'content' => 'En-tête'],
        );
    }

    // -------------------------------------------------------------------------
    // modify_table
    // -------------------------------------------------------------------------

    public function test_modify_table_ajoute_une_ligne(): void
    {
        $resultat = (new ModifyTableTool)->apply(
            $this->document([$this->table('b_0001')]),
            ['block_id' => 'b_0001', 'operation' => 'add_row', 'values' => ['E', 'F']],
        );

        $table = $resultat['document']->blockById('b_0001')->tableData;

        $this->assertSame(3, $table->rows);
        $this->assertSame('E', $table->cell(1, 0));
    }

    public function test_modify_table_insere_avant_la_derniere_ligne_par_defaut(): void
    {
        // Dans un tableau comptable, la dernière ligne est souvent un total :
        // insérer après lui donnerait un résultat faux.
        $resultat = (new ModifyTableTool)->apply(
            $this->document([$this->table('b_0001', [['Intitulé'], ['Total']])]),
            ['block_id' => 'b_0001', 'operation' => 'add_row', 'values' => ['Nouvelle ligne']],
        );

        $table = $resultat['document']->blockById('b_0001')->tableData;

        $this->assertSame('Nouvelle ligne', $table->cell(1, 0));
        $this->assertSame('Total', $table->cell(2, 0));
    }

    public function test_modify_table_ajoute_une_colonne(): void
    {
        $resultat = (new ModifyTableTool)->apply(
            $this->document([$this->table('b_0001')]),
            ['block_id' => 'b_0001', 'operation' => 'add_column', 'values' => ['X', 'Y']],
        );

        $table = $resultat['document']->blockById('b_0001')->tableData;

        $this->assertSame(3, $table->cols);
        $this->assertSame('X', $table->cell(0, 2));
    }

    public function test_modify_table_supprime_une_ligne(): void
    {
        $resultat = (new ModifyTableTool)->apply(
            $this->document([$this->table('b_0001', [['A'], ['B'], ['C']])]),
            ['block_id' => 'b_0001', 'operation' => 'remove_row', 'row' => 1],
        );

        $table = $resultat['document']->blockById('b_0001')->tableData;

        $this->assertSame(2, $table->rows);
        $this->assertSame('C', $table->cell(1, 0));
    }

    public function test_modify_table_refuse_de_vider_le_tableau(): void
    {
        $this->expectException(EditingException::class);
        $this->expectExceptionMessage('une seule ligne');

        (new ModifyTableTool)->apply(
            $this->document([$this->table('b_0001', [['Unique']])]),
            ['block_id' => 'b_0001', 'operation' => 'remove_row'],
        );
    }

    public function test_modify_table_definit_une_cellule_avec_la_valeur_fournie(): void
    {
        // Seule opération qui touche au contenu — sûre, car la valeur vient de
        // l'instruction de l'utilisateur et aucune IA n'est appelée.
        $resultat = (new ModifyTableTool)->apply(
            $this->document([$this->table('b_0001')]),
            ['block_id' => 'b_0001', 'operation' => 'set_cell', 'row' => 1, 'col' => 1, 'value' => '999'],
        );

        $this->assertSame('999', $resultat['document']->blockById('b_0001')->tableData->cell(1, 1));
    }

    public function test_modify_table_refuse_une_cellule_hors_bornes(): void
    {
        $this->expectException(EditingException::class);
        $this->expectExceptionMessage('hors du tableau');

        (new ModifyTableTool)->apply(
            $this->document([$this->table('b_0001')]),
            ['block_id' => 'b_0001', 'operation' => 'set_cell', 'row' => 9, 'col' => 9, 'value' => 'X'],
        );
    }

    public function test_modify_table_refuse_une_operation_inconnue(): void
    {
        $this->expectException(EditingException::class);
        $this->expectExceptionMessage('inconnue');

        (new ModifyTableTool)->apply(
            $this->document([$this->table('b_0001')]),
            ['block_id' => 'b_0001', 'operation' => 'delete_everything'],
        );
    }

    public function test_modify_table_refuse_un_bloc_non_tableau(): void
    {
        $this->expectException(EditingException::class);
        $this->expectExceptionMessage('pas un tableau');

        (new ModifyTableTool)->apply(
            $this->document([$this->paragraph('b_0001')]),
            ['block_id' => 'b_0001', 'operation' => 'add_row'],
        );
    }

    public function test_modify_table_est_marque_destructif(): void
    {
        $this->assertTrue((new ModifyTableTool)->isDestructive());
    }

    // -------------------------------------------------------------------------
    // delete_block
    // -------------------------------------------------------------------------

    public function test_delete_block_supprime_le_bloc(): void
    {
        $resultat = (new DeleteBlockTool)->apply(
            $this->document([$this->paragraph('b_0001'), $this->paragraph('b_0002')]),
            ['block_id' => 'b_0001'],
        );

        $this->assertSame(1, $resultat['document']->count());
        $this->assertNull($resultat['document']->blockById('b_0001'));
    }

    public function test_delete_block_refuse_le_dernier_bloc(): void
    {
        // Un document vide ne serait plus exportable ni analysable.
        $this->expectException(EditingException::class);
        $this->expectExceptionMessage('dernier du document');

        (new DeleteBlockTool)->apply(
            $this->document([$this->paragraph('b_0001')]),
            ['block_id' => 'b_0001'],
        );
    }

    public function test_delete_block_refuse_un_porteur_de_legende(): void
    {
        // La légende pointerait vers un bloc disparu.
        $this->expectException(EditingException::class);
        $this->expectExceptionMessage('légende');

        (new DeleteBlockTool)->apply(
            $this->document([$this->paragraph('b_0000'), $this->table('b_0001'), $this->caption('b_0002', 'Tableau 1', 'b_0001')]),
            ['block_id' => 'b_0001'],
        );
    }

    public function test_delete_block_refuse_une_cible_de_renvoi(): void
    {
        // « voir Figure 3 » pointerait dans le vide.
        $renvoi = (new CrossRef('Figure 3', BlockCategory::Figure, '3'))->resolveTo('b_0001');

        $this->expectException(EditingException::class);
        $this->expectExceptionMessage('renvoi');

        (new DeleteBlockTool)->apply(
            $this->document([
                new Block(blockId: 'b_0000', type: BlockType::Paragraph, text: 'Voir Figure 3', crossRef: $renvoi),
                $this->table('b_0001'),
            ]),
            ['block_id' => 'b_0001'],
        );
    }

    public function test_delete_block_detache_les_legendes_du_bloc_supprime(): void
    {
        // La légende ne doit pas pointer vers un bloc fantôme : elle redevient
        // autonome, et sera renumérotée comme telle.
        $resultat = (new DeleteBlockTool)->apply(
            $this->document([
                $this->paragraph('b_0000'),
                $this->table('b_0001'),
                $this->caption('b_0002', 'Tableau 1', 'b_0001'),
            ]),
            ['block_id' => 'b_0002'],
        );

        $this->assertNull($resultat['document']->blockById('b_0002'));
        $this->assertSame(2, $resultat['document']->count());
    }

    public function test_delete_block_est_marque_destructif(): void
    {
        $this->assertTrue((new DeleteBlockTool)->isDestructive());
    }

    // -------------------------------------------------------------------------
    // regenerate_section
    // -------------------------------------------------------------------------

    public function test_regenerate_section_remplace_une_plage(): void
    {
        $resultat = (new RegenerateSectionTool)->apply(
            $this->document([
                $this->heading('b_0001', 'Titre', 1),
                $this->paragraph('b_0002', 'Ancien 1'),
                $this->paragraph('b_0003', 'Ancien 2'),
            ]),
            [
                'start_block_id' => 'b_0002',
                'end_block_id' => 'b_0003',
                'content' => ['Nouveau 1', 'Nouveau 2', 'Nouveau 3'],
            ],
        );

        $document = $resultat['document'];

        // 1 titre + 3 nouveaux paragraphes.
        $this->assertSame(4, $document->count());
        $this->assertSame('Nouveau 1', $document->blocks[1]->text);
        $this->assertSame('Nouveau 3', $document->blocks[3]->text);
    }

    public function test_regenerate_section_compte_les_blocs_affectes(): void
    {
        // C'est cette valeur que l'orchestrateur compare au seuil de confirmation.
        $document = $this->document([
            $this->paragraph('b_0001'),
            $this->paragraph('b_0002'),
            $this->paragraph('b_0003'),
            $this->paragraph('b_0004'),
            $this->paragraph('b_0005'),
            $this->paragraph('b_0006'),
        ]);

        $taille = (new RegenerateSectionTool)->affectedBlockCount($document, [
            'start_block_id' => 'b_0001',
            'end_block_id' => 'b_0006',
        ]);

        $this->assertSame(6, $taille);
    }

    public function test_regenerate_section_refuse_une_plage_contenant_un_tableau(): void
    {
        // Un tableau réécrit perdrait ses montants : c'est le cas à refuser
        // absolument, et le message doit nommer le bloc fautif.
        $this->expectException(EditingException::class);
        $this->expectExceptionMessage('b_0002');

        (new RegenerateSectionTool)->apply(
            $this->document([
                $this->paragraph('b_0001'),
                $this->table('b_0002'),
                $this->paragraph('b_0003'),
            ]),
            [
                'start_block_id' => 'b_0001',
                'end_block_id' => 'b_0003',
                'content' => 'Nouveau',
            ],
        );
    }

    public function test_regenerate_section_refuse_une_plage_inversee(): void
    {
        $this->expectException(EditingException::class);

        (new RegenerateSectionTool)->apply(
            $this->document([$this->paragraph('b_0001'), $this->paragraph('b_0002')]),
            [
                'start_block_id' => 'b_0002',
                'end_block_id' => 'b_0001',
                'content' => 'Nouveau',
            ],
        );
    }

    public function test_regenerate_section_refuse_un_contenu_absent(): void
    {
        $this->expectException(EditingException::class);
        $this->expectExceptionMessage('remplacement');

        (new RegenerateSectionTool)->apply(
            $this->document([$this->paragraph('b_0001'), $this->paragraph('b_0002')]),
            ['start_block_id' => 'b_0001', 'end_block_id' => 'b_0002'],
        );
    }

    public function test_regenerate_section_accepte_des_blocs_stuctures(): void
    {
        $resultat = (new RegenerateSectionTool)->apply(
            $this->document([$this->paragraph('b_0001'), $this->paragraph('b_0002')]),
            [
                'start_block_id' => 'b_0001',
                'end_block_id' => 'b_0002',
                'content' => [
                    ['type' => 'heading', 'text' => 'Nouveau titre', 'heading_level' => 2],
                    ['type' => 'paragraph', 'text' => 'Nouveau paragraphe'],
                ],
            ],
        );

        $document = $resultat['document'];

        $this->assertSame(BlockType::Heading, $document->blocks[0]->type);
        $this->assertSame(2, $document->blocks[0]->headingLevel);
        $this->assertSame(BlockType::Paragraph, $document->blocks[1]->type);
    }

    public function test_regenerate_section_est_marque_destructif(): void
    {
        $this->assertTrue((new RegenerateSectionTool)->isDestructive());
    }

    // -------------------------------------------------------------------------
    // Invariants communs
    // -------------------------------------------------------------------------

    public function test_aucun_tool_ne_modifie_le_document_d_origine(): void
    {
        // Immuabilité : socle du snapshot et de l'annulation. Si un tool
        // modifiait son entrée, le snapshot enregistrerait un état déjà altéré.
        $document = $this->document([$this->paragraph('b_0001', 'Origine'), $this->paragraph('b_0002')]);

        (new RewriteParagraphTool)->apply($document, ['block_id' => 'b_0001', 'text' => 'Modifié']);

        $this->assertSame('Origine', $document->blockById('b_0001')->text);
        $this->assertSame(2, $document->count());
    }

    public function test_les_tools_non_destructifs_n_exigent_pas_de_snapshot(): void
    {
        // Une insertion ou une réécriture ciblée n'efface rien d'irréversible :
        // exiger un snapshot les rendrait inutilement coûteux.
        $this->assertFalse((new RewriteParagraphTool)->isDestructive());
        $this->assertFalse((new InsertBlockTool)->isDestructive());
    }

    public function test_les_tools_annoncent_leur_nom(): void
    {
        $this->assertSame('rewrite_paragraph', (new RewriteParagraphTool)->name());
        $this->assertSame('insert_block', (new InsertBlockTool)->name());
        $this->assertSame('modify_table', (new ModifyTableTool)->name());
        $this->assertSame('delete_block', (new DeleteBlockTool)->name());
        $this->assertSame('regenerate_section', (new RegenerateSectionTool)->name());
    }
}
