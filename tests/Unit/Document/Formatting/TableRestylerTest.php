<?php

declare(strict_types=1);

namespace Tests\Unit\Document\Formatting;

use App\Document\Formatting\TableRestyler;
use App\Document\Formatting\TemplateEngine;
use App\Document\Structure\Block;
use App\Document\Structure\BlockType;
use App\Document\Structure\TableData;
use Tests\TestCase;

/**
 * Tests du restylage de tableaux.
 *
 * Le test central est `test_le_contenu_des_cellules_est_strictement_identique_apres_restylage` :
 * il compare les données AVANT et APRÈS mise en forme. Un tableau porte des
 * montants, des dates, des références — une altération même minime serait
 * invisible et livrée telle quelle dans le document final.
 */
class TableRestylerTest extends TestCase
{
    private function tableBlock(TableData $table, string $id = 'b_001'): Block
    {
        return new Block(
            blockId: $id,
            type: BlockType::Table,
            text: 'Tableau',
            tableData: $table,
        );
    }

    // -------------------------------------------------------------------------
    // Garantie centrale : le contenu ne bouge pas
    // -------------------------------------------------------------------------

    public function test_le_contenu_des_cellules_est_strictement_identique_apres_restylage(): void
    {
        $table = TableData::fromGrid([
            ['Désignation', 'Montant (FCFA)', 'Date'],
            ['Fournitures de bureau', '125 000', '12/09/2025'],
            ['Transport', '47 500', '15/09/2025'],
        ]);

        $block = $this->tableBlock($table);
        $restyler = new TableRestyler;

        // Empreinte AVANT.
        $before = $restyler->contentHash($table);

        // Restylage.
        $description = $restyler->describeRestyle($block, TemplateEngine::defaultTemplate());

        // Empreinte APRÈS : le restylage ne renvoie aucune donnée, il ne peut
        // donc pas en altérer. On le vérifie explicitement plutôt que de le
        // supposer.
        $after = $restyler->contentHash($block->tableData);

        $this->assertSame($before, $after);
        $this->assertSame('125 000', $block->tableData->cell(1, 1));
        $this->assertSame('Fournitures de bureau', $block->tableData->cell(1, 0));
        $this->assertTrue($restyler->contentIsIntact($block, $description));
    }

    public function test_la_description_ne_contient_aucune_donnee_de_cellule(): void
    {
        // Une description qui transporterait le texte des cellules ouvrirait la
        // porte à une réécriture. On vérifie donc qu'elle n'en contient pas.
        $table = TableData::fromGrid([
            ['Confidentiel', '999'],
            ['Autre', '111'],
        ]);

        $description = (new TableRestyler)->describeRestyle(
            $this->tableBlock($table),
            TemplateEngine::defaultTemplate(),
        );

        $serialized = json_encode($description, JSON_UNESCAPED_UNICODE);
        $this->assertIsString($serialized);
        $this->assertStringNotContainsString('Confidentiel', $serialized);
        $this->assertStringNotContainsString('999', $serialized);
    }

    public function test_les_caracteres_speciaux_et_accents_sont_preserves(): void
    {
        $table = TableData::fromGrid([
            ['Montant', 'Observation'],
            ['1 250 €', 'Payé — reçu n°42 (TVA 19,25 %)'],
            ['Ünïcödé', '«guillemets» & <balises>'],
        ]);

        $block = $this->tableBlock($table);
        $description = (new TableRestyler)->describeRestyle($block, TemplateEngine::defaultTemplate());

        $this->assertSame('Payé — reçu n°42 (TVA 19,25 %)', $block->tableData->cell(1, 1));
        $this->assertSame('«guillemets» & <balises>', $block->tableData->cell(2, 1));
        $this->assertTrue((new TableRestyler)->contentIsIntact($block, $description));
    }

