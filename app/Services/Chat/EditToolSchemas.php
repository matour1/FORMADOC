<?php

declare(strict_types=1);

namespace App\Services\Chat;

use App\Document\Editing\ToolWhitelist;

/**
 * Schémas OpenAI des tools d'édition structurelle (phase R6.14).
 *
 * **Pourquoi une classe dédiée** — `ChatToolsService` expose déjà treize outils ;
 * y ajouter six schémas verbeux (plus de 200 lignes) rendrait le service
 * illisible sans rien apporter. Ici, les schémas sont isolés et leur cohérence
 * avec la liste blanche est vérifiable.
 *
 * **Point de conception** — les schémas sont **dérivés** de `ToolWhitelist` :
 * la liste des noms vient de la liste blanche, et l'énumération des types
 * insérables vient de `BlockType::insertable()`. Un outil retiré de
 * l'autorisation ne peut donc pas rester exposé au modèle par inadvertance.
 *
 * **Ces outils exigent un `document_id`** — ils éditent un document **analysé et
 * persisté**, pas une pièce jointe. C'est une différence de fond avec
 * `document_edit`, qui travaille sur un fichier attaché à la conversation. Le
 * modèle doit le comprendre, d'où la mention explicite dans chaque description.
 */
final class EditToolSchemas
{
    /**
     * Schémas OpenAI complets des tools d'édition et de l'annulation.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function all(): array
    {
        return [
            self::rewriteParagraph(),
            self::insertBlock(),
            self::modifyTable(),
            self::deleteBlock(),
            self::regenerateSection(),
            self::undoLastAction(),
        ];
    }

    /**
     * Noms exposés, dérivés de la liste blanche.
     *
     * `ask_user_clarification` est exclu : il appartient au flux de
     * classification, pas au chat d'édition.
     *
     * @return array<int, string>
     */
    public static function names(): array
    {
        return [...ToolWhitelist::editingToolNames(), 'undo_last_action'];
    }

    private static function rewriteParagraph(): array
    {
        return self::schema(
            'rewrite_paragraph',
            'RÉÉCRIT le texte d’un paragraphe ou d’un titre EXISTANT d’un document analysé. '
            .'Utilise-le quand l’utilisateur demande de reformuler, corriger ou remplacer le contenu '
            .'d’un passage précis. Fonctionne uniquement sur les paragraphes et les titres : les '
            .'tableaux, figures et légendes ne peuvent pas être réécrits, car leurs données et leur '
            .'numérotation seraient corrompues.',
            [
                'document_id' => self::documentId(),
                'block_id' => self::blockId(),
                'text' => [
                    'type' => 'string',
                    'description' => 'Le nouveau texte complet du passage, rédigé par toi.',
                ],
            ],
            ['document_id', 'block_id', 'text'],
        );
    }

    private static function insertBlock(): array
    {
        return self::schema(
            'insert_block',
            'INSÈRE un nouveau bloc après un bloc existant d’un document analysé. Utilise-le pour '
            .'ajouter un paragraphe, un titre, un tableau ou une légende. Les en-têtes, pieds de page '
            .'et renvois croisés ne peuvent pas être insérés dans le corps du document.',
            [
                'document_id' => self::documentId(),
                'position_block_id' => self::blockId(
                    'Identifiant du bloc APRÈS lequel insérer.'
                ),
                'type' => [
                    'type' => 'string',
                    'enum' => ToolWhitelist::insertableTypes(),
                    'description' => 'Type du bloc à insérer.',
                ],
                'content' => [
                    'type' => 'string',
                    'description' => 'Texte du bloc (paragraphe, titre ou légende).',
                ],
                'heading_level' => [
                    'type' => 'integer',
                    'description' => 'Niveau du titre (1 à 3), uniquement si type = heading.',
                ],
                'rows' => [
                    'type' => 'array',
                    'description' => 'Lignes du tableau, uniquement si type = table. '
                        .'Tableau de tableaux de textes : [["Poste", "Montant"], ["Fournitures", "125 000"]].',
                    'items' => ['type' => 'array', 'items' => ['type' => 'string']],
                ],
                'category' => [
                    'type' => 'string',
                    'enum' => ['Figure', 'Tableau', 'Annexe', 'Planche'],
                    'description' => 'Catégorie d’une légende, uniquement si type = caption.',
                ],
            ],
            ['document_id', 'position_block_id', 'type'],
        );
    }

