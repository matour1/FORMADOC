<?php

declare(strict_types=1);

namespace App\Document\Adapters\DocxOoxml;

use DOMElement;

/**
 * Lecture des paragraphes (`w:p`) d'un document Word.
 *
 * Un paragraphe Word est une suite de « runs » (`w:r`), chacun portant son
 * propre formatage : un titre peut contenir un mot en gras au milieu. On
 * reconstruit donc le texte en concaténant les runs, tout en conservant les
 * signaux DOMINANTS (taille maximale rencontrée, gras si tout le paragraphe
 * l'est) — ce sont ces signaux qui alimentent la classification.
 *
 * Éléments gérés :
 *  - `w:t`     : texte
 *  - `w:tab`   : tabulation (convertie en espace, Word les utilise pour aligner)
 *  - `w:br`    : saut de ligne DANS le paragraphe (pas un nouveau paragraphe)
 *  - `w:cr`    : retour chariot
 *  - `w:drawing` / `w:pict` : image (marqueur posé, le binaire est lu ailleurs)
 *  - `w:hyperlink` : lien (le texte est conservé, l'URL est tracée)
 *  - `w:fldSimple` / `w:instrText` : champs Word (SEQ, REF, PAGE…)
 */
final class ParagraphReader
{
    /**
     * Marqueur inséré à la place d'une image dans le texte.
     *
     * Le parseur de PHPWord existant utilisait déjà ce motif
     * (`[image:nom.ext]`) : le conserver facilite la comparaison des deux
     * pipelines pendant la transition.
     */
    public const IMAGE_MARKER_FORMAT = '[image:%s]';

    public function __construct(private readonly StyleReader $styles) {}

    /**
     * Analyse un paragraphe et retourne ses signaux structurels.
     *
     * @param  DOMElement  $paragraph  Élément `w:p`
     * @param  string  $blockId  Identifiant stable attribué au bloc
     * @param  float  $positionY  Position verticale estimée (calculée par le LayoutEstimator)
     * @return null|array<string, mixed> null si le paragraphe n'a aucun contenu exploitable
     */
    public function read(DOMElement $paragraph, string $blockId, float $positionY = 0.0): ?array
    {
        $properties = XmlLoader::firstChild($paragraph, 'w:pPr');
        $directParagraph = $this->styles->effectiveParagraphProperties(
            $this->paragraphPropertiesOf($properties),
            null
        );

        // Le style de paragraphe peut venir du paragraphe lui-même OU du style
        // qu'il référence (chaîne `w:pStyle`).
        $styleId = $this->styleIdOf($properties);
        $effectiveParagraph = $styleId === null
            ? $directParagraph
            : $this->styles->effectiveParagraphProperties(
                $this->paragraphPropertiesOf($properties),
                $styleId
            );

        // --- Analyse des runs ---
        $analysis = $this->analyzeRuns($paragraph, $styleId);

        // Un paragraphe vide (sans texte, sans image, sans saut) n'est pas un
        // bloc : Word en insère beaucoup comme espacement.
        if ($analysis['text'] === '' && ! $analysis['has_image'] && ! $analysis['has_break']) {
            return null;
        }

        $isHeadingStyle = $styleId !== null
            ? $this->styles->headingLevelFor($styleId) !== null
            : false;

        return [
            'block_id' => $blockId,
            'text' => $analysis['text'],
            'style_id' => $styleId,
            'style_name' => $this->styles->nameFor($styleId),
            'heading_level' => $styleId === null ? null : $this->styles->headingLevelFor($styleId),
            'is_heading_style' => $isHeadingStyle,
            'is_caption_style' => $this->styles->isCaptionStyle($styleId),
            'is_list_style' => $this->styles->isListStyle($styleId),
            'font_size' => $analysis['font_size'],
            'is_bold' => $analysis['is_bold'],
            'is_italic' => $analysis['is_italic'],
            'font_name' => $analysis['font_name'],
            'alignment' => $effectiveParagraph['alignment'] ?? null,
            'indent_left' => $effectiveParagraph['indent_left'] ?? null,
            'numbering_id' => $effectiveParagraph['numbering_id'] ?? null,
            'numbering_level' => $effectiveParagraph['numbering_level'] ?? null,
            'page_break_before' => $effectiveParagraph['page_break_before'] ?? false,
            'keep_with_next' => $effectiveParagraph['keep_with_next'] ?? false,
            'position_y' => $positionY,
            'has_image' => $analysis['has_image'],
            'has_break' => $analysis['has_break'],
            'images' => $analysis['images'],
            'hyperlinks' => $analysis['hyperlinks'],
            'field_instructions' => $analysis['field_instructions'],
            'run_count' => $analysis['run_count'],
        ];
    }