    public function test_les_fusions_sont_preservees_dans_l_empreinte(): void
    {
        // Un tableau à cellules fusionnées perdrait sa structure si l'empreinte
        // ignorait gridSpan/vMerge.
        $table = new TableData(
            rows: 2,
            cols: 3,
            cells: [
                ['Titre fusionné', '', ''],
                ['A', 'B', 'C'],
            ],
            gridSpan: [[3, 1, 1], [1, 1, 1]],
            vMerge: [[false, false, false], [false, false, false]],
        );

        $restyler = new TableRestyler;
        $block = $this->tableBlock($table);
        $description = $restyler->describeRestyle($block, TemplateEngine::defaultTemplate());

        $this->assertTrue($restyler->contentIsIntact($block, $description));
        $this->assertSame(3, $table->spanAt(0, 0));
    }

    public function test_une_alteration_du_contenu_est_detectee(): void
    {
        // Contre-épreuve : si le contenu changeait réellement, la vérification
        // doit le signaler. Sans ce test, la garantie serait décorative.
        $table = TableData::fromGrid([['Nom', 'Montant'], ['Alice', '100']]);
        $block = $this->tableBlock($table);
        $restyler = new TableRestyler;

        $description = $restyler->describeRestyle($block, TemplateEngine::defaultTemplate());

        // Simulation d'une corruption : on remplace les données du tableau.
        $corrupted = $block->withTableData(
            TableData::fromGrid([['Nom', 'Montant'], ['Alice', '1000']])
        );

        $this->assertFalse($restyler->contentIsIntact($corrupted, $description));
    }

    // -------------------------------------------------------------------------
    // Description du style
    // -------------------------------------------------------------------------

    public function test_la_description_reprend_le_style_du_gabarit(): void
    {
        $description = (new TableRestyler)->describeRestyle(
            $this->tableBlock(TableData::fromGrid([['A'], ['B']])),
            TemplateEngine::defaultTemplate(),
        );

        $this->assertSame('TableGrid', $description['table_style']);
        $this->assertSame('1F3864', $description['header_color']);
        $this->assertSame('FFFFFF', $description['header_text_color']);
        $this->assertTrue($description['border']);
    }

    public function test_un_gabarit_sans_bordure_est_respecte(): void
    {
        $description = (new TableRestyler)->describeRestyle(
            $this->tableBlock(TableData::fromGrid([['A'], ['B']])),
            TemplateEngine::normalizeTemplate(['tableau' => ['bordure' => false]]),
        );

        $this->assertFalse($description['border']);
    }

    public function test_un_bloc_sans_donnees_de_tableau_ne_produit_aucune_description(): void
    {
        $paragraph = new Block(blockId: 'b_001', type: BlockType::Paragraph, text: 'Texte');

        $this->assertNull(
            (new TableRestyler)->describeRestyle($paragraph, TemplateEngine::defaultTemplate())
        );
    }

    public function test_restyle_all_ne_retient_que_les_tableaux(): void
    {
        $blocks = [
            new Block(blockId: 'b_001', type: BlockType::Paragraph, text: 'Texte'),
            $this->tableBlock(TableData::fromGrid([['A'], ['B']]), 'b_002'),
            new Block(blockId: 'b_003', type: BlockType::Heading, text: 'Titre', headingLevel: 1),
        ];

        $descriptions = (new TableRestyler)->restyleAll($blocks, TemplateEngine::defaultTemplate());

        $this->assertCount(1, $descriptions);
        $this->assertArrayHasKey('b_002', $descriptions);
    }

    // -------------------------------------------------------------------------
    // Détection de la ligne d'en-tête
    // -------------------------------------------------------------------------

    public function test_une_premiere_ligne_fusionnee_est_reconnue_comme_titre(): void
    {
        // Cas classique d'un tableau académique : une ligne de titre fusionnée
        // sur toute la largeur.
        $table = new TableData(
            rows: 3,
            cols: 2,
            cells: [
                ['Répartition du budget', ''],
                ['Poste', 'Montant'],
                ['Fournitures', '125 000'],
            ],
            gridSpan: [[2, 1], [1, 1], [1, 1]],
        );

        $description = (new TableRestyler)->describeRestyle(
            $this->tableBlock($table),
            TemplateEngine::defaultTemplate(),
        );

        $this->assertTrue($description['header_row']);
    }

