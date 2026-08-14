<?php

declare(strict_types=1);

namespace App\DocAnalyzer;

use InvalidArgumentException;
use PhpOffice\PhpWord\Element\AbstractContainer;
use PhpOffice\PhpWord\Element\AbstractElement;
use PhpOffice\PhpWord\Element\Footer;
use PhpOffice\PhpWord\Element\Header;
use PhpOffice\PhpWord\Element\Image;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\Element\Text;
use PhpOffice\PhpWord\Element\TextBreak;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\Element\Title;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Style;
use PhpOffice\PhpWord\Style\Font;
use PhpOffice\PhpWord\Style\Paragraph;
use RuntimeException;

/**
 * Extraction structurelle et fiable d'un document Word (DOCX).
 *
 * Remplace l'extraction "texte brut" de la Phase 1 : ici on conserve la
 * structure réelle du document (sections, en-têtes, pieds de page, styles)
 * afin que la détection des titres soit basée sur les STYLES (déterministe)
 * et non plus sur du markdown généré par IA (peu fiable).
 *
 * Sortie de `parse()` :
 *  - raw_text                  : texte brut concaténé (body uniquement)
 *  - context_text              : texte avec balises de style (<titre>…</titre>)
 *  - context_text_with_positions : texte avec positions [POS:section_X,element_Y,parent_Z]
 *  - sections                  : structure détaillée (body/headers/footers)
 *
 * Chaque élément possède : text, type, styles (font + paragraph), position
 * (section_index, element_index, parent).
 *
 * L'index element_index est INCRÉMENTAL et global : il sert de clé de
 * tri/fusion pour les autres analyseurs (règles, IA).
 */
class DocumentParser
{
    /**
     * Chemin absolu du fichier analysé.
     *
     * @var string
     */
    private string $filePath;

    /**
     * Document PhpWord chargé.
     */
    private ?PhpWord $phpWord = null;

    /**
     * Index global croissant pour tous les éléments (body, headers, footers).
     */
    private int $globalElementIndex = 0;

    /**
     * Types d'éléments PhpWord considérés comme du texte.
     *
     * @var string[]
     */
    private const TEXT_ELEMENT_TYPES = [Text::class, TextRun::class, Title::class];

    /**
     * @param string $filePath Chemin absolu du fichier DOCX (doit exister)
     *
     * @throws InvalidArgumentException Si le fichier n'existe pas ou est illisible
     * @throws RuntimeException         Si le chargement PhpWord échoue
     */
    public function __construct(string $filePath)
    {
        if (!file_exists($filePath)) {
            throw new InvalidArgumentException(
                "DocumentParser : fichier introuvable : {$filePath}"
            );
        }

        if (!is_readable($filePath)) {
            throw new InvalidArgumentException(
                "DocumentParser : fichier illisible : {$filePath}"
            );
        }

        $this->filePath = $filePath;
    }

    /**
     * Analyse le document et retourne sa structure complète.
     *
     * @return array{
     *     raw_text: string,
     *     context_text: string,
     *     context_text_with_positions: string,
     *     sections: array<int, array{
     *         body: array<int, array<string, mixed>>,
     *         headers: array<int, array<string, mixed>>,
     *         footers: array<int, array<string, mixed>>,
     *     }>,
     * }
     *
     * @throws RuntimeException Si le chargement du document échoue
     */
    public function parse(): array
    {
        $this->phpWord = $this->load();
        $this->globalElementIndex = 0;

        $rawTextParts = [];
        $contextParts = [];
        $positionParts = [];
        $sections = [];

        foreach ($this->phpWord->getSections() as $sectionIndex => $section) {
            $body = [];
            $headers = [];
            $footers = [];

            foreach ($section->getElements() as $element) {
                $parsed = $this->parseElement($element, $sectionIndex, 'body');
                if ($parsed !== null) {
                    $body[] = $parsed;
                    $rawTextParts[] = $parsed['text'];
                    $contextParts[] = $this->elementToContextText($parsed);
                    $positionParts[] = $this->elementToPositionText($parsed);
                }
            }

            foreach ($section->getHeaders() as $header) {
                foreach ($header->getElements() as $element) {
                    $parsed = $this->parseElement($element, $sectionIndex, 'header');
                    if ($parsed !== null) {
                        $headers[] = $parsed;
                        $contextParts[] = $this->elementToContextText($parsed);
                        $positionParts[] = $this->elementToPositionText($parsed);
                    }
                }
            }

            foreach ($section->getFooters() as $footer) {
                foreach ($footer->getElements() as $element) {
                    $parsed = $this->parseElement($element, $sectionIndex, 'footer');
                    if ($parsed !== null) {
                        $footers[] = $parsed;
                        $contextParts[] = $this->elementToContextText($parsed);
                        $positionParts[] = $this->elementToPositionText($parsed);
                    }
                }
            }

            $sections[] = [
                'body' => $body,
                'headers' => $headers,
                'footers' => $footers,
            ];
        }

        return [
            'raw_text' => $this->normalize(implode("\n", $rawTextParts)),
            'context_text' => $this->normalize(implode("\n", $contextParts)),
            'context_text_with_positions' => $this->normalize(implode("\n", $positionParts)),
            'sections' => $sections,
        ];
    }