    /**
     * Identifiant de style porté par un paragraphe (`w:pPr/w:pStyle`).
     */
    public function styleIdOf(?DOMElement $paragraphProperties): ?string
    {
        if ($paragraphProperties === null) {
            return null;
        }

        $style = XmlLoader::firstChild($paragraphProperties, 'w:pStyle');
        $value = XmlLoader::attributeValue($style);

        return $value === '' ? null : $value;
    }

    /**
     * Propriétés déclarées directement sur le paragraphe (sans style).
     *
     * @return array<string, mixed>
     */
    public function paragraphPropertiesOf(?DOMElement $paragraphProperties): array
    {
        if ($paragraphProperties === null) {
            return [];
        }

        $properties = [];

        $justification = XmlLoader::attributeValue(XmlLoader::firstChild($paragraphProperties, 'w:jc'));
        if ($justification !== null) {
            $properties['alignment'] = $justification;
        }

        $indent = XmlLoader::firstChild($paragraphProperties, 'w:ind');
        if ($indent !== null) {
            $left = XmlLoader::attributeValue($indent, 'w:left');
            if ($left !== null) {
                $properties['indent_left'] = XmlLoader::twipsToPoints($left);
            }
        }

        $numbering = XmlLoader::firstChild($paragraphProperties, 'w:numPr');
        if ($numbering !== null) {
            $numId = XmlLoader::attributeValue(XmlLoader::firstChild($numbering, 'w:numId'));
            $level = XmlLoader::attributeValue(XmlLoader::firstChild($numbering, 'w:ilvl'));

            if ($numId !== null) {
                $properties['numbering_id'] = $numId;
            }
            if ($level !== null) {
                $properties['numbering_level'] = (int) $level;
            }
        }

        $pageBreak = XmlLoader::flag(XmlLoader::firstChild($paragraphProperties, 'w:pageBreakBefore'));
        if ($pageBreak !== null) {
            $properties['page_break_before'] = $pageBreak;
        }

        $keepNext = XmlLoader::flag(XmlLoader::firstChild($paragraphProperties, 'w:keepNext'));
        if ($keepNext !== null) {
            $properties['keep_with_next'] = $keepNext;
        }

        return $properties;
    }

