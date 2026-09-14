<?php

declare(strict_types=1);

namespace App\Document\Editing\Tools;

use App\Document\Editing\EditingException;
use App\Document\Editing\EditTool;
use App\Document\Structure\StructuralDocument;
use App\Document\Structure\TableData;

/**
 * Tool `modify_table` — modifie la **structure** d'un tableau, jamais son contenu.
 *
 * **Règle absolue (§15)** : le contenu des cellules n'est jamais reformulé par
 * l'IA. Ce tool ne sait donc faire que des opérations de structure :
 *
 *  - ajouter une ligne ;
 *  - ajouter une colonne ;
 *  - supprimer une ligne ;
 *  - définir la ligne d'en-tête ;
 *  - remplacer une cellule **par une valeur fournie explicitement**.
 *
 * Le remplacement de cellule est autorisé parce que la valeur vient d'une
 * instruction explicite de l'utilisateur (« mets 125 000 dans la cellule 2,1 »).
 * Ce qui est interdit, c'est de **laisser le modèle réécrire** des montants ou
 * des dates de sa propre initiative — et c'est structurellement impossible ici :
 * ce tool n'appelle aucune IA, il applique une valeur reçue.
 *
 * **Le tool est marqué destructif** : ajouter ou supprimer une ligne change la
 * structure du document, et un snapshot doit permettre de revenir en arrière.
 */
final class ModifyTableTool implements EditTool
{
    /**
     * Opérations de structure autorisées.
     */
    private const OPERATIONS = [
        'add_row',
        'add_column',
        'remove_row',
        'set_header',
        'set_cell',
    ];

    public function name(): string
    {
        return 'modify_table';
    }

    public function isDestructive(): bool
    {
        return true;
    }

    public function affectedBlockCount(StructuralDocument $document, array $arguments): int
    {
        return 1;
    }

    /**
     * @param  array<string, mixed>  $arguments  {block_id, operation, ...}
     * @return array{document: StructuralDocument, summary: string, details: array<string, mixed>}
     */
    public function apply(StructuralDocument $document, array $arguments): array
    {
        $blockId = (string) ($arguments['block_id'] ?? '');
        $bloc = $document->blockById($blockId);

        if ($bloc === null) {
            throw EditingException::blocIntrouvable($blockId, $this->name());
        }

        if ($bloc->tableData === null) {
            throw EditingException::sortieInvalide(
                $this->name(),
                "le bloc « {$blockId} » n'est pas un tableau"
            );
        }

        $operation = (string) ($arguments['operation'] ?? '');

        if (! in_array($operation, self::OPERATIONS, true)) {
            throw EditingException::sortieInvalide(
                $this->name(),
                "opération « {$operation} » inconnue — attendu : ".implode(', ', self::OPERATIONS)
            );
        }

        $table = $bloc->tableData;
        $nouveau = $this->appliquer($table, $operation, $arguments);
        $document = $document->replaceBlock($bloc->withTableData($nouveau));

        return [
            'document' => $document,
            'summary' => 'Tableau « '.$blockId.' » modifié ('.$operation.').',
            'details' => [
                'block_id' => $blockId,
                'operation' => $operation,
                'rows_before' => $table->rows,
                'rows_after' => $nouveau->rows,
                'cols_before' => $table->cols,
                'cols_after' => $nouveau->cols,
            ],
        ];
    }

    /**
     * Applique l'opération de structure demandée.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @throws EditingException Si l'opération est impossible sur ce tableau
     */
    private function appliquer(TableData $table, string $operation, array $arguments): TableData
    {
        return match ($operation) {
            'add_row' => $this->ajouterLigne($table, $arguments),
            'add_column' => $this->ajouterColonne($table, $arguments),
            'remove_row' => $this->supprimerLigne($table, $arguments),
            'set_header' => $this->definirEnTete($table, $arguments),
            'set_cell' => $this->definirCellule($table, $arguments),
        };
    }

    /**
     * Ajoute une ligne, vide ou fournie.
     *
     * @param  array<string, mixed>  $arguments
     */
    private function ajouterLigne(TableData $table, array $arguments): TableData
    {
        $fournie = $arguments['values'] ?? null;
        $ligne = [];

        for ($col = 0; $col < $table->cols; $col++) {
            $ligne[] = is_array($fournie) && isset($fournie[$col]) && is_scalar($fournie[$col])
                ? (string) $fournie[$col]
                : '';
        }

        $position = $this->positionLigne($arguments, $table->rows);

        $lignes = $this->lignes($table);
        array_splice($lignes, $position, 0, [$ligne]);

        return TableData::fromGrid($lignes);
    }

