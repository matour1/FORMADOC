<?php

declare(strict_types=1);

namespace App\Document\Formatting;

use App\Document\Structure\Block;
use App\Document\Structure\BlockType;

/**
 * Mise en flux des figures et de leurs légendes.
 *
 * **Aucun fichier image n'est touché** : pas de recadrage, pas de réencodage,
 * pas de redimensionnement destructif. Le formateur ne décide que de la
 * *position* des blocs dans le flux et de ce qui doit rester **solidaire**.
 *
 * Deux défauts concrets sont évités ici :
 *
 *  1. **La légende orpheline** — une légende séparée de sa figure par un saut
 *     de page (« Figure 3 » en bas d'une page, l'image en haut de la suivante)
 *     est une erreur de rendu classique et visible. On demande au moteur DOCX
 *     de garder les deux solidaires.
 *
 *  2. **La légende orpheline sans figure** — un bloc `caption` dont le bloc lié
 *     a disparu. On le signale plutôt que d'inventer une figure.
 */
final class FigureFlowFormatter
{
    /**
     * Calcule le plan de flux des figures et légendes.
     *
     * @param  array<int, Block>  $blocks  Blocs du document
     * @return array{
     *     entries: array<int, array<string, mixed>>,
     *     orphan_captions: array<int, string>,
     *     uncaptioned_figures: array<int, string>,
     *     images_untouched: true
     * }
     */
    public function plan(array $blocks): array
    {
        $entries = [];
        $orphanCaptions = [];
        $uncaptionedFigures = [];

        $linkedTargets = $this->linkedTargets($blocks);
        $figureIds = $this->figureIds($blocks);

        foreach ($blocks as $block) {
            if ($block->type === BlockType::Figure || $block->type === BlockType::Image) {
                $hasCaption = in_array($block->blockId, $linkedTargets, true);

                if (! $hasCaption) {
                    $uncaptionedFigures[] = $block->blockId;
                }

                $entries[] = $this->figureEntry($block, $hasCaption);

                continue;
            }

            if ($block->type === BlockType::Caption) {
                $targetId = $block->linkedBlockId;

                // Légende dont la cible n'existe pas (ou plus) : on ne la
                // supprime pas — elle porte peut-être une information utile —
                // mais on la laisse dans le flux normal et on la signale.
                if ($targetId === null || ! in_array($targetId, $figureIds, true)) {
                    $orphanCaptions[] = $block->blockId;
                }

                $entries[] = [
                    'block_id' => $block->blockId,
                    'kind' => 'caption',
                    'linked_block_id' => $targetId,
                    'keep_with_previous' => $targetId !== null && in_array($targetId, $figureIds, true),
                    'alignment' => 'center',
                    'number' => $block->displayNumber(),
                ];
            }
        }

        return [
            'entries' => $entries,
            'orphan_captions' => $orphanCaptions,
            'uncaptioned_figures' => $uncaptionedFigures,
            // Marqueur explicite : ce composant ne modifie jamais un fichier
            // image. L'écrire dans le résultat rend la garantie inspectable
            // (et vérifiable par test) plutôt que tacite.
            'images_untouched' => true,
        ];
    }

    /**
     * Description de flux d'une figure.
     *
     * @return array<string, mixed>
     */
    private function figureEntry(Block $block, bool $hasCaption): array
    {
        return [
            'block_id' => $block->blockId,
            'kind' => $block->type === BlockType::Figure ? 'figure' : 'image',
            'image_ref' => $block->imageRef,
            'linked_block_id' => $block->linkedBlockId,
            'has_caption' => $hasCaption,
            // Une figure avec légende reste solidaire de la suivante ; une
            // image décorative peut se placer librement.
            'keep_with_next' => $hasCaption,
            'alignment' => $block->type === BlockType::Figure ? 'center' : 'left',
            'number' => $block->displayNumber(),
            // Aucune transformation d'image n'est décrite : le générateur
            // réutilise le fichier d'origine tel quel.
            'transform' => null,
        ];
    }

    /**
     * Identifiants des blocs ciblés par une légende.
     *
     * @param  array<int, Block>  $blocks
     * @return array<int, string>
     */
    private function linkedTargets(array $blocks): array
    {
        $targets = [];

        foreach ($blocks as $block) {
            if ($block->type === BlockType::Caption && $block->linkedBlockId !== null) {
                $targets[] = $block->linkedBlockId;
            }
        }

        return $targets;
    }

    /**
     * Identifiants des blocs de type figure ou image.
     *
     * @param  array<int, Block>  $blocks
     * @return array<int, string>
     */
    private function figureIds(array $blocks): array
    {
        $ids = [];

        foreach ($blocks as $block) {
            if ($block->type === BlockType::Figure || $block->type === BlockType::Image) {
                $ids[] = $block->blockId;
            }
        }

        return $ids;
    }

    /**
     * Statistiques de flux, pour le rapport de traitement.
     *
     * Un document sans légende orpheline n'est pas nécessairement un bon
     * document — mais la présence de légendes orphelines signale toujours un
     * problème de lecture qu'il vaut mieux montrer que taire.
     *
     * @param  array<int, Block>  $blocks
     * @return array{figures: int, images: int, captions: int, orphan_captions: int, uncaptioned_figures: int}
     */
    public function statistics(array $blocks): array
    {
        $plan = $this->plan($blocks);
        $figures = 0;
        $images = 0;
        $captions = 0;

        foreach ($blocks as $block) {
            match ($block->type) {
                BlockType::Figure => $figures++,
                BlockType::Image => $images++,
                BlockType::Caption => $captions++,
                default => null,
            };
        }

        return [
            'figures' => $figures,
            'images' => $images,
            'captions' => $captions,
            'orphan_captions' => count($plan['orphan_captions']),
            'uncaptioned_figures' => count($plan['uncaptioned_figures']),
        ];
    }
}
