<?php

declare(strict_types=1);

namespace App\Document\Formatting;

use App\Document\Structure\Block;
use App\Document\Structure\BlockCategory;
use App\Document\Structure\BlockType;
use App\Document\Structure\TableData;

/**
 * Traduit une description de rendu vers le format d'analyse de `DocumentReconstructor` (tâche R3.7).
 *
 * **Pourquoi un adaptateur plutôt qu'une réécriture de `DocumentReconstructor` ?**
 * Le reconstructeur fonctionne, il est couvert par des tests, et il porte des
 * post-traitements DOCX non triviaux (formats de numérotation romaine/arabe,
 * `gridSpan` sur les cellules fusionnées, champs `SEQ`, conservation des
 * fichiers temporaires d'images jusqu'au `save()` final). Le réécrire
 * introduirait des régressions pour aucun gain. On lui fournit donc l'entrée
 * qu'il attend, en reprenant **exactement** ses clés :
 *
 * | Bloc du pipeline   | `type` de `body_complet` | Clés attendues                  |
 * |--------------------|--------------------------|---------------------------------|
 * | `heading`          | `titre`                  | `depth` (1–3)                   |
 * | `paragraph`        | `texte`                  | —                               |
 * | `table`            | `tableau`                | `rows[]['cells']`               |
 * | `caption`          | `legende`                | — (les listes viennent de `legends`) |
 * | `figure` / `image` | `image`                  | `image_data`, `image_name`, `image_extension` |
 * | `cross_ref`        | `texte`                  | résolu en texte (R4)            |
 *
 * **Aucun contenu n'est transformé** : seul l'emballage change. Le texte d'un
 * paragraphe, les cellules d'un tableau et le binaire d'une image traversent
 * cet adaptateur tels quels.
 *
 * Le binaire d'image est un point sensible : la source structurelle ne conserve
 * qu'une **référence** (`img_001.png`), pas de contenu. On transmet donc la
 * valeur fournie par l'appelant dans `$images`, sans jamais réencoder — une
 * correspondance absente produit un bloc image sans données plutôt qu'une image
 * inventée.
 */
final class ReconstructionPayloadBuilder
{
    /**
     * Construit la charge utile complète pour `DocumentReconstructor::reconstruct()`.
     *
     * @param  RenderedDocument  $rendered  Description issue du moteur de gabarit
     * @param  array<string, array{data: string, extension: string}>  $images  Binaire base64 par référence d'image
     * @return array{analysis: array<string, mixed>, gabarit: array<string, mixed>}
     */
    public function build(RenderedDocument $rendered, array $images = []): array
    {
        $blocks = $rendered->blocks;

        return [
            'analysis' => [
                // Le corps ordonné : c'est lui que le reconstructeur parcourt.
                'body_complet' => $this->bodyComplet($blocks, $images),

                // Catégories attendues par le frontispice et les en-têtes.
                'titres' => $this->titles($blocks),
                'sous_titres' => [],
                // Clé `legends` : c'est le nom attendu par le reconstructeur,
                // quelle que soit la langue du reste du projet.
                'legends' => $this->legends($blocks),
                'en_tetes' => $this->textsOfType($blocks, BlockType::Header),
                'pieds_de_page' => $this->textsOfType($blocks, BlockType::Footer),

                // Traçabilité pour le rapport de traitement.
                'meta' => [
                    'source_type' => $rendered->sourceType,
                    'fidelity' => $rendered->fidelity->value,
                    'block_count' => count($blocks),
                ],
            ],
            'gabarit' => $rendered->template,
        ];
    }

    /**
     * Corps ordonné, dans l'ordre du document.
     *
     * Une seule passe, sans regroupement par type : **l'ordre définit la
     * numérotation** des figures et tableaux (R4). Regrouper par type
     * produirait des numéros faux.
     *
     * @param  array<int, Block>  $blocks
     * @param  array<string, array{data: string, extension: string}>  $images
     * @return array<int, array<string, mixed>>
     */
    private function bodyComplet(array $blocks, array $images): array
    {
        $elements = [];

        foreach (array_values($blocks) as $index => $block) {
            $element = $this->element($block, $index, $images);

            if ($element !== null) {
                $elements[] = $element;
            }
        }

        return $elements;
    }