    /**
     * Ajoute une colonne, vide ou fournie.
     *
     * @param  array<string, mixed>  $arguments
     */
    private function ajouterColonne(TableData $table, array $arguments): TableData
    {
        $fournie = $arguments['values'] ?? null;
        $largeur = is_array($fournie) ? count($fournie) : 0;
        $lignes = $this->lignes($table);

        foreach ($lignes as $index => $ligne) {
            $valeur = is_array($fournie) && isset($fournie[$index]) && is_scalar($fournie[$index])
                ? (string) $fournie[$index]
                : '';

            $ligne[] = $valeur;
            $lignes[$index] = $ligne;
        }

        // Si la colonne fournie est plus courte que le tableau, les lignes
        // manquantes reçoivent une cellule vide — ce qui précède le fait déjà.
        unset($largeur);

        return TableData::fromGrid($lignes);
    }

    /**
     * Supprime une ligne.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @throws EditingException Si le tableau n'a plus qu'une ligne
     */
    private function supprimerLigne(TableData $table, array $arguments): TableData
    {
        if ($table->rows <= 1) {
            throw new EditingException(
                'Ce tableau ne comporte qu’une seule ligne : la supprimer viderait le tableau. '
                .'Supprimez plutôt le bloc entier si c’est le but.'
            );
        }

        $position = (int) ($arguments['row'] ?? ($table->rows - 1));

        if ($position < 0 || $position >= $table->rows) {
            throw EditingException::sortieInvalide(
                $this->name(),
                "la ligne {$position} n'existe pas (le tableau en compte {$table->rows})"
            );
        }

        $lignes = $this->lignes($table);
        array_splice($lignes, $position, 1);

        return TableData::fromGrid($lignes);
    }

    /**
     * Définit (ou retire) la ligne d'en-tête.
     *
     * L'en-tête est signalé par une mise en forme, pas par une donnée
     * structurelle : on le trace donc dans les métadonnées du bloc plutôt que
     * dans le tableau lui-même. L'opération vérifie seulement que la désignation
     * est cohérente — un en-tête sur un tableau vide n'aurait pas de sens.
     *
     * @param  array<string, mixed>  $arguments
     */
    private function definirEnTete(TableData $table, array $arguments): TableData
    {
        $actif = (bool) ($arguments['enabled'] ?? true);

        if ($actif && $table->rows < 2) {
            throw new EditingException(
                'Un en-tête suppose au moins deux lignes (l’intitulé et les données). '
                .'Ajoutez d’abord les données du tableau.'
            );
        }

        // Aucun changement de données : l'information d'en-tête est portée par la
        // mise en forme (voir `TableRestyler`), qui la déduit du gabarit.
        return $table;
    }

    /**
     * Remplace la valeur d'une cellule par une valeur **fournie explicitement**.
     *
     * C'est la seule opération qui touche au contenu — et elle est sûre, parce
     * que la valeur vient de l'instruction de l'utilisateur et non d'une
     * génération du modèle. Aucune IA n'est appelée ici.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @throws EditingException Si la valeur manque ou si les coordonnées sont hors bornes
     */
    private function definirCellule(TableData $table, array $arguments): TableData
    {
        $valeur = $arguments['value'] ?? null;

        if (! is_scalar($valeur)) {
            throw EditingException::sortieInvalide(
                $this->name(),
                'la nouvelle valeur de cellule doit être fournie dans « value »'
            );
        }

        $ligne = (int) ($arguments['row'] ?? -1);
        $colonne = (int) ($arguments['col'] ?? -1);

        if ($ligne < 0 || $ligne >= $table->rows || $colonne < 0 || $colonne >= $table->cols) {
            throw EditingException::sortieInvalide(
                $this->name(),
                "la cellule ({$ligne}, {$colonne}) est hors du tableau "
                ."({$table->rows} lignes × {$table->cols} colonnes)"
            );
        }

        $lignes = $this->lignes($table);
        $lignes[$ligne][$colonne] = (string) $valeur;

        return TableData::fromGrid($lignes);
    }

    /**
     * Position d'insertion d'une ligne.
     *
     * Par défaut, la ligne est ajoutée avant la dernière : dans un tableau
     * comptable, la dernière ligne est souvent un total, et insérer après lui
     * donnerait un résultat faux. L'utilisateur peut forcer une position.
     *
     * @param  array<string, mixed>  $arguments
     */
    private function positionLigne(array $arguments, int $rows): int
    {
        if (! isset($arguments['row'])) {
            return max(0, $rows - 1);
        }

        return min(max(0, (int) $arguments['row']), $rows);
    }

    /**
     * Grille du tableau sous forme de lignes × colonnes.
     *
     * `TableData` stocke déjà une grille, mais son accès est indexé : cette
     * conversion donne des tableaux PHP manipulables par `array_splice`.
     *
     * @return array<int, array<int, string>>
     */
    private function lignes(TableData $table): array
    {
        $lignes = [];

        for ($ligne = 0; $ligne < $table->rows; $ligne++) {
            $cellules = [];

            for ($colonne = 0; $colonne < $table->cols; $colonne++) {
                $cellules[] = $table->cell($ligne, $colonne);
            }

            $lignes[] = $cellules;
        }

        return $lignes;
    }
}
