<?php

declare(strict_types=1);

namespace App\Document\Formatting;

use App\Document\Structure\Block;
use App\Document\Structure\BlockType;

/**
 * Injection des en-têtes, pieds de page et numérotation de pages.
 *
 * **Déterministe et sans token** (§10, §11). La numérotation des pages est un
 * calcul, pas une génération : le moteur DOCX la produit nativement via un champ
 * `PAGE` / `NUMPAGES`, ce qui garantit qu'elle reste juste même si l'utilisateur
 * modifie ensuite le document dans Word.
 *
 * Un point volontairement explicite : la numérotation **ne compte jamais la
 * page de garde**. C'est la convention académique courante, et la compter
 * produirait un décalage visible sur toutes les pages suivantes.
 */
final class HeaderFooterInjector
{
    /**
     * Types de blocs qui constituent un en-tête ou un pied de page.
     */
    private const ANCHOR_TYPES = [BlockType::Header, BlockType::Footer];

    /**
     * Construit la description d'en-tête / pied de page.
     *
     * @param  array<int, Block>  $blocks  Blocs du document (source des textes)
     * @param  array<string, mixed>  $gabarit  Gabarit normalisé
     * @param  array<string, mixed>  $options  Options de rendu (titre, mention légale, exclusion garde)
     * @return array{
     *     header: null|array{text: string, alignment: string, from_source: bool},
     *     footer: null|array{text: string, alignment: string, from_source: bool},
     *     page_number: null|array{position: string, alignment: string, format: string, start_at: int, skip_first: bool},
     *     page_total: bool
     * }
     */
    public function describe(array $blocks, array $gabarit, array $options = []): array
    {
        // Un en-tête/pied déjà présent dans le document source est PRÉSERVÉ :
        // on ne l'écrase pas avec une valeur générique, on le complète
        // seulement de la numérotation.
        $sourceHeader = $this->sourceText($blocks, BlockType::Header);
        $sourceFooter = $this->sourceText($blocks, BlockType::Footer);

        $headerText = $sourceHeader ?? $this->sanitize($options['header'] ?? null);
        $footerText = $sourceFooter ?? $this->sanitize($options['footer'] ?? null);

        $numberingEnabled = (bool) ($options['page_number'] ?? true);
        $skipFirst = (bool) ($options['skip_first_page'] ?? true);

        return [
            'header' => $headerText === null ? null : [
                'text' => $headerText,
                'alignment' => (string) ($options['header_alignment'] ?? 'center'),
                'from_source' => $sourceHeader !== null,
            ],
            'footer' => $footerText === null ? null : [
                'text' => $footerText,
                'alignment' => (string) ($options['footer_alignment'] ?? 'center'),
                'from_source' => $sourceFooter !== null,
            ],
            'page_number' => $numberingEnabled ? [
                'position' => (string) ($options['page_number_position'] ?? 'footer'),
                'alignment' => (string) ($options['page_number_alignment'] ?? 'center'),
                'format' => (string) ($options['page_number_format'] ?? 'decimal'),
                // `start_at => 1` même avec `skip_first` : c'est le champ
                // `titlePg` (page de garde distincte) qui masque le numéro,
                // pas un décalage — sans quoi il faudrait écrire « Page 2 »
                // sur ce qui est visiblement la première page.
                'start_at' => 1,
                'skip_first' => $skipFirst,
            ] : null,
            // Le total de pages n'est injecté que si un « / N » est demandé :
            // le champ NUMPAGES force un recalcul complet à chaque ouverture.
            'page_total' => (bool) ($options['page_total'] ?? false),
        ];
    }

    /**
     * Texte du premier bloc d'un type donné, s'il existe.
     *
     * @param  array<int, Block>  $blocks
     */
    private function sourceText(array $blocks, BlockType $type): ?string
    {
        if (! in_array($type, self::ANCHOR_TYPES, true)) {
            return null;
        }

        foreach ($blocks as $block) {
            if ($block->type === $type) {
                $text = trim($block->text);

                if ($text !== '') {
                    return $text;
                }
            }
        }

        return null;
    }

    /**
     * Normalise une valeur d'option en texte affichable.
     *
     * Une chaîne vide ou uniquement composée d'espaces est traitée comme une
     * absence : afficher un en-tête vide laisserait une bande blanche réservée
     * en haut de chaque page.
     */
    private function sanitize(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    /**
     * Le document comporte-t-il un en-tête ou un pied de page source ?
     *
     * @param  array<int, Block>  $blocks
     */
    public function hasSourceHeaderFooter(array $blocks): bool
    {
        foreach ($blocks as $block) {
            if (in_array($block->type, self::ANCHOR_TYPES, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Description des marges réservées à l'en-tête et au pied de page.
     *
     * Les marges d'en-tête/pied doivent rester **inférieures** aux marges haute
     * et basse, sinon le texte de page chevauche l'en-tête — un défaut visible
     * et difficile à diagnostiquer.
     *
     * @param  null|array<string, mixed>  $template
     * @return array{top: int, bottom: int, header: int, footer: int, valid: bool}
     */
    public function marginsFor(?array $template = null): array
    {
        $margins = TemplateEngine::sectionMargins($template);

        return [
            'top' => $margins['top'],
            'bottom' => $margins['bottom'],
            'header' => $margins['header'],
            'footer' => $margins['footer'],
            'valid' => $margins['header'] < $margins['top'] && $margins['footer'] < $margins['bottom'],
        ];
    }
}
