<?php

declare(strict_types=1);

namespace App\Document\Adapters\DocxOoxml;

use App\Document\Structure\TableData;
use DOMElement;

/**
 * Lecture des tableaux Word (`w:tbl`).
 *
 * Règle absolue (REFONTE_ARCHITECTURE.md §15) : **le contenu des cellules n'est
 * JAMAIS reformulé ni normalisé**. Il est lu, restylé (bordures, couleur
 * d'en-tête) mais jamais réécrit — un tableau contient des données chiffrées
 * qu'une reformulation corromprait silencieusement.
 *
 * Structure lue :
 * ```
 * w:tbl
 *  ├─ w:tblPr   (style, largeur, look)
 *  ├─ w:tblGrid (largeurs des colonnes)
 *  └─ w:tr      (ligne)
 *      └─ w:tc  (cellule)
 *          ├─ w:tcPr (gridSpan, vMerge, tcW…)
 *          └─ w:p   (paragraphes — une cellule peut en contenir plusieurs)
 * ```
 *
 * Subtilité des fusions :
 *  - `w:gridSpan w:val="2"` : la cellule couvre 2 colonnes. Les cellules
 *    suivantes de la ligne sont ABSENTES de l'XML (elles ne sont pas vides).
 *  - `w:vMerge w:val="restart"` : commence une fusion verticale.
 *  - `w:vMerge` (sans `w:val`, ou `="continue"`) : continuation vers le bas.
 *    La cellule existe mais son contenu ne doit pas être relu (Word affiche
 *    celui de la cellule d'origine).
 */
final class TableReader
{
    public function __construct(private readonly ParagraphReader $paragraphs) {}

    /**
     * Lit un tableau et retourne ses données + ses métadonnées.
     *
     * @param  DOMElement  $table  Élément `w:tbl`
     * @return array{
     *     table_data: TableData,
     *     has_header: bool,
     *     has_merges: bool,
     *     column_widths: array<int, float>,
     *     style_id: null|string,
     *     row_count: int
     * }
     */
    public function read(DOMElement $table): array
    {
        $grid = XmlLoader::firstChild($table, 'w:tblGrid');
        $columnWidths = $this->columnWidths($grid);

        $rows = XmlLoader::childElements($table, 'w:tr');
        $cells = [];
        $gridSpan = [];
        $vMerge = [];
        $hasMerges = false;

        foreach ($rows as $rowIndex => $row) {
            $rowCells = [];
            $rowSpan = [];
            $rowMerge = [];

            // Une ligne marquée `w:tblHeader` se répète en haut de chaque page :
            // c'est la ligne d'en-tête du tableau.
            foreach (XmlLoader::childElements($row, 'w:tc') as $cell) {
                $properties = XmlLoader::firstChild($cell, 'w:tcPr');

                $span = $this->gridSpanOf($properties);
                if ($span > 1) {
                    $hasMerges = true;
                }

                $isMergeContinuation = $this->isMergeContinuation($properties);
                if ($isMergeContinuation) {
                    $hasMerges = true;
                }

                $rowCells[] = $this->cellText($cell, $isMergeContinuation);
                $rowSpan[] = $span;
                $rowMerge[] = $isMergeContinuation;
            }

            $cells[] = $rowCells;
            $gridSpan[] = $rowSpan;
            $vMerge[] = $rowMerge;
        }

        $rowCount = count($rows);
        $columnCount = $this->columnCount($cells, $gridSpan, $columnWidths);

        return [
            'table_data' => new TableData(
                rows: $rowCount,
                cols: $columnCount,
                cells: $cells,
                gridSpan: $gridSpan,
                vMerge: $vMerge,
            ),
            'has_header' => $this->hasRepeatingHeader($rows),
            'has_merges' => $hasMerges,
            'column_widths' => $columnWidths,
            'style_id' => $this->styleIdOf($table),
            'row_count' => $rowCount,
        ];
    }

    /**
     * Texte d'une cellule.
     *
     * Une cellule Word contient des PARAGRAPHES (`w:p`), pas un simple texte :
     * le contenu peut aussi être réparti sur plusieurs runs (Word fragmente
     * « Du 01/04/2026 » en « Du » + « 01/04/202 » + « 6 » selon les corrections
     * de frappe). On concatène donc tous les paragraphes, séparés par un saut
     * de ligne — la structure interne de la cellule est conservée telle quelle.
     *
     * @param  bool  $isMergeContinuation  Cellule qui prolonge une fusion verticale
     */
    private function cellText(DOMElement $cell, bool $isMergeContinuation): string
    {
        // Une cellule de continuation de fusion verticale ne porte pas son
        // propre contenu : Word affiche celui de la cellule d'origine.
        if ($isMergeContinuation) {
            return '';
        }

        $paragraphTexts = [];

        foreach (XmlLoader::childElements($cell, 'w:p') as $paragraph) {
            $text = '';
            foreach (XmlLoader::descendants($paragraph, 'w:t') as $textNode) {
                $text .= $textNode->textContent;
            }

            // Les sauts de ligne internes (`w:br`) doivent être préservés.
            foreach (XmlLoader::descendants($paragraph, 'w:br') as $break) {
                $type = XmlLoader::attributeValue($break, 'w:type');
                if ($type !== 'page') {
                    $text .= "\n";
                }
            }

            // Les images dans une cellule sont marquées pour ne pas être perdues.
            foreach (XmlLoader::descendants($paragraph, 'w:drawing') as $drawing) {
                $text .= '[image]';
            }

            $paragraphTexts[] = $text;
        }

        // Les paragraphes vides en fin de cellule sont des artefacts de mise en
        // page : on les retire sans toucher au contenu réel.
        while ($paragraphTexts !== [] && end($paragraphTexts) === '') {
            array_pop($paragraphTexts);
        }

        return $this->normalizeCellText(implode("\n", $paragraphTexts));
    }

