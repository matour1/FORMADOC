<?php

declare(strict_types=1);

namespace App\Document\Structure;

/**
 * Pont entre l'ancien format de structure et le schéma JSON structurel commun.
 *
 * Indispensable pour la migration (décision D6) : **35 structures de documents
 * existent en base** au format historique. Sans ce pont, brancher le nouveau
 * pipeline invaliderait les analyses déjà validées par les utilisateurs.
 *
 * Trois formats historiques coexistent en base, tous gérés ici :
 *
 * ```
 * 1. [titles, legends, titles_raw]      (8 enregistrements)
 *    → format « léger » : les titres sont un Markdown généré par l'IA,
 *      seules les légendes sont exploitables.
 *
 * 2. [titres, sous_titres, tableaux, images, en_tetes, pieds_de_page,
 *     elements_flottants, legends]       (11 enregistrements)
 *
 * 3. Format 2 + [body_complet]           (15 enregistrements)
 *    → format « complet » : chaque catégorie est une liste d'items
 *      structurés { texte, niveau, styles, position }.
 * ```
 *
 * Le pont est **volontairement tolérant** : il lit ce qui existe et ignore le
 * reste, plutôt que d'échouer sur un format légèrement différent. Une structure
 * partiellement convertie reste exploitable ; une exception ne le serait pas.
 */
final class LegacyStructureBridge
{
    /**
     * Correspondance entre les catégories historiques et les types de blocs.
     *
     * @var array<string, BlockType>
     */
    private const CATEGORY_TYPES = [
        'titres' => BlockType::Heading,
        'sous_titres' => BlockType::Heading,
        'tableaux' => BlockType::Table,
        'images' => BlockType::Image,
        'en_tetes' => BlockType::Header,
        'pieds_de_page' => BlockType::Footer,
        'elements_flottants' => BlockType::Image,
    ];

    /**
     * Convertit une structure historique en document structurel.
     *
     * Le format historique est **catégoriel** : il range les blocs par type
     * (titres, tableaux, images, légendes…) au lieu de les ordonner. L'ordre de
     * lecture réel est porté par le champ `position.element_index` de chaque
     * item — c'est donc lui qui sert à reconstituer la séquence, et non l'ordre
     * de parcours des catégories.
     *
     * @param  array<string, mixed>  $legacy  Structure issue de `document_structures.structure`
     * @param  string  $documentId  Identifiant du document d'origine
     * @return array{document: StructuralDocument, warnings: array<int, string>}
     *                                                                           Les avertissements signalent ce qui n'a pas pu être converti —
     *                                                                           jamais d'échec silencieux.
     */
    public function toStructural(array $legacy, string $documentId): array
    {
        $warnings = [];
        $blocks = [];
        $index = 0;

        // --- Titres et sous-titres ---
        foreach (['titres', 'sous_titres'] as $category) {
            foreach ($this->itemsOf($legacy, $category) as $item) {
                $block = $this->headingFrom($item, $index);

                if ($block === null) {
                    continue;
                }

                $blocks[] = $block;
                $index++;
            }
        }

        // --- Autres catégories structurées ---
        foreach (self::CATEGORY_TYPES as $category => $type) {
            if ($type === BlockType::Heading) {
                continue; // déjà traité
            }

            foreach ($this->itemsOf($legacy, $category) as $item) {
                $block = $this->contentFrom($item, $type, $index);

                if ($block === null) {
                    continue;
                }

                $blocks[] = $block;
                $index++;
            }
        }

        // --- Légendes ---
        foreach ($this->legendsOf($legacy) as $legend) {
            $block = $this->captionFrom($legend, $index);

            if ($block === null) {
                continue;
            }

            $blocks[] = $block;
            $index++;
        }

        // --- Signalement de ce qui n'a pas pu être converti ---
        if (isset($legacy['titles']) && ! isset($legacy['titres'])) {
            $warnings[] = 'Format historique « titles » : les titres étaient un rendu '
                .'Markdown généré par l\'IA, non convertibles. Seules les légendes '
                .'ont été reprises. Relancer une analyse reconstituera les titres.';
        }

        // Une structure vide n'est PAS une erreur : le format « markdown » ne
        // contient aucun élément exploitable, et une relance d'analyse suffit à
        // reconstituer la structure. Lever une exception rendrait le document
        // inaccessible, ce qui serait pire que de le signaler.
        if ($blocks === []) {
            $warnings[] = 'Aucun élément exploitable dans la structure historique. '
                .'Une nouvelle analyse est nécessaire pour ce document.';
        }

        // Restitution de l'ORDRE DE LECTURE. Le format historique étant
        // catégoriel, l'ordre de parcours ci-dessus mélange les types : sans ce
        // tri, une légende se retrouverait après toutes les images, et la
        // renumérotation produirait des numéros incohérents.
        if ($blocks !== []) {
            $blocks = $this->restoreReadingOrder($blocks);

            // Certains formats historiques (« structure », 11 enregistrements en
            // base) ne portent AUCUNE position de légende : leurs légendes ont
            // toutes la position 0 et se retrouvent donc regroupées en tête.
            // On le signale : l'utilisateur saura que relancer une analyse
            // donnera un ordre exact, et la renumérotation ne sera pas trompée
            // en silence.
            if ($this->hasLegendOrderGap($legacy, $blocks)) {
                $warnings[] = 'Les positions des légendes sont absentes de cette '
                    .'structure historique : leur ordre d\'apparition est approximatif. '
                    .'Relancer une analyse produira un ordre exact.';
            }
        }

        return [
            'document' => new StructuralDocument(
                documentId: $documentId,
                // L'ancien pipeline ne lisait que du `.docx` natif.
                sourceType: 'docx',
                blocks: $blocks,
                meta: [
                    'converted_from' => 'legacy',
                    'legacy_format' => $this->detectFormat($legacy),
                    'conversion_warnings' => $warnings,
                ],
            ),
            'warnings' => $warnings,
        ];
    }