    /**
     * Traduit un bloc en élément `body_complet`.
     *
     * @param  array<string, array{data: string, extension: string}>  $images
     * @return null|array<string, mixed> null pour les blocs hors corps
     */
    private function element(Block $block, int $index, array $images): ?array
    {
        // `position` permet au reconstructeur de relier un élément à un titre
        // détecté par ailleurs : on la reconstruit depuis l'ordre du document.
        $position = ['element_index' => $index];

        return match ($block->type) {
            BlockType::Heading => [
                'type' => 'titre',
                'text' => $block->text,
                'depth' => $this->headingDepth($block),
                'position' => $position,
                'block_id' => $block->blockId,
            ],

            BlockType::Paragraph, BlockType::CrossRef => [
                'type' => 'texte',
                'text' => $block->text,
                'position' => $position,
                'block_id' => $block->blockId,
            ],

            BlockType::Table => [
                'type' => 'tableau',
                'text' => $block->text,
                // Le reconstructeur attend `rows[]['cells']` : les données sont
                // transmises cellule par cellule, sans reformulation.
                'rows' => $this->tableRows($block->tableData),
                'position' => $position,
                'block_id' => $block->blockId,
            ],

            BlockType::Caption, BlockType::Annexe, BlockType::Planche => [
                'type' => 'legende',
                'text' => $block->text,
                'linked_block_id' => $block->linkedBlockId,
                'position' => $position,
                'block_id' => $block->blockId,
            ],

            BlockType::Figure, BlockType::Image => $this->imageElement($block, $position, $images),

            // En-têtes et pieds sont rendus par `applyHeaderFooter`, pas dans le corps.
            BlockType::Header, BlockType::Footer => null,
        };
    }

    /**
     * Élément image, avec le binaire s'il est fourni.
     *
     * Le binaire n'est jamais inventé ni réencodé : s'il manque, le
     * reconstructeur affichera « [Image: nom] ». C'est préférable à une image
     * vide ou à un plantage.
     *
     * @param  array<string, mixed>  $position
     * @param  array<string, array{data: string, extension: string}>  $images
     * @return array<string, mixed>
     */
    private function imageElement(Block $block, array $position, array $images): array
    {
        $ref = $block->imageRef;

        $element = [
            'type' => 'image',
            'text' => $block->text,
            'image_name' => $ref === null ? 'image' : basename($ref),
            'image_extension' => $ref === null ? 'png' : $this->extensionOf($ref),
            'position' => $position,
            'block_id' => $block->blockId,
        ];

        if ($ref !== null && isset($images[$ref])) {
            $element['image_data'] = $images[$ref]['data'];
            $element['image_extension'] = $images[$ref]['extension'];
        }

        return $element;
    }

    /**
     * Extension d'une référence d'image, normalisée en minuscules.
     *
     * Word produit parfois des parties `.tmp` : l'extension annoncée n'est donc
     * qu'un repli, le format réel étant détecté par signature binaire en amont.
     */
    private function extensionOf(string $ref): string
    {
        $extension = strtolower(pathinfo($ref, PATHINFO_EXTENSION));

        return $extension === '' ? 'png' : $extension;
    }

    /**
     * Lignes → cellules, au format exact attendu par le reconstructeur.
     *
     * La grille est parcourue telle quelle : **aucun texte n'est retouché**.
     *
     * @return array<int, array{cells: array<int, string>}>
     */
    private function tableRows(?TableData $table): array
    {
        if ($table === null) {
            return [];
        }

        $rows = [];

        for ($row = 0; $row < $table->rows; $row++) {
            $cells = [];

            for ($col = 0; $col < $table->cols; $col++) {
                $cells[] = $table->cell($row, $col);
            }

            $rows[] = ['cells' => $cells];
        }

        return $rows;
    }

