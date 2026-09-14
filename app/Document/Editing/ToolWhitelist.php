<?php

declare(strict_types=1);

namespace App\Document\Editing;

use App\Document\Structure\BlockType;

/**
 * Liste blanche des tools d'édition (garde-fou §9.14).
 *
 * **Pourquoi une liste blanche et non une liste noire** — un modèle de langage
 * peut demander n'importe quel outil, y compris un outil inventé. Une liste noire
 * devrait anticiper toutes les inventions ; une liste blanche n'accepte que ce
 * qui est explicitement prévu. C'est la seule approche défendable quand l'appelant
 * n'est pas fiable par construction.
 *
 * **L'énumération est unique et fait autorité.** Le test d'architecture
 * (`ArchitectureConstraintsTest`) vérifie que le répertoire `Tools/` contient
 * exactement ces fichiers : ajouter un tool sans l'inscrire ici **fait échouer
 * la suite**. C'est volontaire — un nouvel outil d'édition est une décision de
 * conception, pas un détail d'implémentation.
 */
final class ToolWhitelist
{
    /**
     * Tools d'édition autorisés, avec leurs métadonnées.
     *
     * `destructive` indique qu'une annulation doit être possible : ces tools
     * exigent un snapshot préalable (§9.7).
     * `confirm_threshold` est le nombre de blocs au-delà duquel une confirmation
     * utilisateur est requise (§9.16) ; null = jamais de confirmation.
     */
    private const TOOLS = [
        'rewrite_paragraph' => [
            'class' => 'App\Document\Editing\Tools\RewriteParagraphTool',
            'destructive' => false,
            'confirm_threshold' => null,
            'description' => 'Réécrit le texte d’un paragraphe existant.',
        ],
        'insert_block' => [
            'class' => 'App\Document\Editing\Tools\InsertBlockTool',
            'destructive' => false,
            'confirm_threshold' => null,
            'description' => 'Insère un nouveau bloc après un bloc existant.',
        ],
        'modify_table' => [
            'class' => 'App\Document\Editing\Tools\ModifyTableTool',
            'destructive' => true,
            'confirm_threshold' => null,
            'description' => 'Modifie la structure d’un tableau (lignes, colonnes, en-tête).',
        ],
        'delete_block' => [
            'class' => 'App\Document\Editing\Tools\DeleteBlockTool',
            'destructive' => true,
            // §9.16 : « confirmation si `delete_block` ou `regenerate_section`
            // > 5 blocs ». Le tool ne supprime aujourd'hui qu'un bloc à la fois,
            // donc le seuil n'est jamais atteint — il reste posé comme garde-fou
            // si une variante multi-blocs voit le jour.
            'confirm_threshold' => 5,
            'description' => 'Supprime un bloc du document.',
        ],
        'regenerate_section' => [
            'class' => 'App\Document\Editing\Tools\RegenerateSectionTool',
            'destructive' => true,
            'confirm_threshold' => 5,
            'description' => 'Réécrit une section entière (plage de blocs).',
        ],
    ];

    /**
     * Le tool `ask_user_clarification` est partagé avec la classification (R2) :
     * il ne vit pas dans `Editing/Tools/` et n'apparaît donc pas ci-dessus.
     * Il est néanmoins autorisé en édition — d'où cette liste complète.
     *
     * `undo_last_action` n'est pas un tool d'édition non plus : il **annule** une
     * édition. Il est traité par le même chemin (liste blanche + orchestrateur)
     * parce qu'il agit sur le même document et doit bénéficier des mêmes
     * garde-fous — mais il ne figure pas dans `editingToolNames()`, sans quoi le
     * test d'architecture exigerait un fichier `UndoLastActionTool.php`
     * inexistant.
     *
     * @return array<int, string>
     */
    public static function names(): array
    {
        return [...array_keys(self::TOOLS), 'ask_user_clarification', 'undo_last_action'];
    }

    /**
     * Les tools d'édition seuls (sans le tool de clarification partagé).
     *
     * @return array<int, string>
     */
    public static function editingToolNames(): array
    {
        return array_keys(self::TOOLS);
    }

    /**
     * Le tool est-il autorisé ?
     *
     * `undo_last_action` est autorisé bien qu'il ne soit pas dans `self::TOOLS` :
     * il annule une édition au lieu d'en produire une, et suit le même chemin de
     * garde-fous.
     */
    public static function allows(string $name): bool
    {
        return in_array($name, self::names(), true);
    }

    /**
     * Nom de classe d'un tool autorisé.
     *
     * @throws EditingException Si le tool n'est pas autorisé
     */
    public static function classFor(string $name): string
    {
        if (! isset(self::TOOLS[$name])) {
            throw EditingException::outilNonAutorise($name);
        }

        return self::TOOLS[$name]['class'];
    }

    /**
     * Le tool exige-t-il un snapshot préalable ?
     */
    public static function isDestructive(string $name): bool
    {
        return (bool) (self::TOOLS[$name]['destructive'] ?? false);
    }

    /**
     * Seuil de blocs au-delà duquel une confirmation est requise.
     *
     * @return null|int null si le tool ne demande jamais confirmation
     */
    public static function confirmThreshold(string $name): ?int
    {
        return self::TOOLS[$name]['confirm_threshold'] ?? null;
    }

    /**
     * Le nombre de blocs visés exige-t-il une confirmation ?
     *
     * Sémantique du seuil, telle qu'attendue par le §9.16 : « delete_block sur
     * 1 bloc passe ; regenerate_section sur 6 blocs demande confirmation ». Un
     * seuil de 1 signifie donc que TOUTE suppression demande confirmation, et un
     * seuil de 5 qu'à partir de 6 blocs.
     */
    public static function requiresConfirmation(string $name, int $blockCount): bool
    {
        $seuil = self::confirmThreshold($name);

        return $seuil !== null && $blockCount > $seuil;
    }

    /**
     * Description d'un tool, pour exposer le catalogue au modèle.
     *
     * @return array<string, mixed>
     *
     * @throws EditingException Si le tool n'est pas autorisé
     */
    public static function describe(string $name): array
    {
        if (! isset(self::TOOLS[$name])) {
            throw EditingException::outilNonAutorise($name);
        }

        return [
            'name' => $name,
            'class' => self::TOOLS[$name]['class'],
            'destructive' => self::TOOLS[$name]['destructive'],
            'confirm_threshold' => self::TOOLS[$name]['confirm_threshold'],
            'description' => self::TOOLS[$name]['description'],
        ];
    }

    /**
     * Catalogue complet, pour l'exposition au modèle.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function catalogue(): array
    {
        return array_map(
            static fn (string $name): array => self::describe($name),
            self::editingToolNames()
        );
    }

    /**
     * Types de blocs qu'un tool peut insérer.
     *
     * Délégué à `BlockType::insertable()` : une seule source de vérité, sinon
     * le tool d'insertion et le validateur de schéma divergeraient.
     *
     * @return array<int, string>
     */
    public static function insertableTypes(): array
    {
        return array_map(
            static fn (BlockType $type): string => $type->value,
            BlockType::insertable()
        );
    }
}