    /**
     * Détecte lequel des trois formats historiques est présent.
     *
     * @param  array<string, mixed>  $legacy
     */
    public function detectFormat(array $legacy): string
    {
        if (isset($legacy['body_complet'])) {
            return 'complet';
        }

        if (isset($legacy['titres'])) {
            return 'structure';
        }

        if (isset($legacy['titles'])) {
            return 'markdown';
        }

        return 'inconnu';
    }

    /**
     * Sérialise un document structurel vers le format historique.
     *
     * Permet à l'ancien `DocumentReconstructor` de continuer à fonctionner
     * pendant la transition : c'est la garantie que les 677 tests restent verts
     * et que l'application fonctionne à chaque étape (principe du strangleur).
     *
     * @return array<string, mixed>
     */
    public function toLegacy(StructuralDocument $document): array
    {
        $legacy = [
            'titres' => [],
            'sous_titres' => [],
            'tableaux' => [],
            'images' => [],
            'en_tetes' => [],
            'pieds_de_page' => [],
            'elements_flottants' => [],
            'legends' => [],
            'body_complet' => [],
        ];

        foreach ($document->blocks as $block) {
            $item = $this->legacyItemFrom($block);

            switch ($block->type) {
                case BlockType::Heading:
                    // Le niveau 1 va dans `titres`, les suivants dans `sous_titres` :
                    // c'est la convention de l'ancien pipeline.
                    $key = ($block->headingLevel ?? 1) === 1 ? 'titres' : 'sous_titres';
                    $legacy[$key][] = $item;
                    break;

                case BlockType::Table:
                    $item['rows'] = $block->tableData?->cells ?? [];
                    $legacy['tableaux'][] = $item;
                    break;

                case BlockType::Image:
                    $legacy['images'][] = $item;
                    break;

                case BlockType::Figure:
                    // Une figure est une image AVEC légende : l'ancien format la
                    // range dans les images, la légende allant dans `legends`.
                    $legacy['images'][] = $item;
                    break;

                case BlockType::Header:
                    $legacy['en_tetes'][] = $item;
                    break;

                case BlockType::Footer:
                    $legacy['pieds_de_page'][] = $item;
                    break;

                case BlockType::Caption:
                    $legacy['legends'][] = [
                        'raw' => $block->text,
                        'line' => 0,
                        'type' => $block->effectiveCategory()?->keyword() ?? 'Figure',
                        'label' => $this->captionLabel($block->text),
                        'number' => $block->originalNumber ?? '',
                    ];
                    break;

                case BlockType::CrossRef:
                case BlockType::Paragraph:
                case BlockType::Annexe:
                case BlockType::Planche:
                    // Ces blocs n'ont pas de catégorie dédiée dans le format
                    // historique : ils vivent dans `body_complet`, qui porte
                    // l'ordre de lecture du document.
                    break;
            }

            // `body_complet` reçoit TOUS les blocs sauf les légendes (qui ont
            // leur propre collection) : c'est lui qui porte l'ordre de lecture
            // utilisé par le reconstructeur.
            if ($block->type !== BlockType::Caption) {
                $legacy['body_complet'][] = $item;
            }
        }

        return $legacy;
    }

