<?php

declare(strict_types=1);

namespace App\Document\Formatting;

use App\Document\Structure\Block;
use App\Services\DocumentGeneration\TemplateStyleResolver;

/**
 * Mise en forme des titres (tâche R3.2).
 *
 * Deux sorties complémentaires :
 *
 *  - une description **interne** (`style()`), utilisée par le moteur de gabarit
 *    et inspectable par les tests sans dépendance externe ;
 *  - des tableaux **au format PHPWord** (`font()` / `paragraph()`), délégués à
 *    `TemplateStyleResolver` — le projet possède déjà cette conversion et la
 *    dupliquer ferait diverger les deux chemins de rendu.
 *
 * Le niveau est toujours ramené dans l'intervalle 1–3. Au-delà, on réutilise le
 * style du niveau 3 : inventer des tailles décroissantes rendrait les titres
 * profonds illisibles, et Word lui-même ne distingue pas visuellement au-delà.
 */
final class HeadingFormatter
{
    /** Profondeur maximale gérée par le gabarit. */
    public const MAX_LEVEL = 3;

    /**
     * Style interne d'un titre.
     *
     * @param  array<string, mixed>  $gabarit  Gabarit normalisé
     * @return array<string, mixed>
     */
    public function style(Block $block, array $gabarit): array
    {
        $level = $this->normalizeLevel($block->headingLevel);
        $key = $this->key($level);

        return [
            'font_name' => $gabarit['police'],
            'font_size' => $gabarit['tailles'][$key] ?? $gabarit['tailles']['titre3'],
            'color' => $gabarit['couleurs'][$key] ?? $gabarit['couleurs']['titre1'],
            'bold' => true,
            'italic' => false,
            // Les titres ne sont jamais justifiés : un intitulé ferré à droite
            // ou étiré par la justification est un défaut visuel immédiat.
            'alignment' => $gabarit['alignement_titres'],
            'line_spacing' => $gabarit['interligne'],
            'space_before' => $gabarit['espacements']['avant_titre'],
            'space_after' => $gabarit['espacements']['apres_titre'],
            // Évite un titre isolé en bas de page, séparé de son contenu.
            'keep_with_next' => true,
            'heading_level' => $block->headingLevel,
        ];
    }

    /**
     * Style de police au format PHPWord, pour un niveau donné.
     *
     * Délégué à `TemplateStyleResolver` : une seule source de vérité pour la
     * conversion gabarit → PHPWord.
     *
     * @param  array<string, mixed>  $gabarit
     * @return array<string, mixed>
     */
    public function font(array $gabarit, int $level): array
    {
        return TemplateStyleResolver::fontStyle($gabarit, $this->key($this->normalizeLevel($level)));
    }

    /**
     * Style de paragraphe au format PHPWord (alignement, espacements, interligne).
     *
     * @param  array<string, mixed>  $gabarit
     * @return array<string, mixed>
     */
    public function paragraph(array $gabarit): array
    {
        return TemplateStyleResolver::titleParagraphStyle($gabarit);
    }

    /**
     * Clé de gabarit d'un niveau (`titre1`, `titre2`, `titre3`).
     */
    public function key(int $level): string
    {
        return 'titre'.$this->normalizeLevel($level);
    }

    /**
     * Ramène un niveau dans l'intervalle géré (1–3).
     *
     * Un niveau absent (titre non hiérarchisé) est traité comme niveau 1 : c'est
     * la lecture la plus probable, et la plus visible à la relecture.
     */
    public function normalizeLevel(?int $level): int
    {
        if ($level === null) {
            return 1;
        }

        return min(max($level, 1), self::MAX_LEVEL);
    }

    /**
     * Niveaux effectivement présents dans un document.
     *
     * Sert au rapport de traitement (« 3 niveaux de titres détectés »).
     *
     * @param  array<int, Block>  $blocks
     * @return array<int, int> Niveaux triés
     */
    public function levelsIn(array $blocks): array
    {
        $levels = [];

        foreach ($blocks as $block) {
            if ($block->headingLevel !== null) {
                $levels[$this->normalizeLevel($block->headingLevel)] = true;
            }
        }

        $result = array_keys($levels);
        sort($result);

        return $result;
    }
}
