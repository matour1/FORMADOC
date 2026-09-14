<?php

declare(strict_types=1);

namespace Tests\Unit\Document\Structure;

use App\Document\Structure\TableData;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Tests des décisions portées par TableData.
 *
 * Enjeu principal : le contenu des cellules est conservé EXACTEMENT et les
 * fusions préservées — il n'est jamais reformulé par l'IA et doit survivre à
 * un aller-retour JSON.
 */
class TableDataTest extends TestCase
{
    public function test_from_grid_deduit_les_dimensions(): void
    {
        $table = TableData::fromGrid([
            ['A', 'B', 'C'],
            ['1', '2', '3'],
        ]);

        $this->assertSame(2, $table->rows);
        $this->assertSame(3, $table->cols);
    }

    public function test_le_contenu_des_cellules_est_conserve_a_l_identique(): void
    {
        // Accents, espaces internes et caractères spéciaux : rien n'est normalisé.
        $grid = [
            ['Indicateur', 'Valeur (FCFA)'],
            ['Chiffre d\'affaires', '1 250 000'],
            ['Taux — T1', '12,5 %'],
        ];

        $table = TableData::fromGrid($grid);

        $this->assertSame('Chiffre d\'affaires', $table->cell(1, 0));
        $this->assertSame('1 250 000', $table->cell(1, 1));
        $this->assertSame('12,5 %', $table->cell(2, 1));
        $this->assertSame($grid, $table->cells);
    }

    public function test_une_cellule_hors_bornes_retourne_une_chaine_vide(): void
    {
        $table = TableData::fromGrid([['A']]);

        $this->assertSame('', $table->cell(9, 9));
    }

    public function test_un_tableau_sans_fusion_n_en_declare_aucune(): void
    {
        $table = TableData::fromGrid([['A', 'B'], ['C', 'D']]);

        $this->assertFalse($table->hasMerges());
        $this->assertSame(1, $table->spanAt(0, 0));
    }

    public function test_une_fusion_horizontale_est_detectee_et_exposee(): void
    {
        $table = new TableData(
            rows: 1,
            cols: 2,
            cells: [[0 => 'Titre fusionné', 1 => '']],
            gridSpan: [[2, 1]],
        );

        $this->assertTrue($table->hasMerges());
        $this->assertSame(2, $table->spanAt(0, 0));
    }

    public function test_une_fusion_verticale_expose_sa_continuation(): void
    {
        $table = new TableData(
            rows: 2,
            cols: 1,
            cells: [[0 => 'Bloc'], [0 => '']],
            vMerge: [[false], [true]],
        );

        $this->assertTrue($table->hasMerges());
        $this->assertFalse($table->isVMergeContinuation(0, 0));
        $this->assertTrue($table->isVMergeContinuation(1, 0));
    }

    public function test_une_cellule_non_renseignee_n_est_pas_une_continuation(): void
    {
        $table = TableData::fromGrid([['A'], ['B']]);

        $this->assertFalse($table->isVMergeContinuation(1, 0));
    }

    public function test_flatten_cells_parcourt_toutes_les_cellules_dans_l_ordre(): void
    {
        $table = TableData::fromGrid([['A', 'B'], ['C', 'D']]);

        $this->assertSame(['A', 'B', 'C', 'D'], $table->flattenCells());
    }

    public function test_le_schema_json_aplatit_les_cellules_pour_respecter_le_format_commun(): void
    {
        $table = TableData::fromGrid([['A', 'B'], ['C', 'D']]);
        $array = $table->toArray();

        // Le schéma §6 documente `cells` comme une liste plate.
        $this->assertSame(['A', 'B', 'C', 'D'], $array['cells']);
        $this->assertSame(2, $array['rows']);
        $this->assertSame(2, $array['cols']);
    }

    public function test_un_aller_retour_json_reconstitue_la_grille_complete(): void
    {
        $original = TableData::fromGrid([
            ['En-tête 1', 'En-tête 2', 'En-tête 3'],
            ['a', 'b', 'c'],
            ['d', 'e', 'f'],
        ]);

        $restored = TableData::fromArray($original->toArray());

        $this->assertSame($original->cells, $restored->cells);
        $this->assertSame('En-tête 3', $restored->cell(0, 2));
        $this->assertSame('e', $restored->cell(2, 1));
    }

    public function test_un_aller_retour_json_preserve_les_fusions(): void
    {
        $original = new TableData(
            rows: 2,
            cols: 2,
            cells: [[0 => 'Fusion', 1 => ''], [0 => 'x', 1 => 'y']],
            gridSpan: [[2, 1], [1, 1]],
            vMerge: [[false, false], [true, false]],
        );

        $restored = TableData::fromArray($original->toArray());

        $this->assertTrue($restored->hasMerges());
        $this->assertSame(2, $restored->spanAt(0, 0));
        $this->assertTrue($restored->isVMergeContinuation(1, 0));
    }

    public function test_un_nombre_de_lignes_negatif_est_rejete(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('lignes');

        new TableData(rows: -1, cols: 2);
    }

    public function test_un_nombre_de_colonnes_negatif_est_rejete(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('colonnes');

        new TableData(rows: 1, cols: -3);
    }

    public function test_un_tableau_vide_est_representable(): void
    {
        $table = TableData::fromGrid([]);

        $this->assertSame(0, $table->rows);
        $this->assertSame(0, $table->cols);
        $this->assertFalse($table->hasMerges());
        $this->assertSame([], $table->flattenCells());
    }
}