    // -------------------------------------------------------------------------
    // Lecture du format historique
    // -------------------------------------------------------------------------

    /**
     * Items structurés d'une catégorie (tableau d'items, pas de chaîne).
     *
     * @param  array<string, mixed>  $legacy
     * @return array<int, array<string, mixed>>
     */
    private function itemsOf(array $legacy, string $category): array
    {
        $items = $legacy[$category] ?? [];

        if (! is_array($items)) {
            return [];
        }

        // Ignorer les formats où la catégorie est une chaîne (Markdown généré).
        return array_values(array_filter(
            $items,
            static fn (mixed $item): bool => is_array($item)
        ));
    }

    /**
     * Légendes du format historique.
     *
     * @param  array<string, mixed>  $legacy
     * @return array<int, array<string, mixed>>
     */
    private function legendsOf(array $legacy): array
    {
        return $this->itemsOf($legacy, 'legends');
    }

    /**
     * Construit un bloc de titre depuis un item historique.
     *
     * @param  array<string, mixed>  $item
     */
    private function headingFrom(array $item, int $index): ?Block
    {
        $text = $this->textOf($item);

        if ($text === '') {
            return null;
        }

        // Le niveau vient de `niveau` (explicite) ou de `depth` (profondeur
        // technique de Word) : le premier est plus fiable.
        $level = $item['niveau'] ?? $item['depth'] ?? 1;

        return new Block(
            blockId: $this->blockId($index),
            type: BlockType::Heading,
            text: $text,
            headingLevel: max(1, (int) $level),
            fontSize: $this->fontSizeOf($item),
            isBold: $this->isBoldOf($item),
            positionY: $this->elementIndexOf($item),
            fidelity: Fidelity::Exact,
            // Conversion d'un format validé par l'utilisateur : la confiance
            // reflète cette validation.
            confidence: 0.95,
        );
    }

    /**
     * Construit un bloc de contenu (tableau, image, en-tête…) depuis un item.
     *
     * @param  array<string, mixed>  $item
     */
    private function contentFrom(array $item, BlockType $type, int $index): ?Block
    {
        // Un tableau peut être fourni avec ou sans contenu de cellules.
        $tableData = null;
        if ($type === BlockType::Table) {
            $rows = $item['rows'] ?? $item['cells'] ?? [];

            // Tableau sans contenu : on le signale par un contenu vide plutôt
            // que de l'ignorer, pour ne pas perdre la référence.
            $tableData = is_array($rows) && $rows !== []
                ? TableData::fromGrid($this->normalizeRows($rows))
                : new TableData(rows: (int) ($item['rows_count'] ?? 0), cols: 0);
        }

        $text = $this->textOf($item);

        // Les catégories non textuelles (images, en-têtes) peuvent n'avoir ni
        // texte ni contenu : on les conserve tout de même, leur position et
        // leur présence constituent une information.
        return new Block(
            blockId: $this->blockId($index),
            type: $type,
            text: $text,
            fontSize: $this->fontSizeOf($item),
            isBold: $this->isBoldOf($item),
            positionY: $this->elementIndexOf($item),
            fidelity: Fidelity::Exact,
            confidence: 0.9,
            tableData: $tableData,
            imageRef: $type === BlockType::Image || $type === BlockType::Figure
                ? $this->imageRefOf($item)
                : null,
        );
    }