    /**
     * Parcourt tous les runs d'un paragraphe et agrège leurs signaux.
     *
     * @return array{
     *     text: string,
     *     font_size: null|float,
     *     is_bold: bool,
     *     is_italic: bool,
     *     font_name: null|string,
     *     has_image: bool,
     *     has_break: bool,
     *     images: array<int, string>,
     *     hyperlinks: array<int, array{text: string, url: null|string}>,
     *     field_instructions: array<int, string>,
     *     run_count: int
     * }
     */
    private function analyzeRuns(DOMElement $paragraph, ?string $styleId): array
    {
        $text = '';
        $fontSizes = [];
        $boldFlags = [];
        $italicFlags = [];
        $fontNames = [];
        $images = [];
        $hyperlinks = [];
        $fieldInstructions = [];
        $hasBreak = false;
        $runCount = 0;

        // On parcourt TOUS les descendants `w:r`, y compris ceux encapsulés
        // dans un `w:hyperlink` (sinon le texte des liens disparaît).
        foreach (XmlLoader::descendants($paragraph, 'w:r') as $run) {
            $runCount++;
            $runProperties = $this->styles->effectiveRunProperties(
                $this->runPropertiesOf(XmlLoader::firstChild($run, 'w:rPr')),
                $styleId
            );

            if (isset($runProperties['font_size']) && $runProperties['font_size'] > 0) {
                $fontSizes[] = (float) $runProperties['font_size'];
            }
            if (array_key_exists('is_bold', $runProperties)) {
                $boldFlags[] = (bool) $runProperties['is_bold'];
            }
            if (array_key_exists('is_italic', $runProperties)) {
                $italicFlags[] = (bool) $runProperties['is_italic'];
            }
            if (isset($runProperties['font_name'])) {
                $fontNames[] = (string) $runProperties['font_name'];
            }

            $text .= $this->runText($run, $images, $hasBreak, $fieldInstructions);
        }

        // Les liens peuvent aussi contenir des runs sans `w:rPr` : on les
        // parcourt séparément pour tracer l'URL.
        foreach (XmlLoader::descendants($paragraph, 'w:hyperlink') as $hyperlink) {
            $relationId = $hyperlink->getAttribute('r:id');
            $anchor = $hyperlink->getAttribute('w:anchor');

            $linkText = '';
            foreach (XmlLoader::descendants($hyperlink, 'w:t') as $textNode) {
                $linkText .= $textNode->textContent;
            }

            if ($linkText !== '') {
                $hyperlinks[] = [
                    'text' => $linkText,
                    'url' => null, // résolu par l'adaptateur via les relations
                    'relation_id' => $relationId !== '' ? $relationId : null,
                    'anchor' => $anchor !== '' ? $anchor : null,
                ];
            }
        }

        // Signaux dominants : la taille maximale rencontrée représente le
        // « niveau visuel » du paragraphe (un titre garde sa taille même si
        // un mot est dans une autre police).
        $fontSize = $fontSizes === [] ? null : max($fontSizes);

        // Gras : considéré vrai seulement si TOUS les runs porteurs
        // d'information le sont — un mot en gras au milieu d'un paragraphe
        // ne fait pas du paragraphe un titre.
        $isBold = $boldFlags !== [] && ! in_array(false, $boldFlags, true);
        $isItalic = $italicFlags !== [] && ! in_array(false, $italicFlags, true);

        return [
            'text' => $this->normalize($text),
            'font_size' => $fontSize,
            'is_bold' => $isBold,
            'is_italic' => $isItalic,
            'font_name' => $fontNames === [] ? null : $fontNames[0],
            'has_image' => $images !== [],
            'has_break' => $hasBreak,
            'images' => $images,
            'hyperlinks' => $hyperlinks,
            'field_instructions' => $fieldInstructions,
            'run_count' => $runCount,
        ];
    }

    /**
     * Texte porté par un run (et effets détectés au passage).
     *
     * @param  array<int, string>  $images  Collecté par référence
     * @param  bool  $hasBreak  Collecté par référence
     * @param  array<int, string>  $fieldInstructions  Collecté par référence
     */
    private function runText(DOMElement $run, array &$images, bool &$hasBreak, array &$fieldInstructions): string
    {
        $text = '';

        foreach ($run->childNodes as $node) {
            if ($node instanceof \DOMText) {
                $text .= $node->textContent;

                continue;
            }

            if (! $node instanceof DOMElement) {
                continue;
            }

            switch ($node->nodeName) {
                case 'w:t':
                    // `xml:space="preserve"` est indispensable : sans lui, Word
                    // supprime les espaces de début/fin au rendu.
                    $text .= $node->textContent;
                    break;

                case 'w:tab':
                    // Une tabulation Word sert à l'alignement : on la restitue
                    // comme telle pour ne pas coller les mots entre eux.
                    $text .= "\t";
                    break;

                case 'w:br':
                    // Saut de ligne INTERNE au paragraphe (Maj+Entrée).
                    $type = XmlLoader::attributeValue($node, 'w:type');
                    if ($type === 'page') {
                        $hasBreak = true;
                        $text .= "\n";

                        break;
                    }
                    $text .= "\n";
                    break;

                case 'w:cr':
                    $text .= "\n";
                    break;

                case 'w:noBreakHyphen':
                    $text .= '-';
                    break;

                case 'w:drawing':
                case 'w:pict':
                case 'w:object':
                    $name = $this->imageName($node);
                    if ($name !== null) {
                        $images[] = $name;
                        $text .= sprintf(self::IMAGE_MARKER_FORMAT, $name);
                    }
                    break;

                case 'w:fldSimple':
                    // Champ simple : `<w:fldSimple w:instr="SEQ Figure">`.
                    $instruction = $node->getAttribute('w:instr');
                    if ($instruction !== '') {
                        $fieldInstructions[] = $instruction;
                    }
                    $text .= $node->textContent;
                    break;

                case 'w:instrText':
                    $instruction = trim($node->textContent);
                    if ($instruction !== '') {
                        $fieldInstructions[] = $instruction;
                    }
                    break;

                default:
                    // Runs imbriqués, `w:smartTag`, `w:sdt`… : on récupère le
                    // texte brut pour ne rien perdre.
                    if (in_array($node->nodeName, ['w:smartTag', 'w:sdt', 'w:sdtContent'], true)) {
                        $text .= $node->textContent;
                    }
                    break;
            }
        }

        return $text;
    }

