<?php

declare(strict_types=1);

namespace App\Services\Detection;

/**
 * Applique les corrections validées par l'utilisateur à la structure
 * détectée (Phase 4).
 *
 * Chaque correction est adressée par la clé de position d'un item :
 *   - « 1 »  → promouvoir l'item en titre (niveau 1)
 *   - « 2 »  → rétrograder l'item en sous-titre (niveau 2)
 *   - « 3 »  → rétrograder en sous-titre de niveau 3
 *   - « remove » → retirer l'item du plan
 */
class StructureCorrectionService
{
    /**
     * @param array<string, mixed> $structure
     * @param array<string, string> $corrections clé de position → action
     *
     * @return array<string, mixed>
     */
    public function apply(array $structure, array $corrections): array
    {
        $titres = [];
        $sousTitres = [];

        foreach (($structure['titres'] ?? []) as $item) {
            $this->repartir($item, 'titres', $corrections, $titres, $sousTitres);
        }

        foreach (($structure['sous_titres'] ?? []) as $item) {
            $this->repartir($item, 'sous_titres', $corrections, $titres, $sousTitres);
        }

        $structure['titres'] = $this->trier($titres);
        $structure['sous_titres'] = $this->trier($sousTitres);

        return $structure;
    }

    /**
     * Répartit un item entre titres et sous-titres selon sa correction.
     *
     * @param array<string, mixed> $item
     * @param array<string, string> $corrections
     * @param array<int, array<string, mixed>> $titres
     * @param array<int, array<string, mixed>> $sousTitres
     */
    private function repartir(
        array $item,
        string $categorie,
        array $corrections,
        array &$titres,
        array &$sousTitres
    ): void {
        $key = AmbiguityDetectionService::itemKey($item);
        $action = $corrections[$key] ?? null;

        if ($action === 'remove') {
            return; // item retiré du plan
        }

        $cible = $categorie;

        if ($action !== null) {
            $item['niveau'] = (int) $action;
            $cible = ((int) $action === 1) ? 'titres' : 'sous_titres';
        }

        if ($cible === 'titres') {
            $titres[] = $item;
        } else {
            $sousTitres[] = $item;
        }
    }

    /**
     * Trie les items selon leur position d'origine (section puis élément).
     *
     * @param array<int, array<string, mixed>> $items
     *
     * @return array<int, array<string, mixed>>
     */
    private function trier(array $items): array
    {
        usort($items, static function (array $a, array $b): int {
            $sa = (int) ($a['position']['section_index'] ?? 0);
            $sb = (int) ($b['position']['section_index'] ?? 0);

            if ($sa !== $sb) {
                return $sa <=> $sb;
            }

            $ea = (int) ($a['position']['element_index'] ?? 0);
            $eb = (int) ($b['position']['element_index'] ?? 0);

            return $ea <=> $eb;
        });

        return $items;
    }
}