    /**
     * Normalise les caractères invisibles d'une cellule.
     *
     * On ne touche PAS au contenu (espaces internes, chiffres, ponctuation) :
     * seuls les caractères de contrôle Unicode que Word insère sont uniformisés.
     */
    private function normalizeCellText(string $text): string
    {
        // Espaces insécables et fines → espace normale (sinon les comparaisons
        // de contenu échouent et l'affichage est irrégulier).
        $text = str_replace(["\u{00A0}", "\u{202F}", "\u{2009}"], ' ', $text);

        // Retours Windows → Unix, puis suppression des espaces de début/fin.
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        return trim($text);
    }

    /**
     * Nombre de colonnes couvertes par une cellule (`w:gridSpan`).
     *
     * Quand une cellule couvre 2 colonnes, les cellules suivantes de la ligne
     * sont ABSENTES de l'XML — il faut donc additionner les `gridSpan` pour
     * connaître la largeur réelle de la ligne.
     */
    private function gridSpanOf(?DOMElement $cellProperties): int
    {
        if ($cellProperties === null) {
            return 1;
        }

        $spanElement = XmlLoader::firstChild($cellProperties, 'w:gridSpan');
        $value = XmlLoader::attributeValue($spanElement);

        return $value === null ? 1 : max(1, (int) $value);
    }

    /**
     * Cette cellule prolonge-t-elle une fusion verticale ?
     *
     * Trois formes existent :
     *  - `<w:vMerge w:val="restart"/>` : commence la fusion → ce n'est PAS une
     *    continuation ;
     *  - `<w:vMerge/>` : continuation implicite ;
     *  - `<w:vMerge w:val="continue"/>` : continuation explicite.
     */
    private function isMergeContinuation(?DOMElement $cellProperties): bool
    {
        if ($cellProperties === null) {
            return false;
        }

        $merge = XmlLoader::firstChild($cellProperties, 'w:vMerge');
        if ($merge === null) {
            return false;
        }

        $value = XmlLoader::attributeValue($merge);

        // `restart` (ou `w:val` absent sur un restart) démarre la fusion.
        return $value !== 'restart';
    }

    /**
     * Largeurs des colonnes, en points.
     *
     * @return array<int, float>
     */
    private function columnWidths(?DOMElement $grid): array
    {
        if ($grid === null) {
            return [];
        }

        $widths = [];
        foreach (XmlLoader::childElements($grid, 'w:gridCol') as $column) {
            $width = XmlLoader::attributeValue($column, 'w:w');
            $widths[] = $width === null ? 0.0 : (float) XmlLoader::twipsToPoints($width);
        }

        return $widths;
    }

    /**
     * Nombre de colonnes du tableau.
     *
     * Le `w:tblGrid` est la source de vérité : il déclare la structure réelle
     * du tableau, indépendamment du contenu des lignes. Il est préféré au
     * comptage des cellules, car une ligne peut légitimement couvrir moins de
     * colonnes que le tableau n'en déclare (ligne de titre fusionnée, par
     * exemple) — et les `gridSpan` d'une ligne d'exemple ne reflètent pas
     * toujours la largeur maximale.
     *
     * @param  array<int, array<int, string>>  $cells
     * @param  array<int, array<int, int>>  $spanRows
     * @param  array<int, float>  $columnWidths  Largeurs déclarées par `w:tblGrid`
     */
    private function columnCount(array $cells, array $spanRows, array $columnWidths): int
    {
        // 1. Source de vérité : le tblGrid.
        if ($columnWidths !== []) {
            return count($columnWidths);
        }

        // 2. Repli : la ligne la plus large du tableau (somme des gridSpan).
        $max = 0;
        foreach ($spanRows as $spans) {
            $total = array_sum($spans);
            if ($total > $max) {
                $max = $total;
            }
        }

        // 3. Dernier repli : le nombre de cellules de la première ligne.
        if ($max === 0 && $cells !== []) {
            $max = count($cells[0]);
        }

        return $max;
    }

    /**
     * Le tableau a-t-il une ligne d'en-tête qui se répète ?
     *
     * `w:tblHeader` marque une ligne répétée en haut de chaque page. Elle est
     * utilisée par le moteur de gabarit pour appliquer la couleur d'en-tête.
     *
     * @param  array<int, DOMElement>  $rows
     */
    private function hasRepeatingHeader(array $rows): bool
    {
        foreach ($rows as $row) {
            $properties = XmlLoader::firstChild($row, 'w:trPr');
            if ($properties !== null && XmlLoader::firstChild($properties, 'w:tblHeader') !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Identifiant de style du tableau (`w:tblStyle`).
     *
     * Conservé pour permettre au moteur de gabarit de savoir d'où vient la mise
     * en forme d'origine avant de la remplacer.
     */
    private function styleIdOf(DOMElement $table): ?string
    {
        $properties = XmlLoader::firstChild($table, 'w:tblPr');

        return $properties === null
            ? null
            : XmlLoader::attributeValue(XmlLoader::firstChild($properties, 'w:tblStyle'));
    }
}
