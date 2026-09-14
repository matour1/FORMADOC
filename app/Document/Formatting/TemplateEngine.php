<?php

declare(strict_types=1);

namespace App\Document\Formatting;

use App\Document\Structure\Block;
use App\Document\Structure\StructuralDocument;

/**
 * Résolution déterministe du gabarit de mise en forme.
 *
 * **Ce composant ne consomme AUCUN token** (REFONTE_ARCHITECTURE.md §4, §10).
 * Il applique un gabarit — police, tailles, couleurs, interlignes, alignements,
 * marges, style de tableau — sur des blocs **déjà classifiés**. C'est le
 * principe fondateur de la refonte : ne jamais faire deviner à l'IA ce qu'une
 * règle déterministe peut établir.
 *
 * Structure d'un gabarit (colonne `templates.params` existante, réutilisée) :
 * ```
 * {
 *   "police": "Times New Roman",
 *   "tailles": { "titre1": 16, "titre2": 14, "titre3": 12, "corps": 12 },
 *   "couleurs": { "titre1": "1F3864", … },
 *   "interligne": 1.5,
 *   "alignement_titres": "left",
 *   "tableau": { "style": "TableGrid", "header_couleur": "1F3864", … }
 * }
 * ```
 *
 * Le moteur produit une **description de rendu** (`RenderedDocument`), et non
 * un fichier : l'écriture DOCX reste le rôle de PHPWord via
 * `DocumentReconstructor`. Cette séparation est ce qui rend le résultat
 * testable sans produire de fichier.
 */
final class TemplateEngine
{
    /**
     * Le formateur de titres est injectable pour que les tests puissent le
     * substituer sans changer le comportement par défaut.
     */
    public function __construct(
        private readonly HeadingFormatter $headings = new HeadingFormatter,
    ) {}

    /**
     * Gabarit académique par défaut (réutilise les valeurs du projet).
     *
     * @return array<string, mixed>
     */
    public static function defaultTemplate(): array
    {
        return [
            'police' => 'Times New Roman',
            'tailles' => [
                'titre1' => 16,
                'titre2' => 14,
                'titre3' => 12,
                'corps' => 12,
                'legende' => 10,
            ],
            'couleurs' => [
                'titre1' => '1F3864',
                'titre2' => '1F3864',
                'titre3' => '1F3864',
                'corps' => '000000',
                'legende' => '44546A',
            ],
            'interligne' => 1.5,
            'espacements' => [
                'avant_titre' => 240,
                'apres_titre' => 120,
                'apres_paragraphe' => 120,
            ],
            'alignement_titres' => 'left',
            'alignement_corps' => 'both',
            'marges' => [
                'top' => 1440,
                'right' => 1440,
                'bottom' => 1440,
                'left' => 1440,
                'header' => 720,
                'footer' => 720,
            ],
            'tableau' => [
                'style' => 'TableGrid',
                'header_couleur' => '1F3864',
                'header_texte' => 'FFFFFF',
                'bordure' => true,
            ],
        ];
    }

    /**
     * Complète un gabarit partiel avec les valeurs par défaut.
     *
     * Les gabarits en base sont souvent incomplets : un utilisateur peut n'avoir
     * défini que la police. Combler les manques évite des valeurs nulles qui
     * feraient échouer le rendu.
     *
     * @param  null|array<string, mixed>  $template
     * @return array<string, mixed>
     */
    public static function normalizeTemplate(?array $template): array
    {
        $defaults = self::defaultTemplate();

        if ($template === null || $template === []) {
            return $defaults;
        }

        $normalized = $defaults;

        // Les clés plates écrasent directement — sauf si la valeur est vide :
        // un champ de formulaire laissé vide produirait une police (ou un
        // alignement) inexistant, et le rendu échouerait silencieusement.
        foreach (['police', 'interligne', 'alignement_titres', 'alignement_corps'] as $key) {
            if (! isset($template[$key]) || ! is_scalar($template[$key])) {
                continue;
            }

            if (is_string($template[$key]) && trim($template[$key]) === '') {
                continue;
            }

            $normalized[$key] = $template[$key];
        }

        // Les clés imbriquées fusionnent clé par clé (jamais un écrasement
        // global : un gabarit qui ne définit que `titre1` conserve les autres).
        foreach (['tailles', 'couleurs', 'espacements', 'marges', 'tableau'] as $key) {
            if (! is_array($template[$key] ?? null)) {
                continue;
            }

            foreach ($template[$key] as $subKey => $value) {
                if (is_scalar($value) && $value !== '') {
                    $normalized[$key][$subKey] = $value;
                }
            }
        }

        return $normalized;
    }