    /**
     * Construit un bloc de légende depuis un item historique.
     *
     * Format historique : `{raw, line, type, label, number}`.
     *
     * @param  array<string, mixed>  $item
     */
    private function captionFrom(array $item, int $index): ?Block
    {
        $raw = trim((string) ($item['raw'] ?? ''));

        if ($raw === '') {
            return null;
        }

        $category = BlockCategory::fromKeyword((string) ($item['type'] ?? 'Figure'));

        return new Block(
            blockId: $this->blockId($index),
            type: BlockType::Caption,
            text: $raw,
            positionY: (float) ($item['line'] ?? 0),
            fidelity: Fidelity::Exact,
            confidence: 0.95,
            // Catégorie : déduite du mot-clé, avec Figure par défaut (le format
            // historique utilise toujours un mot-clé reconnu).
            category: $category ?? BlockCategory::Figure,
            originalNumber: isset($item['number']) ? (string) $item['number'] : null,
        );
    }

    // -------------------------------------------------------------------------
    // Écriture du format historique
    // -------------------------------------------------------------------------

    /**
     * Convertit un bloc vers le format d'item historique.
     *
     * @return array<string, mixed>
     */
    private function legacyItemFrom(Block $block): array
    {
        return [
            'type' => $block->type->value,
            'depth' => $block->headingLevel,
            'texte' => $block->text,
            'text' => $block->text, // les deux clés coexistent dans l'historique
            'niveau' => $block->headingLevel,
            'styles' => [
                'font' => [
                    'name' => null,
                    'basic' => [
                        'name' => null,
                        'size' => $block->fontSize,
                        'color' => null,
                    ],
                    'style' => [
                        'bold' => $block->isBold,
                        'italic' => null,
                        'underline' => 'none',
                    ],
                ],
                'paragraph' => null,
            ],
            'position' => [
                'parent' => 'body',
                'element_index' => (int) ($block->positionY ?? 0),
                'section_index' => 0,
            ],
        ];
    }

    /**
     * Détecte l'absence de positions exploitables pour les légendes.
     *
     * Le format « structure » (11 enregistrements en base) ne stocke pas la
     * ligne de chaque légende : elles portent toutes la position 0. Leur ordre
     * d'apparition est alors indéterminable, ce qu'il est honnête de signaler
     * plutôt que de présenter un ordre arbitraire comme définitif.
     *
     * @param  array<string, mixed>  $legacy
     * @param  array<int, Block>  $blocks
     */
    private function hasLegendOrderGap(array $legacy, array $blocks): bool
    {
        $hasCaptions = false;
        $captionsWithPosition = 0;
        $captionCount = 0;

        foreach ($blocks as $block) {
            if ($block->type !== BlockType::Caption) {
                continue;
            }

            $hasCaptions = true;
            $captionCount++;

            if (($block->positionY ?? 0.0) > 0.0) {
                $captionsWithPosition++;
            }
        }

        // Le manque n'est réel que s'il y a des légendes ET qu'aucune ne porte
        // de position — un seul `line` renseigné suffit à établir l'ordre.
        return $hasCaptions
            && $captionCount > 1
            && $captionsWithPosition === 0
            && $this->legendsOf($legacy) !== [];
    }