    /**
     * Niveau de titre ramené dans l'intervalle géré par le reconstructeur.
     */
    private function headingDepth(Block $block): int
    {
        return min(HeadingFormatter::MAX_LEVEL, max(1, $block->headingLevel ?? 1));
    }

    /**
     * Titres du document, au format attendu par le repli du corps.
     *
     * @param  array<int, Block>  $blocks
     * @return array<int, array<string, mixed>>
     */
    private function titles(array $blocks): array
    {
        $titles = [];

        foreach (array_values($blocks) as $index => $block) {
            if ($block->type !== BlockType::Heading) {
                continue;
            }

            $titles[] = [
                'texte' => $block->text,
                'niveau' => $this->headingDepth($block),
                'position' => ['element_index' => $index],
            ];
        }

        return $titles;
    }

    /**
     * Légendes, au format attendu par les listes de figures et de tableaux.
     *
     * Trois clés sont consommées par le reconstructeur : `type` (pour filtrer),
     * `number` (le numéro recalculé par R4) et `label` (le texte de la légende).
     *
     * Les blocs `annexe` et `planche` sont inclus : ils alimentent les listes
     * correspondantes exactement comme les figures et les tableaux.
     *
     * @param  array<int, Block>  $blocks
     * @return array<int, array<string, mixed>>
     */
    private function legends(array $blocks): array
    {
        $legends = [];

        foreach (array_values($blocks) as $index => $block) {
            if (! in_array($block->type, [BlockType::Caption, BlockType::Annexe, BlockType::Planche], true)) {
                continue;
            }

            $legends[] = [
                // Vocabulaire du reconstructeur : « figure », « tableau »…
                'type' => $this->legendType($block),
                'number' => $block->displayNumber() ?? '?',
                'label' => $this->labelOf($block),
                'linked_block_id' => $block->linkedBlockId,
                'position' => ['element_index' => $index],
            ];
        }

        return $legends;
    }

    /**
     * Type de légende reconnu par le reconstructeur.
     *
     * La catégorie du bloc est prioritaire ; à défaut, on déduit du type de
     * bloc (`annexe` → annexe, `planche` → planche, sinon figure).
     */
    private function legendType(Block $block): string
    {
        $category = $block->effectiveCategory() ?? $block->category;

        if ($category instanceof BlockCategory) {
            return $category->keywordLowerCase();
        }

        return match ($block->type) {
            BlockType::Annexe => BlockCategory::Annexe->keywordLowerCase(),
            BlockType::Planche => BlockCategory::Planche->keywordLowerCase(),
            BlockType::Table => BlockCategory::Table->keywordLowerCase(),
            default => BlockCategory::Figure->keywordLowerCase(),
        };
    }

    /**
     * Texte de la légende, sans son numéro.
     *
     * Le reconstructeur recompose lui-même « Figure 3 : {label} » : conserver
     * « Figure 3 » dans le libellé produirait « Figure 3 : Figure 3 — Schéma ».
     * On retire donc le préfixe de numérotation, sans toucher au reste.
     */
    private function labelOf(Block $block): string
    {
        $text = trim($block->text);

        if ($text === '') {
            return '';
        }

        // Préfixe « Figure 3 », « Tableau 2.1 », « ANNEXE IV »… suivi d'un
        // séparateur optionnel (- – — : ou .).
        $pattern = '/^(figure|tableau|annexe|planche|table|fig\.?)\s+[0-9A-Z]+(?:\.[0-9]+)*\s*[-–—:.]?\s*/iu';

        $cleaned = preg_replace($pattern, '', $text);

        return $cleaned === null ? $text : trim($cleaned);
    }

    /**
     * Textes des blocs d'un type donné, pour les en-têtes et pieds de page.
     *
     * @param  array<int, Block>  $blocks
     * @return array<int, array<string, mixed>>
     */
    private function textsOfType(array $blocks, BlockType $type): array
    {
        $texts = [];

        foreach ($blocks as $block) {
            if ($block->type === $type && trim($block->text) !== '') {
                $texts[] = ['texte' => $block->text];
            }
        }

        return $texts;
    }
}
