<?php

declare(strict_types=1);

namespace App\Services\DocumentGeneration;

use PhpOffice\PhpWord\Style\Spacing;

/**
 * Convertit les styles « imbriqués » produits par Font::getStyleValues() et
 * Paragraph::getStyleValues() (via DocumentParser::extractStyles()) vers le
 * format PLAT attendu par Section::addText().
 *
 * Les styles imbriqués ne sont pas directement réinjectables : on extrait ici
 * uniquement les attributs visuels pertinents pour une couverture (police,
 * taille, gras, italique, souligné, couleur, alignement, espacements).
 */
class StyleMapper
{
    /**
     * @param  null|array<string, mixed>  $font  Style imbriqué (sortie getStyleValues)
     * @return array<string, mixed> Style plat pour addText (toujours un tableau)
     */
    public static function toFlatFont(?array $font): array
    {
        if ($font === null) {
            return [];
        }

        $basic = $font['basic'] ?? [];
        $style = $font['style'] ?? [];
        $flat = [];

        if (! empty($basic['name'])) {
            $flat['name'] = $basic['name'];
        }

        if (isset($basic['size']) && $basic['size'] !== null) {
            $flat['size'] = (float) $basic['size'];
        }

        if (! empty($basic['color'])) {
            $flat['color'] = $basic['color'];
        }

        if (! empty($style['bold'])) {
            $flat['bold'] = true;
        }

        if (! empty($style['italic'])) {
            $flat['italic'] = true;
        }

        $underline = $style['underline'] ?? null;
        if (is_string($underline) && $underline !== '' && $underline !== 'none') {
            $flat['underline'] = $underline;
        }

        return $flat;
    }

    /**
     * @param  null|array<string, mixed>  $paragraph  Style imbriqué (sortie getStyleValues)
     * @return array<string, mixed> Style plat pour addText (toujours un tableau)
     */
    public static function toFlatParagraph(?array $paragraph): array
    {
        if ($paragraph === null) {
            return [];
        }

        $flat = [];

        $alignment = $paragraph['alignment'] ?? '';
        if (is_string($alignment) && $alignment !== '') {
            $flat['alignment'] = $alignment;
        }

        $spacing = $paragraph['spacing'] ?? null;

        $before = self::spacingValue($spacing, 'before');
        $after = self::spacingValue($spacing, 'after');
        $line = self::spacingValue($spacing, 'line');

        if ($before !== null) {
            $flat['spaceBefore'] = $before;
        }

        if ($after !== null) {
            $flat['spaceAfter'] = $after;
        }

        if ($line !== null) {
            $flat['lineHeight'] = $line;
        }

        return $flat;
    }

    /**
     * Extrait une valeur d'espacement (objet Spacing ou tableau).
     */
    private static function spacingValue($spacing, string $key): ?float
    {
        if ($spacing instanceof Spacing) {
            $value = match ($key) {
                'before' => $spacing->getBefore(),
                'after' => $spacing->getAfter(),
                'line' => $spacing->getLine(),
                default => null,
            };
        } elseif (is_array($spacing)) {
            $value = $spacing[$key] ?? null;
        } else {
            $value = null;
        }

        return $value !== null ? (float) $value : null;
    }
}
