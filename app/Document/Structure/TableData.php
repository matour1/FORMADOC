<?php

declare(strict_types=1);

namespace App\Document\Structure;

use InvalidArgumentException;

/**
 * Données brutes d'un tableau.
 *
 * Règle absolue (REFONTE_ARCHITECTURE.md §15) : le contenu des cellules n'est
 * JAMAIS reformulé par l'IA. Il est classifié, restylé (bordures, couleur
 * d'en-tête) mais jamais réécrit. Ce objet conserve donc le texte exact.
 *
 * Les fusions de cellules sont préservées : une cellule fusionnée horizontalement
 * occupe une seule entrée et les suivantes portent leur `spanned` à true.
 */
final readonly class TableData
{
    /**
     * @param  int  $rows  Nombre de lignes
     * @param  int  $cols  Nombre de colonnes
     * @param  array<int, array<int, string>>  $cells  Contenu exact des cellules [ligne][colonne]
     * @param  array<int, array<int, int>>  $gridSpan  Nombre de colonnes couvertes par cellule (1 par défaut)
     * @param  array<int, array<int, bool>>  $vMerge  Cellule fusionnée verticalement depuis la ligne du dessus
     */
    public function __construct(
        public int $rows,
        public int $cols,
        public array $cells = [],
        public array $gridSpan = [],
        public array $vMerge = [],
    ) {
        if ($rows < 0) {
            throw new InvalidArgumentException('Le nombre de lignes ne peut pas être négatif.');
        }

        if ($cols < 0) {
            throw new InvalidArgumentException('Le nombre de colonnes ne peut pas être négatif.');
        }
    }

    /**
     * Construit un tableau depuis une grille de chaînes (cas le plus courant).
     *
     * Le nombre de lignes et de colonnes est déduit de la grille ; les colonnes
     * sont supposées régulières (la première ligne fait référence).
     *
     * @param  array<int, array<int, string>>  $cells
     */
    public static function fromGrid(array $cells): self
    {
        $rows = count($cells);
        $cols = $rows > 0 ? count($cells[0]) : 0;

        return new self(rows: $rows, cols: $cols, cells: $cells);
    }

    /**
     * Contenu exact d'une cellule (chaîne vide si hors bornes).
     */
    public function cell(int $row, int $col): string
    {
        return $this->cells[$row][$col] ?? '';
    }

    /**
     * Nombre de colonnes réellement couvertes par une cellule fusionnée.
     */
    public function spanAt(int $row, int $col): int
    {
        return $this->gridSpan[$row][$col] ?? 1;
    }

    /**
     * Cette cellule est-elle la continuation d'une fusion verticale ?
     *
     * Une continuation ne doit pas afficher son propre contenu (Word affiche le
     * texte de la cellule fusionnée d'origine).
     */
    public function isVMergeContinuation(int $row, int $col): bool
    {
        return ($this->vMerge[$row][$col] ?? false) === true;
    }

    /**
     * Ce tableau contient-il au moins une fusion ?
     */
    public function hasMerges(): bool
    {
        foreach ($this->gridSpan as $row) {
            foreach ($row as $span) {
                if ($span > 1) {
                    return true;
                }
            }
        }

        foreach ($this->vMerge as $row) {
            foreach ($row as $isMerged) {
                if ($isMerged) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Toutes les cellules, aplaties (utile pour la recherche de texte).
     *
     * @return array<int, string>
     */
    public function flattenCells(): array
    {
        $flattened = [];

        foreach ($this->cells as $row) {
            foreach ($row as $cell) {
                $flattened[] = $cell;
            }
        }

        return $flattened;
    }

    /**
     * Sérialisation vers le schéma JSON structurel commun (§6).
     *
     * Le format de sortie est celui documenté : `cells` à plat, comme dans le
     * schéma de référence, plus les métadonnées de fusion.
     *
     * @return array{rows: int, cols: int, cells: array<int, string>, grid_span: array<int, array<int, int>>, v_merge: array<int, array<int, bool>>}
     */
    public function toArray(): array
    {
        return [
            'rows' => $this->rows,
            'cols' => $this->cols,
            'cells' => $this->flattenCells(),
            'grid_span' => $this->gridSpan,
            'v_merge' => $this->vMerge,
        ];
    }

    /**
     * Reconstruit un tableau depuis le schéma JSON structurel.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $rows = (int) ($data['rows'] ?? 0);
        $cols = (int) ($data['cols'] ?? 0);
        $flat = $data['cells'] ?? [];

        if (! is_array($flat)) {
            $flat = [];
        }

        // Le schéma commun aplatit les cellules : on reconstitue la grille.
        $cells = [];
        if ($rows > 0 && $cols > 0) {
            $flat = array_values($flat);
            for ($row = 0; $row < $rows; $row++) {
                for ($col = 0; $col < $cols; $col++) {
                    $index = ($row * $cols) + $col;
                    $cells[$row][$col] = (string) ($flat[$index] ?? '');
                }
            }
        }

        return new self(
            rows: $rows,
            cols: $cols,
            cells: $cells,
            gridSpan: is_array($data['grid_span'] ?? null) ? $data['grid_span'] : [],
            vMerge: is_array($data['v_merge'] ?? null) ? $data['v_merge'] : [],
        );
    }
}
