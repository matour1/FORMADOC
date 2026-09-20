<?php

declare(strict_types=1);

namespace App\DocAnalyzer;

/**
 * Fusion déterministe des résultats des deux analyseurs.
 *
 * Stratégie : les règles (déterministes) font foi ; l'IA n'ajoute QUE les
 * éléments manquants. Un élément est considéré "déjà couvert" s'il existe
 * dans le résultat des règles un item avec le même element_index ET le même
 * parent (le texte et le type servent de garde-fou supplémentaire).
 *
 * Après fusion, chaque catégorie est triée par position (section_index puis
 * element_index) pour garantir un ordre stable et reproductible.
 */
class ResultMerger
{
    /**
     * Fusionne le résultat des règles et celui de l'IA.
     *
     * @param  array<string, array<int, array<string, mixed>>>  $rulesResult
     * @param  array<string, array<int, array<string, mixed>>>  $iaResult
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function merge(array $rulesResult, array $iaResult): array
    {
        $merged = AnalyzerResult::empty();

        foreach (AnalyzerResult::CATEGORIES as $category) {
            $items = $rulesResult[$category] ?? [];
            $iaItems = $iaResult[$category] ?? [];

            // Clés déjà couvertes par les règles : (element_index, parent)
            $covered = [];
            foreach ($items as $item) {
                $key = $this->itemKey($item);
                if ($key !== null) {
                    $covered[$key] = true;
                }
            }

            foreach ($iaItems as $iaItem) {
                $key = $this->itemKey($iaItem);
                if ($key !== null && isset($covered[$key])) {
                    continue; // Déjà détecté par les règles : on garde la version règles
                }

                // Nouvel élément apporté par l'IA : on l'ajoute
                $items[] = $iaItem;
                if ($key !== null) {
                    $covered[$key] = true;
                }
            }

            // Tri stable par position (section_index, puis element_index)
            usort($items, static function (array $a, array $b): int {
                $pa = $a['position'] ?? [];
                $pb = $b['position'] ?? [];

                $sa = (int) ($pa['section_index'] ?? 0);
                $sb = (int) ($pb['section_index'] ?? 0);
                if ($sa !== $sb) {
                    return $sa <=> $sb;
                }

                $ea = (int) ($pa['element_index'] ?? 0);
                $eb = (int) ($pb['element_index'] ?? 0);

                return $ea <=> $eb;
            });

            $merged[$category] = array_values($items);
        }

        return $merged;
    }

    /**
     * Clé de déduplication d'un item : (element_index, parent).
     *
     * @param  array<string, mixed>  $item
     */
    private function itemKey(array $item): ?string
    {
        $position = $item['position'] ?? null;
        if (! is_array($position)) {
            return null;
        }

        $elementIndex = $position['element_index'] ?? null;
        $parent = $position['parent'] ?? '';

        if ($elementIndex === null) {
            return null;
        }

        return (int) $elementIndex.':'.$parent;
    }
}