    /**
     * Applique le gabarit à un document structurel.
     *
     * @param  StructuralDocument  $document  Document classifié
     * @param  null|array<string, mixed>  $template  Gabarit (utilise le défaut si null)
     * @return RenderedDocument Description du rendu (styles résolus par bloc)
     */
    public function apply(StructuralDocument $document, ?array $template = null): RenderedDocument
    {
        $gabarit = self::normalizeTemplate($template);
        $styles = [];

        foreach ($document->blocks as $block) {
            $styles[$block->blockId] = $this->styleFor($block, $gabarit);
        }

        return new RenderedDocument(
            documentId: $document->documentId,
            sourceType: $document->sourceType,
            blocks: $document->blocks,
            styles: $styles,
            template: $gabarit,
            fidelity: $document->expectedFidelity(),
        );
    }

    /**
     * Détermine le style d'un bloc selon son type et son niveau.
     *
     * Aucune heuristique ici : le type a déjà été établi par la classification
     * (déterministe ou vérifiée par l'utilisateur). On ne fait que traduire un
     * type en attributs de mise en forme.
     *
     * @param  array<string, mixed>  $gabarit
     * @return array<string, mixed>
     */
    private function styleFor(Block $block, array $gabarit): array
    {
        $base = [
            'font_name' => $gabarit['police'],
            'font_size' => $gabarit['tailles']['corps'],
            'color' => $gabarit['couleurs']['corps'],
            'bold' => false,
            'italic' => false,
            'alignment' => $gabarit['alignement_corps'],
            'line_spacing' => $gabarit['interligne'],
            'space_after' => $gabarit['espacements']['apres_paragraphe'],
            'indent_level' => $block->indentLevel,
        ];

        return match ($block->type->value) {
            // Les titres sont délégués à HeadingFormatter : la règle de niveau
            // (plafonnement à 3, non-justification) doit exister en un seul
            // endroit, sinon les deux chemins de rendu divergent.
            'heading' => $this->headings->style($block, $gabarit),
            'caption' => $this->captionStyle($gabarit, $base),
            'header', 'footer' => $this->headerFooterStyle($gabarit, $base),
            'table' => $this->tableStyle($gabarit, $base),
            default => $base,
        };
    }

    /**
     * Style d'une légende : plus petite, en italique, dans une couleur adoucie.
     *
     * @param  array<string, mixed>  $gabarit
     * @param  array<string, mixed>  $base
     * @return array<string, mixed>
     */
    private function captionStyle(array $gabarit, array $base): array
    {
        return [
            ...$base,
            'font_size' => $gabarit['tailles']['legende'] ?? 10,
            'color' => $gabarit['couleurs']['legende'] ?? '44546A',
            'italic' => true,
            'alignment' => 'center',
            'space_before' => 60,
            'space_after' => 200,
        ];
    }

    /**
     * Style d'un en-tête ou d'un pied de page.
     *
     * @param  array<string, mixed>  $gabarit
     * @param  array<string, mixed>  $base
     * @return array<string, mixed>
     */
    private function headerFooterStyle(array $gabarit, array $base): array
    {
        return [
            ...$base,
            'font_size' => max(8, (int) $gabarit['tailles']['corps'] - 2),
            'color' => '808080',
            'alignment' => 'center',
            'line_spacing' => 1.0,
        ];
    }

    /**
     * Style d'un tableau.
     *
     * Le gabarit ne porte que la MISE EN FORME (bordures, couleur d'en-tête).
     * Le contenu des cellules n'est jamais touché : c'est la responsabilité du
     * `TableRestyler`, et la règle absolue de la refonte (§15).
     *
     * @param  array<string, mixed>  $gabarit
     * @param  array<string, mixed>  $base
     * @return array<string, mixed>
     */
    private function tableStyle(array $gabarit, array $base): array
    {
        return [
            ...$base,
            'table_style' => $gabarit['tableau']['style'] ?? 'TableGrid',
            'table_header_color' => $gabarit['tableau']['header_couleur'] ?? '1F3864',
            'table_header_text_color' => $gabarit['tableau']['header_texte'] ?? 'FFFFFF',
            'table_border' => (bool) ($gabarit['tableau']['bordure'] ?? true),
            // Un tableau ne consomme pas d'espacement de paragraphe : il gère
            // ses propres marges internes.
            'space_before' => 120,
            'space_after' => 120,
        ];
    }

    /**
     * Marges de section du gabarit, en twips.
     *
     * @param  null|array<string, mixed>  $template
     * @return array<string, int>
     */
    public static function sectionMargins(?array $template = null): array
    {
        $gabarit = self::normalizeTemplate($template);

        return [
            'top' => (int) $gabarit['marges']['top'],
            'right' => (int) $gabarit['marges']['right'],
            'bottom' => (int) $gabarit['marges']['bottom'],
            'left' => (int) $gabarit['marges']['left'],
            'header' => (int) $gabarit['marges']['header'],
            'footer' => (int) $gabarit['marges']['footer'],
        ];
    }
}