    public function test_une_premiere_ligne_d_intitules_courts_est_reconnue(): void
    {
        $table = TableData::fromGrid([
            ['Nom', 'Prénom', 'Âge'],
            ['ZIVO', 'Desmond', '24'],
        ]);

        $description = (new TableRestyler)->describeRestyle(
            $this->tableBlock($table),
            TemplateEngine::defaultTemplate(),
        );

        $this->assertTrue($description['header_row']);
    }

    public function test_une_premiere_ligne_longue_n_est_pas_un_en_tete(): void
    {
        // Un tableau de données dont la première ligne contient une longue
        // phrase n'a pas d'en-tête : la colorer créerait une fausse hiérarchie.
        $table = TableData::fromGrid([
            ['Ce paragraphe constitue en réalité une phrase complète qui décrit la situation du stage'],
            ['Suite du texte'],
        ]);

        $description = (new TableRestyler)->describeRestyle(
            $this->tableBlock($table),
            TemplateEngine::defaultTemplate(),
        );

        $this->assertFalse($description['header_row']);
    }

    public function test_un_tableau_d_une_seule_ligne_n_a_pas_d_en_tete(): void
    {
        $description = (new TableRestyler)->describeRestyle(
            $this->tableBlock(TableData::fromGrid([['Unique']])),
            TemplateEngine::defaultTemplate(),
        );

        $this->assertFalse($description['header_row']);
    }

    // -------------------------------------------------------------------------
    // Statistiques
    // -------------------------------------------------------------------------

    public function test_les_statistiques_comptent_tableaux_cellules_et_fusions(): void
    {
        $blocks = [
            $this->tableBlock(TableData::fromGrid([['A', 'B'], ['C', 'D']]), 'b_001'),
            $this->tableBlock(new TableData(
                rows: 2,
                cols: 2,
                cells: [['Fusion', ''], ['X', 'Y']],
                gridSpan: [[2, 1], [1, 1]],
            ), 'b_002'),
            new Block(blockId: 'b_003', type: BlockType::Paragraph, text: 'Texte'),
        ];

        $stats = (new TableRestyler)->statistics($blocks);

        $this->assertSame(2, $stats['tables']);
        $this->assertSame(8, $stats['cells']);
        $this->assertSame(1, $stats['with_merges']);
        $this->assertTrue($stats['all_content_intact']);
    }

    public function test_les_statistiques_sur_un_document_sans_tableau(): void
    {
        $stats = (new TableRestyler)->statistics([
            new Block(blockId: 'b_001', type: BlockType::Paragraph, text: 'Texte'),
        ]);

        $this->assertSame(0, $stats['tables']);
        $this->assertSame(0, $stats['cells']);
        $this->assertTrue($stats['all_content_intact']);
    }

    public function test_deux_tableaux_identiques_ont_la_meme_empreinte(): void
    {
        // L'empreinte doit être stable (pas de dépendance à l'ordre de parcours
        // d'un tableau associatif, par exemple).
        $restyler = new TableRestyler;
        $grid = [['A', 'B'], ['C', 'D']];

        $this->assertSame(
            $restyler->contentHash(TableData::fromGrid($grid)),
            $restyler->contentHash(TableData::fromGrid($grid))
        );
    }

    public function test_deux_tableaux_differents_ont_des_empreintes_differentes(): void
    {
        $restyler = new TableRestyler;

        $this->assertNotSame(
            $restyler->contentHash(TableData::fromGrid([['A'], ['B']])),
            $restyler->contentHash(TableData::fromGrid([['A'], ['C']]))
        );
    }
}