    /**
     * Nom de fichier de l'image embarquée dans un dessin.
     *
     * L'image elle-même est référencée par un `r:embed` qu'il appartient à
     * l'adaptateur de résoudre via les relations ; on ne conserve donc ici que
     * l'identifiant de relation, normalisé comme nom de marqueur.
     */
    private function imageName(DOMElement $container): ?string
    {
        // Cas standard : `<a:blip r:embed="rId5"/>` dans un `w:drawing`.
        foreach (XmlLoader::descendants($container, 'a:blip') as $blip) {
            $embed = $blip->getAttribute('r:embed');
            if ($embed === '') {
                $embed = $blip->getAttribute('r:link');
            }
            if ($embed !== '') {
                return $embed.'.img';
            }
        }

        // Cas des anciens documents : `<v:imagedata r:id="rId4"/>` dans `w:pict`.
        foreach (XmlLoader::descendants($container, 'v:imagedata') as $imageData) {
            $id = $imageData->getAttribute('r:id');
            if ($id !== '') {
                return $id.'.img';
            }
        }

        // Image sans référence exploitable (forme dessinée, objet OLE).
        return 'embedded-object';
    }

    /**
     * Propriétés de police déclarées directement sur un run (`w:rPr`).
     *
     * @return array<string, mixed>
     */
    private function runPropertiesOf(?DOMElement $runProperties): array
    {
        if ($runProperties === null) {
            return [];
        }

        $properties = [];

        // La taille en demi-points (w:sz 32 = 16 pt). Word utilise aussi
        // w:szCs pour les scripts complexes : on privilégie w:sz.
        $size = XmlLoader::attributeValue(XmlLoader::firstChild($runProperties, 'w:sz'));
        if ($size !== null) {
            $properties['font_size'] = XmlLoader::halfPointsToPoints($size);
        }

        $bold = XmlLoader::flag(XmlLoader::firstChild($runProperties, 'w:b'));
        if ($bold !== null) {
            $properties['is_bold'] = $bold;
        }

        $italic = XmlLoader::flag(XmlLoader::firstChild($runProperties, 'w:i'));
        if ($italic !== null) {
            $properties['is_italic'] = $italic;
        }

        $color = XmlLoader::attributeValue(XmlLoader::firstChild($runProperties, 'w:color'));
        if ($color !== null && $color !== 'auto') {
            $properties['color'] = $color;
        }

        $fonts = XmlLoader::firstChild($runProperties, 'w:rFonts');
        if ($fonts !== null) {
            foreach (['w:ascii', 'w:hAnsi', 'w:cs'] as $attribute) {
                $value = XmlLoader::attributeValue($fonts, $attribute);
                if ($value !== null && $value !== '') {
                    $properties['font_name'] = $value;
                    break;
                }
            }

            foreach (['w:asciiTheme', 'w:hAnsiTheme'] as $attribute) {
                $theme = XmlLoader::attributeValue($fonts, $attribute);
                if ($theme !== null && $theme !== '') {
                    $properties['font_theme'] = $theme;
                    break;
                }
            }
        }

        return $properties;
    }

    /**
     * Normalise les espaces : fins insécables, tabulations multiples, espaces
     * de fin. Word insère de nombreux caractères Unicode invisibles que l'on
     * uniformise pour que la classification travaille sur un texte propre.
     */
    private function normalize(string $text): string
    {
        // Espace insécable (U+00A0), espace fine insécable (U+202F), espace
        // de largeur nulle (U+200B) et espace fine (U+2009) → espace normale.
        $text = str_replace(["\u{00A0}", "\u{202F}", "\u{200B}", "\u{2009}", "\u{FEFF}"], ' ', $text);

        // Retours de ligne Windows → Unix.
        $text = str_replace(["\r\n", "\r"], "\n", $text);

        // Tabulations multiples → une seule (Word en aligne parfois plusieurs).
        $text = preg_replace("/\t{2,}/u", "\t", $text) ?? $text;

        return trim($text);
    }
}