    /**
     * Restitue l'ordre de lecture du document à partir des positions.
     *
     * **Problème résolu.** Le format historique est catégoriel : les blocs y
     * sont rangés par TYPE (tous les titres ensemble, toutes les images
     * ensemble, toutes les légendes ensemble). L'ordre réel n'existe que dans
     * `position.element_index` (et `line` pour les légendes).
     *
     * Sans cette restauration, une légende se retrouverait après toutes les
     * images, ce qui fausserait complètement la renumérotation (R4) : les
     * compteurs de figures devraient suivre l'ordre d'apparition dans le corps
     * du texte, pas l'ordre des catégories.
     *
     * Le tri est **stable** (l'ordre de départ départage les positions égales),
     * car plusieurs blocs partagent souvent la même position : un titre et son
     * tableau, par exemple.
     *
     * @param  array<int, Block>  $blocks
     * @return array<int, Block>
     */
    private function restoreReadingOrder(array $blocks): array
    {
        // Sans aucune position exploitable, l'ordre catégoriel est conservé :
        // trois catégories importantes (titres, tableaux, légendes) restent
        // dans un ordre plausible, et inventer un ordre serait pire.
        $hasPositions = false;
        foreach ($blocks as $block) {
            if ($block->positionY !== null) {
                $hasPositions = true;
                break;
            }
        }

        if (! $hasPositions) {
            return $blocks;
        }

        // Tri stable : on mémorise l'index initial pour départager les égalités.
        $indexed = [];
        foreach ($blocks as $position => $block) {
            $indexed[] = ['origin' => $position, 'block' => $block];
        }

        usort($indexed, static function (array $a, array $b): int {
            $compare = ($a['block']->positionY ?? 0.0) <=> ($b['block']->positionY ?? 0.0);

            return $compare !== 0 ? $compare : $a['origin'] <=> $b['origin'];
        });

        return array_map(static fn (array $entry): Block => $entry['block'], $indexed);
    }

    /**
     * Extrait le texte d'un item historique (`texte` ou `text`).
     *
     * @param  array<string, mixed>  $item
     */
    private function textOf(array $item): string
    {
        $text = $item['texte'] ?? $item['text'] ?? $item['raw'] ?? '';

        return trim(is_scalar($text) ? (string) $text : '');
    }

    /**
     * Taille de police d'un item (`styles.font.basic.size`).
     *
     * @param  array<string, mixed>  $item
     */
    private function fontSizeOf(array $item): ?float
    {
        $size = $item['styles']['font']['basic']['size'] ?? null;

        return is_numeric($size) ? (float) $size : null;
    }

    /**
     * Gras d'un item (`styles.font.style.bold`).
     *
     * @param  array<string, mixed>  $item
     */
    private function isBoldOf(array $item): bool
    {
        return ($item['styles']['font']['style']['bold'] ?? false) === true;
    }

    /**
     * Index d'élément d'un item (`position.element_index`).
     *
     * @param  array<string, mixed>  $item
     */
    private function elementIndexOf(array $item): ?float
    {
        $index = $item['position']['element_index'] ?? null;

        return is_numeric($index) ? (float) $index : null;
    }

    /**
     * Référence d'image d'un item.
     *
     * @param  array<string, mixed>  $item
     */
    private function imageRefOf(array $item): ?string
    {
        $name = $item['image_name'] ?? $item['name'] ?? null;

        return is_scalar($name) ? (string) $name : null;
    }

    /**
     * Normalise des lignes de tableau hétérogènes vers une grille de chaînes.
     *
     * @param  array<int|string, mixed>  $rows
     * @return array<int, array<int, string>>
     */
    private function normalizeRows(array $rows): array
    {
        $grid = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $cells = [];
            foreach ($row as $cell) {
                $cells[] = is_scalar($cell) ? (string) $cell : '';
            }

            $grid[] = $cells;
        }

        return $grid;
    }

    /**
     * Libellé d'une légende, sans le préfixe de numérotation.
     *
     * « Figure 1 : Architecture » → « Architecture »
     */
    private function captionLabel(string $text): string
    {
        return trim((string) preg_replace(
            '/^(?:Légende|Legende|Figure|Tableau|Annexe|Planche)\s*(?:\d+|[A-Z])?\s*[:.\-–—]?\s*/iu',
            '',
            $text
        ));
    }

    /**
     * Identifiant de bloc au même format que le parseur natif (`b_0001`).
     */
    private function blockId(int $index): string
    {
        return sprintf('b_%04d', $index + 1);
    }
}