    private static function modifyTable(): array
    {
        return self::schema(
            'modify_table',
            'MODIFIE la STRUCTURE d’un tableau d’un document analysé : ajouter ou supprimer une ligne, '
            .'ajouter une colonne, ou définir la valeur d’une cellule. '
            .'IMPORTANT : ne réécris jamais les montants, dates ou références d’un tableau de ta propre '
            .'initiative — fournis seulement la valeur qu’un utilisateur t’a explicitement donnée.',
            [
                'document_id' => self::documentId(),
                'block_id' => self::blockId('Identifiant du bloc tableau à modifier.'),
                'operation' => [
                    'type' => 'string',
                    'enum' => ['add_row', 'add_column', 'remove_row', 'set_header', 'set_cell'],
                    'description' => 'Opération de structure à appliquer.',
                ],
                'row' => [
                    'type' => 'integer',
                    'description' => 'Index de ligne (0 = première). Pour add_row, insère à cet index ; '
                        .'par défaut avant la dernière ligne.',
                ],
                'col' => [
                    'type' => 'integer',
                    'description' => 'Index de colonne (0 = première), pour set_cell.',
                ],
                'value' => [
                    'type' => 'string',
                    'description' => 'Valeur EXACTE fournie par l’utilisateur, pour set_cell uniquement.',
                ],
                'values' => [
                    'type' => 'array',
                    'description' => 'Valeurs de la nouvelle ligne ou colonne (facultatif).',
                    'items' => ['type' => 'string'],
                ],
            ],
            ['document_id', 'block_id', 'operation'],
        );
    }

    private static function deleteBlock(): array
    {
        return self::schema(
            'delete_block',
            'SUPPRIME un bloc d’un document analysé. Action destructrice mais ANNULABLE : un état '
            .'antérieur est enregistré automatiquement. Refusée si le bloc est le dernier du document, '
            .'s’il est décrit par une légende, ou s’il est visé par un renvoi croisé.',
            [
                'document_id' => self::documentId(),
                'block_id' => self::blockId('Identifiant du bloc à supprimer.'),
            ],
            ['document_id', 'block_id'],
        );
    }

    private static function regenerateSection(): array
    {
        $seuil = ToolWhitelist::confirmThreshold('regenerate_section') ?? 5;

        return self::schema(
            'regenerate_section',
            'RÉÉCRIT une section entière (plage de blocs) d’un document analysé. Utilise-le pour '
            .'retravailler un développement complet. Refusé si la plage contient un tableau, une figure '
            .'ou une légende, car leurs données ne se régénèrent pas. '
            .'Au-delà de '.$seuil.' blocs, une confirmation de l’utilisateur est demandée : '
            .'relance alors l’appel avec confirmed = true après son accord.',
            [
                'document_id' => self::documentId(),
                'start_block_id' => self::blockId('Premier bloc de la plage à remplacer.'),
                'end_block_id' => self::blockId('Dernier bloc de la plage à remplacer (inclus).'),
                'content' => [
                    'description' => 'Nouveau contenu : une chaîne (un paragraphe) ou un tableau de blocs.',
                    'oneOf' => [
                        ['type' => 'string'],
                        [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'type' => ['type' => 'string', 'enum' => ['paragraph', 'heading']],
                                    'text' => ['type' => 'string'],
                                    'heading_level' => ['type' => 'integer'],
                                ],
                                'required' => ['text'],
                            ],
                        ],
                    ],
                ],
                'confirmed' => self::confirmed('regenerate_section'),
            ],
            ['document_id', 'start_block_id', 'end_block_id', 'content'],
        );
    }

    private static function undoLastAction(): array
    {
        return self::schema(
            'undo_last_action',
            'ANNULE la dernière modification apportée au document analysé (restaure l’état antérieur '
            .'enregistré automatiquement). Utilise-le quand l’utilisateur dit que le résultat ne lui '
            .'convient pas ou demande de revenir en arrière.',
            [
                'document_id' => self::documentId(),
            ],
            ['document_id'],
        );
    }

    /**
     * Description du drapeau de confirmation.
     */
    private static function confirmed(string $tool): array
    {
        return [
            'type' => 'boolean',
            'description' => 'À positionner à true UNIQUEMENT après accord explicite de l’utilisateur. '
                .'Sans lui, une action dépassant le seuil est refusée et te demande de confirmer. '
                .'Ne réessaie jamais sans avoir obtenu cet accord.',
        ];
    }

    /**
     * Description commune du `document_id`.
     *
     * Explicite sur la différence avec `source_path` : la confusion entre un
     * document analysé et une pièce jointe ferait échouer chaque appel.
     */
    private static function documentId(): array
    {
        return [
            'type' => 'integer',
            'description' => 'Identifiant du document ANALYSÉ à éditer (table documents). '
                .'Ce n’est pas le chemin d’une pièce jointe : pour un fichier attaché à la '
                .'conversation, utilise document_edit.',
        ];
    }

    /**
     * Description commune d'un `block_id`.
     */
    private static function blockId(string $description = 'Identifiant du bloc visé.'): array
    {
        return ['type' => 'string', 'description' => $description];
    }

    /**
     * Assemble un schéma OpenAI au format attendu par `ChatToolsService`.
     *
     * @param  array<string, mixed>  $properties
     * @param  array<int, string>  $required
     * @return array<string, mixed>
     */
    private static function schema(string $name, string $description, array $properties, array $required): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $name,
                'description' => $description,
                'parameters' => [
                    'type' => 'object',
                    'properties' => $properties,
                    'required' => $required,
                ],
            ],
        ];
    }
}