    /**
     * Charge le document via PhpWord.
     *
     * @throws RuntimeException Si le chargement échoue (fichier corrompu, format non supporté…)
     */
    private function load(): PhpWord
    {
        try {
            return IOFactory::load($this->filePath);
        } catch (\Throwable $e) {
            throw new RuntimeException(
                "DocumentParser : échec de chargement du document ({$e->getMessage()})",
                0,
                $e
            );
        }
    }

    /**
     * Analyse un élément et retourne sa représentation normalisée.
     *
     * @param int    $sectionIndex Index de la section (0-based)
     * @param string $parent       body|header|footer
     *
     * @return null|array<string, mixed> null si l'élément ne porte pas de contenu pertinent
     */
    private function parseElement(AbstractElement $element, int $sectionIndex, string $parent): ?array
    {
        $type = $this->getElementType($element);
        $text = $this->getElementText($element);

        // Sauts et éléments vides : on les ignore du résultat structuré
        if ($type === 'saut' || (trim($text) === '' && $type !== 'image' && $type !== 'tableau')) {
            return null;
        }

        $parsed = [
            'text' => $text,
            'type' => $type,
            'styles' => $this->extractStyles($element),
            'position' => [
                'section_index' => $sectionIndex,
                'element_index' => $this->globalElementIndex++,
                'parent' => $parent,
            ],
        ];

        // Métadonnées spécifiques
        if ($element instanceof Title) {
            $parsed['depth'] = (int) $element->getDepth();
        }

        if ($element instanceof Table) {
            $parsed['rows_count'] = count($element->getRows());
        }

        if ($element instanceof Image) {
            $parsed['image_name'] = $element->getName() ?: basename((string) $element->getSource());
        }

        return $parsed;
    }

    /**
     * Détermine le type normalisé d'un élément PhpWord.
     *
     * @return string titre|texte|tableau|image|saut|autre
     */
    public function getElementType(AbstractElement $element): string
    {
        return match (true) {
            $element instanceof Title => 'titre',
            $element instanceof Text, $element instanceof TextRun => 'texte',
            $element instanceof Table => 'tableau',
            $element instanceof Image => 'image',
            $element instanceof TextBreak || str_contains(get_class($element), 'PageBreak') => 'saut',
            default => 'autre',
        };
    }

    /**
     * Extrait le texte d'un élément, en gérant les contenus imbriqués
     * (TextRun, tableaux, cellules).
     */
    public function getElementText(AbstractElement $element): string
    {
        if ($element instanceof Title) {
            return $this->titleText($element);
        }

        if ($element instanceof Text) {
            return (string) ($element->getText() ?? '');
        }

        if ($element instanceof TextRun) {
            return $this->containerText($element);
        }

        if ($element instanceof Table) {
            return $this->tableText($element);
        }

        if ($element instanceof Image) {
            $name = $element->getName() ?: basename((string) $element->getSource());

            return "[image:{$name}]";
        }

        if ($element instanceof Header || $element instanceof Footer) {
            return $this->containerText($element);
        }

        return '';
    }

    /**
     * Extrait les styles normalisés d'un élément (font + paragraph).
     *
     * @return array<string, mixed>
     */
    public function extractStyles(AbstractElement $element): array
    {
        $styles = [
            'font' => null,
            'paragraph' => null,
        ];

        // Les éléments Title n'exposent pas getFontStyle/getParagraphStyle :
        // leurs styles sont enregistrés dans le registre sous "Heading_N"/"Title".
        if ($element instanceof Title) {
            $styleName = $element->getStyle();
            $registered = $styleName !== null
                ? ($this->resolveRegisteredStyle($styleName))
                : null;
            if ($registered instanceof Font) {
                $styles['font'] = $registered->getStyleValues();
            }
            if ($registered instanceof Paragraph) {
                $styles['paragraph'] = $registered->getStyleValues();
            }

            return $styles;
        }

        // Les TextRun n'exposent pas getFontStyle : le style de paragraphe
        // est porté par le run, et le style de police par les Text enfants.
        if ($element instanceof TextRun) {
            $paragraphStyle = $element->getParagraphStyle();
            if ($paragraphStyle !== null) {
                $styles['paragraph'] = $this->normalizeParagraphStyle($paragraphStyle);
            }

            foreach ($element->getElements() as $child) {
                if ($child instanceof Text && method_exists($child, 'getFontStyle')) {
                    $childFont = $this->normalizeFontStyle($child->getFontStyle());
                    if ($childFont !== null) {
                        $styles['font'] = $childFont;

                        break;
                    }
                }
            }

            return $styles;
        }

        // Seuls les éléments textuels exposent des styles font/paragraph
        if (!method_exists($element, 'getFontStyle') || !method_exists($element, 'getParagraphStyle')) {
            return $styles;
        }

        $fontStyle = $element->getFontStyle();
        $paragraphStyle = $element->getParagraphStyle();

        if ($fontStyle !== null) {
            $styles['font'] = $this->normalizeFontStyle($fontStyle);
        }

        if ($paragraphStyle !== null) {
            $styles['paragraph'] = $this->normalizeParagraphStyle($paragraphStyle);
        }

        return $styles;
    }

