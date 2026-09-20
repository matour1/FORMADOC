<?php

declare(strict_types=1);

namespace App\DocAnalyzer;

/**
 * Contrat partagé pour les résultats d'analyse (règles, IA, fusion).
 *
 * Les deux analyseurs (RuleBasedDetector et DeepSeekAnalyzer) produisent des
 * résultats dans CE format normalisé, ce qui permet au ResultMerger de les
 * fusionner de façon déterministe (règles prioritaires, IA en complément).
 */
class AnalyzerResult
{
    /**
     * Catégories d'éléments détectés, dans l'ordre canonique.
     *
     * @var string[]
     */
    public const CATEGORIES = [
        'titres',
        'sous_titres',
        'en_tetes',
        'pieds_de_page',
        'tableaux',
        'images',
        'elements_flottants',
    ];

    /**
     * Retourne un résultat vide (toutes catégories à []).
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public static function empty(): array
    {
        $result = [];
        foreach (self::CATEGORIES as $category) {
            $result[$category] = [];
        }

        return $result;
    }

    /**
     * Vérifie qu'un tableau est un résultat conforme au contrat.
     *
     * @param  mixed  $result
     */
    public static function isValid($result): bool
    {
        if (! is_array($result)) {
            return false;
        }

        foreach (self::CATEGORIES as $category) {
            if (! isset($result[$category]) || ! is_array($result[$category])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Normalise un résultat brut (ex: sortie IA) vers le contrat.
     *
     * Les catégories manquantes sont ajoutées vides, et chaque item est
     * ramené à un tableau (texte, position, styles, type).
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, array<int, array<string, mixed>>>
     */
    public static function normalize(array $raw): array
    {
        $result = self::empty();

        foreach (self::CATEGORIES as $category) {
            $items = $raw[$category] ?? [];
            if (! is_array($items)) {
                $items = [];
            }

            foreach ($items as $item) {
                if (is_string($item)) {
                    $result[$category][] = ['texte' => $item, 'position' => null, 'styles' => [], 'type' => $category];
                } elseif (is_array($item)) {
                    $result[$category][] = self::normalizeItem($item, $category);
                }
            }
        }

        return $result;
    }

    /**
     * Normalise un item unique d'une catégorie.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private static function normalizeItem(array $item, string $category): array
    {
        $normalized = [
            'texte' => (string) ($item['texte'] ?? $item['text'] ?? $item['label'] ?? ''),
            'position' => $item['position'] ?? null,
            'styles' => $item['styles'] ?? [],
            'type' => (string) ($item['type'] ?? $category),
        ];

        // Raccourcis : positions plates (element_index direct) → position normalisée
        if (isset($item['element_index']) && ! is_array($normalized['position'])) {
            $normalized['position'] = [
                'section_index' => (int) ($item['section_index'] ?? 0),
                'element_index' => (int) $item['element_index'],
                'parent' => (string) ($item['parent'] ?? 'body'),
            ];
        }

        // Métadonnées utiles conservées si présentes
        foreach (['depth', 'rows_count', 'image_name'] as $meta) {
            if (isset($item[$meta])) {
                $normalized[$meta] = $item[$meta];
            }
        }

        return $normalized;
    }
}