    /**
     * Résout un style enregistré, en gérant la normalisation
     * "Heading1" → "Heading_1" (nom interne PhpWord).
     *
     * @return null|Font|Paragraph
     */
    private function resolveRegisteredStyle(string $styleName)
    {
        $registered = Style::getStyle($styleName);

        if ($registered === null && preg_match('/^Heading(\d)$/', $styleName, $m)) {
            $registered = Style::getStyle('Heading_' . $m[1]);
        }

        return $registered;
    }

    /**
     * Normalise un style de police (objet ou nom de style).
     *
     * @param Font|string|null $fontStyle
     *
     * @return null|array<string, mixed>
     */
    private function normalizeFontStyle($fontStyle): ?array
    {
        if ($fontStyle instanceof Font) {
            return $fontStyle->getStyleValues();
        }

        if (is_string($fontStyle) && $fontStyle !== '') {
            $registered = Style::getStyle($fontStyle);
            if ($registered instanceof Font) {
                return $registered->getStyleValues();
            }

            return ['style_name' => $fontStyle];
        }

        return null;
    }

    /**
     * Normalise un style de paragraphe (objet ou nom de style).
     *
     * @param Paragraph|string|null $paragraphStyle
     *
     * @return null|array<string, mixed>
     */
    private function normalizeParagraphStyle($paragraphStyle): ?array
    {
        if ($paragraphStyle instanceof Paragraph) {
            return $paragraphStyle->getStyleValues();
        }

        if (is_string($paragraphStyle) && $paragraphStyle !== '') {
            $registered = Style::getStyle($paragraphStyle);
            if ($registered instanceof Paragraph) {
                return $registered->getStyleValues();
            }

            return ['style_name' => $paragraphStyle];
        }

        return null;
    }

    /**
     * Convertit un élément analysé en balise contextuelle.
     */
    private function elementToContextText(array $parsed): string
    {
        $tag = match ($parsed['type']) {
            'titre' => 'titre',
            'tableau' => 'tableau',
            'image' => 'image',
            default => 'texte',
        };

        return "<{$tag}>{$parsed['text']}</{$tag}>";
    }

    /**
     * Convertit un élément analysé en texte positionné.
     */
    private function elementToPositionText(array $parsed): string
    {
        $pos = $parsed['position'];

        return "[POS:section_{$pos['section_index']},element_{$pos['element_index']},parent_{$pos['parent']}]"
            . $parsed['text'];
    }

    /**
     * Texte d'un élément Title (string simple ou TextRun).
     */
    private function titleText(Title $title): string
    {
        $text = $title->getText();

        if (is_string($text)) {
            return $text;
        }

        if ($text instanceof TextRun) {
            return $this->containerText($text);
        }

        return '';
    }

    /**
     * Concatène le texte d'un conteneur (TextRun, Header, Footer, Cell…).
     */
    private function containerText(AbstractContainer $container): string
    {
        $parts = [];

        foreach ($container->getElements() as $child) {
            if ($child instanceof Text) {
                $parts[] = (string) ($child->getText() ?? '');
            } elseif ($child instanceof TextRun) {
                $parts[] = $this->containerText($child);
            }
        }

        return implode('', $parts);
    }

    /**
     * Concatène le texte d'un tableau (lignes → cellules → éléments).
     */
    private function tableText(Table $table): string
    {
        $rows = [];

        foreach ($table->getRows() as $row) {
            $cells = [];

            foreach ($row->getCells() as $cell) {
                $cells[] = $this->containerText($cell);
            }

            $rows[] = implode(' | ', $cells);
        }

        return implode("\n", $rows);
    }

    /**
     * Normalise les fins de ligne du texte extrait.
     */
    private function normalize(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        return preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;
    }
}
