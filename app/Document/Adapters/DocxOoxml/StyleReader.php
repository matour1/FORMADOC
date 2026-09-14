<?php

declare(strict_types=1);

namespace App\Document\Adapters\DocxOoxml;

use App\Document\Adapters\DocxOoxml\Exceptions\DocxReadException;
use DOMElement;

/**
 * Lecteur des styles Word (`word/styles.xml`).
 *
 * C'est ici que se joue le principal gain de la refonte : **`w:outlineLvl`** est
 * un signal DÉTERMINISTE du niveau hiérarchique. Un titre de niveau 1 porte
 * `outlineLvl=0`, niveau 2 → `outlineLvl=1`, etc. C'est infiniment plus fiable
 * que de deviner par la taille de police ou le gras.
 *
 * Deux pièges réels rencontrés sur les documents du projet :
 *
 * 1. **Les `styleId` sont localisés.** Sur un Word français, le style intégré
 *    « Heading 1 » a l'identifiant `Titre1`. On ne peut donc PAS chercher
 *    `w:styleId="Heading1"`. En revanche `<w:name w:val="heading 1"/>` reste
 *    en anglais : c'est la clé robuste.
 * 2. **Les styles héritent** (`w:basedOn`). Un style peut ne définir que la
 *    couleur, la police venant de son parent. La résolution doit donc être
 *    récursive, et un style peut en annuler un autre (`<w:b w:val="0"/>`).
 */
final class StyleReader
{
    /**
     * Correspondance entre les noms de styles Word et les niveaux de titre.
     *
     * Les noms sont ceux de `w:name` (toujours en anglais, même sur une
     * installation française).
     *
     * @var array<string, int>
     */
    private const HEADING_NAME_TO_LEVEL = [
        'heading 1' => 1,
        'heading 2' => 2,
        'heading 3' => 3,
        'heading 4' => 4,
        'heading 5' => 5,
        'heading 6' => 6,
        'heading 7' => 7,
        'heading 8' => 8,
        'heading 9' => 9,
        'titre 1' => 1,
        'titre 2' => 2,
        'titre 3' => 3,
        'titre 4' => 4,
        'titre 5' => 5,
        'titre 6' => 6,
    ];

    /**
     * Styles dont le contenu est une légende (figure, tableau, etc.).
     *
     * @var array<int, string>
     */
    private const CAPTION_NAMES = [
        'caption',
        'légende',
        'legende',
        'figure',
        'tableau',
        'sous-titre',
    ];

    /**
     * Styles de sommaire et de listes (frontispice).
     *
     * @var array<int, string>
     */
    private const LIST_NAMES = [
        'toc 1',
        'toc 2',
        'toc 3',
        'table of figures',
        'tabledesillustrations',
        'list paragraph',
        'paragraphedeliste',
    ];

    /**
     * Styles définis, indexés par `styleId`.
     *
     * @var array<string, array{
     *     name: string,
     *     type: string,
     *     based_on: null|string,
     *     outline_level: null|int,
     *     is_default: bool,
     *     run: array<string, mixed>,
     *     paragraph: array<string, mixed>
     * }>
     */
    private array $styles = [];

    /**
     * Propriétés par défaut du document (`w:docDefaults`).
     *
     * @var array<string, mixed>
     */
    private array $defaults = [];

    /**
     * Cache des styles résolus (héritage déjà appliqué).
     *
     * @var array<string, array{run: array<string, mixed>, paragraph: array<string, mixed>}>
     */
    private array $resolvedCache = [];

    /**
     * Charge les styles d'un paquet.
     *
     * @throws DocxReadException
     */
    public static function fromPackage(PackageReader $package): self
    {
        $xml = $package->read(PackageReader::STYLES);

        $reader = new self;

        if ($xml === null) {
            // Un document sans styles.xml reste lisible : seuls les styles
            // explicites sur les paragraphes seront disponibles.
            return $reader;
        }

        $reader->parse($xml);

        return $reader;
    }

    /**
     * Analyse `styles.xml`.
     *
     * @throws DocxReadException
     */
    public function parse(string $xml): void
    {
        $document = XmlLoader::load($xml, PackageReader::STYLES);

        // --- Valeurs par défaut du document ---
        $docDefaults = XmlLoader::firstDescendant($document->documentElement, 'w:docDefaults');
        if ($docDefaults !== null) {
            $runDefault = XmlLoader::firstDescendant($docDefaults, 'w:rPrDefault');
            $paragraphDefault = XmlLoader::firstDescendant($docDefaults, 'w:pPrDefault');

            $this->defaults = [
                'run' => $runDefault !== null
                    ? $this->parseRunProperties(XmlLoader::firstChild($runDefault, 'w:rPr'))
                    : [],
                'paragraph' => $paragraphDefault !== null
                    ? $this->parseParagraphProperties(XmlLoader::firstChild($paragraphDefault, 'w:pPr'))
                    : [],
            ];
        }

        // --- Styles nommés ---
        // NB : `getElementsByTagName('w:style')` ne fonctionne PAS (il compare
        // au nom local, jamais au préfixe) → on parcourt par nom local.
        foreach (XmlLoader::descendants($document->documentElement, 'w:style') as $node) {
            $styleId = $node->getAttribute('w:styleId');
            if ($styleId === '') {
                continue;
            }

            $name = XmlLoader::attributeValue(
                XmlLoader::firstChild($node, 'w:name')
            ) ?? $styleId;

            $this->styles[$styleId] = [
                'name' => $name,
                'type' => $node->getAttribute('w:type') ?: 'paragraph',
                'based_on' => XmlLoader::attributeValue(XmlLoader::firstChild($node, 'w:basedOn')),
                'outline_level' => $this->parseOutlineLevel(
                    XmlLoader::firstChild($node, 'w:pPr')
                ),
                'is_default' => $node->getAttribute('w:default') === '1',
                'run' => $this->parseRunProperties(XmlLoader::firstChild($node, 'w:rPr')),
                'paragraph' => $this->parseParagraphProperties(XmlLoader::firstChild($node, 'w:pPr')),
            ];
        }
    }

    /**
     * Le paquet contient-il des styles ?
     */
    public function isEmpty(): bool
    {
        return $this->styles === [];
    }

    /**
     * Nombre de styles connus.
     */
    public function count(): int
    {
        return count($this->styles);
    }

    /**
     * Tous les identifiants de styles connus.
     *
     * @return array<int, string>
     */
    public function styleIds(): array
    {
        return array_keys($this->styles);
    }

    /**
     * Propriétés par défaut du document (repli quand rien n'est défini).
     *
     * @return array{run: array<string, mixed>, paragraph: array<string, mixed>}
     */
    public function defaults(): array
    {
        return $this->defaults + ['run' => [], 'paragraph' => []];
    }

    // -------------------------------------------------------------------------
    // Interprétation métier
    // -------------------------------------------------------------------------

    /**
     * Niveau de titre déduit d'un style.
     *
     * Trois sources, par ordre de fiabilité décroissante :
     *  1. **`w:outlineLvl`** — signal explicite posé par Word (le plus fiable) ;
     *  2. le **nom du style** (`heading 1`, `titre 2`…) — mappe la convention ;
     *  3. rien → null (le bloc n'est pas un titre selon son style).
     *
     * @param  null|string  $styleId  Identifiant du style du paragraphe
     * @return null|int Niveau 1-9, ou null si le style n'est pas un titre
     */
    public function headingLevelFor(?string $styleId): ?int
    {
        if ($styleId === null || ! isset($this->styles[$styleId])) {
            return null;
        }

        // 1. outlineLvl est le signal déterministe (0 = niveau 1).
        $outline = $this->styles[$styleId]['outline_level'] ?? null;
        if ($outline !== null) {
            return $outline + 1;
        }

        // 2. Repli sur le nom du style.
        $name = mb_strtolower($this->styles[$styleId]['name']);

        if (isset(self::HEADING_NAME_TO_LEVEL[$name])) {
            return self::HEADING_NAME_TO_LEVEL[$name];
        }

        // 3. Le nom peut porter une numérotation (« Heading 1 Char »).
        if (preg_match('/(?:heading|titre)\s*(\d+)/u', $name, $matches) === 1) {
            $level = (int) $matches[1];

            return $level >= 1 && $level <= 9 ? $level : null;
        }

        return null;
    }

    /**
     * Le style désigne-t-il une légende (figure, tableau, annexe, planche) ?
     *
     * Signal utilisé par la classification : un paragraphe en style « Légende »
     * est un candidat légende même sans motif texte.
     */
    public function isCaptionStyle(?string $styleId): bool
    {
        if ($styleId === null || ! isset($this->styles[$styleId])) {
            return false;
        }

        $name = mb_strtolower($this->styles[$styleId]['name']);

        foreach (self::CAPTION_NAMES as $captionName) {
            if ($name === $captionName || str_contains($name, $captionName)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Le style appartient-il au frontispice (sommaire, listes) ?
     */
    public function isListStyle(?string $styleId): bool
    {
        if ($styleId === null || ! isset($this->styles[$styleId])) {
            return false;
        }

        $name = mb_strtolower($this->styles[$styleId]['name']);

        foreach (self::LIST_NAMES as $listName) {
            if ($name === $listName) {
                return true;
            }
        }

        return false;
    }

    /**
     * Niveau de titre défini par un NOM de style (sans identifiant connu).
     *
     * Utile quand un document référence un style absent de `styles.xml`
     * (fichier produit par un outil tiers, ou styles supprimés).
     */
    public function headingLevelForName(string $styleName): ?int
    {
        $name = mb_strtolower(trim($styleName));

        if (isset(self::HEADING_NAME_TO_LEVEL[$name])) {
            return self::HEADING_NAME_TO_LEVEL[$name];
        }

        if (preg_match('/(?:heading|titre)\s*(\d+)/u', $name, $matches) === 1) {
            $level = (int) $matches[1];

            return $level >= 1 && $level <= 9 ? $level : null;
        }

        return null;
    }

    /**
     * Nom lisible d'un style (pour la traçabilité et l'UI).
     */
    public function nameFor(?string $styleId): ?string
    {
        if ($styleId === null) {
            return null;
        }

        return $this->styles[$styleId]['name'] ?? null;
    }

    // -------------------------------------------------------------------------
    // Résolution des propriétés effectives
    // -------------------------------------------------------------------------

    /**
     * Propriétés de police effectives d'un style, héritage résolu.
     *
     * Ordre de priorité croissante : valeurs par défaut du document → chaîne
     * des styles parents → style demandé.
     *
     * @return array<string, mixed>
     */
    public function resolvedRunProperties(?string $styleId): array
    {
        return $this->resolve($styleId)['run'];
    }

    /**
     * Propriétés de paragraphe effectives d'un style, héritage résolu.
     *
     * @return array<string, mixed>
     */
    public function resolvedParagraphProperties(?string $styleId): array
    {
        return $this->resolve($styleId)['paragraph'];
    }

    /**
     * Fusionne les propriétés d'un style avec celles déclarées directement sur
     * le paragraphe ou le run.
     *
     * Priorité : valeurs directes > style résolu > défauts du document.
     *
     * @param  array<string, mixed>  $directProperties  Propriétés portées par l'élément
     * @param  null|string  $styleId  Style applicable
     * @return array<string, mixed>
     */
    public function effectiveRunProperties(array $directProperties, ?string $styleId): array
    {
        $resolved = $this->resolvedRunProperties($styleId);

        return $this->mergeProperties($this->defaults['run'] ?? [], $resolved, $directProperties);
    }

    /**
     * Idem pour les propriétés de paragraphe.
     *
     * @param  array<string, mixed>  $directProperties
     * @return array<string, mixed>
     */
    public function effectiveParagraphProperties(array $directProperties, ?string $styleId): array
    {
        $resolved = $this->resolvedParagraphProperties($styleId);

        return $this->mergeProperties($this->defaults['paragraph'] ?? [], $resolved, $directProperties);
    }

    /**
     * Résout un style en appliquant la chaîne d'héritage.
     *
     * @return array{run: array<string, mixed>, paragraph: array<string, mixed>}
     */
    private function resolve(?string $styleId): array
    {
        if ($styleId === null || ! isset($this->styles[$styleId])) {
            return ['run' => [], 'paragraph' => []];
        }

        if (isset($this->resolvedCache[$styleId])) {
            return $this->resolvedCache[$styleId];
        }

        // Protection contre les cycles (un fichier corrompu peut déclarer
        // A basedOn B et B basedOn A → boucle infinie sans ce garde-fou).
        static $inProgress = [];
        if (isset($inProgress[$styleId])) {
            return ['run' => [], 'paragraph' => []];
        }
        $inProgress[$styleId] = true;

        $style = $this->styles[$styleId];
        $parent = $this->resolve($style['based_on']);

        $resolved = [
            'run' => $this->mergeProperties($parent['run'], $style['run']),
            'paragraph' => $this->mergeProperties($parent['paragraph'], $style['paragraph']),
        ];

        unset($inProgress[$styleId]);

        return $this->resolvedCache[$styleId] = $resolved;
    }

    /**
     * Fusion de propriétés : les sources suivantes écrasent les précédentes.
     *
     * Une valeur `null` explicite dans un style sert à ANNULER un héritage
     * (`<w:b w:val="0"/>`) — elle écrase donc bien la valeur parente.
     *
     * @param  array<string, mixed>  ...$sources
     * @return array<string, mixed>
     */
    private function mergeProperties(array ...$sources): array
    {
        $merged = [];

        foreach ($sources as $source) {
            foreach ($source as $key => $value) {
                if ($value !== null) {
                    $merged[$key] = $value;
                }
            }
        }

        return $merged;
    }

    // -------------------------------------------------------------------------
    // Analyse des propriétés OOXML
    // -------------------------------------------------------------------------

    /**
     * Analyse un élément `w:rPr` (propriétés de police).
     *
     * @return array<string, mixed>
     */
    private function parseRunProperties(?DOMElement $runProperties): array
    {
        if ($runProperties === null) {
            return [];
        }

        $properties = [];

        // Taille de police : `w:sz` est en DEMI-POINTS (32 = 16 pt).
        $size = XmlLoader::attributeValue(XmlLoader::firstChild($runProperties, 'w:sz'));
        if ($size !== null) {
            $properties['font_size'] = XmlLoader::halfPointsToPoints($size);
        }

        // Gras / italique / souligné / barré : trois états (absent/vrai/faux).
        foreach (['w:b' => 'is_bold', 'w:i' => 'is_italic', 'w:u' => 'is_underline', 'w:strike' => 'is_strike'] as $tag => $key) {
            $flag = XmlLoader::flag(XmlLoader::firstChild($runProperties, $tag));
            if ($flag !== null) {
                $properties[$key] = $flag;
            }
        }

        // Le soulignement est particulier : `w:u w:val="none"` = pas de soulignement,
        // toute autre valeur = souligné. On remplace la valeur booléenne.
        $underline = XmlLoader::firstChild($runProperties, 'w:u');
        if ($underline !== null) {
            $properties['is_underline'] = XmlLoader::attributeValue($underline) !== 'none';
        }

        // Couleur : « auto » signifie « couleur automatique » (pas de valeur).
        $color = XmlLoader::attributeValue(XmlLoader::firstChild($runProperties, 'w:color'));
        if ($color !== null && $color !== 'auto') {
            $properties['color'] = $color;
        }

        // Police : la même information peut venir de w:ascii, w:hAnsi ou d'une
        // police de thème (majorHAnsi = titres, minorHAnsi = corps).
        $fonts = XmlLoader::firstChild($runProperties, 'w:rFonts');
        if ($fonts !== null) {
            $fontName = null;
            foreach (['w:ascii', 'w:hAnsi', 'w:cs'] as $attribute) {
                $value = XmlLoader::attributeValue($fonts, $attribute);
                if ($value !== null && $value !== '') {
                    $fontName = $value;
                    break;
                }
            }

            if ($fontName !== null) {
                $properties['font_name'] = $fontName;
            }

            // Police de thème : distinguer titre (major) de corps (minor) est
            // un signal utile pour la classification.
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
     * Analyse un élément `w:pPr` (propriétés de paragraphe).
     *
     * @return array<string, mixed>
     */
    private function parseParagraphProperties(?DOMElement $paragraphProperties): array
    {
        if ($paragraphProperties === null) {
            return [];
        }

        $properties = [];

        // Style référencé par ce style (héritage chaîné).
        $styleId = XmlLoader::attributeValue(XmlLoader::firstChild($paragraphProperties, 'w:pStyle'));
        if ($styleId !== null) {
            $properties['p_style'] = $styleId;
        }

        // Alignement : `w:jc` (left, center, right, both).
        $justification = XmlLoader::attributeValue(XmlLoader::firstChild($paragraphProperties, 'w:jc'));
        if ($justification !== null) {
            $properties['alignment'] = $justification;
        }

        // Retrait : `w:ind` en twips.
        $indent = XmlLoader::firstChild($paragraphProperties, 'w:ind');
        if ($indent !== null) {
            foreach (['w:left' => 'indent_left', 'w:right' => 'indent_right', 'w:firstLine' => 'indent_first_line', 'w:hanging' => 'indent_hanging'] as $attribute => $key) {
                $value = XmlLoader::attributeValue($indent, $attribute);
                if ($value !== null) {
                    $properties[$key] = XmlLoader::twipsToPoints($value);
                }
            }
        }

        // Interligne et espacements.
        $spacing = XmlLoader::firstChild($paragraphProperties, 'w:spacing');
        if ($spacing !== null) {
            foreach ([
                'w:line' => 'line_spacing',
                'w:lineRule' => 'line_rule',
                'w:before' => 'space_before',
                'w:after' => 'space_after',
            ] as $attribute => $key) {
                $value = XmlLoader::attributeValue($spacing, $attribute);
                if ($value === null) {
                    continue;
                }

                // `w:line` est en 1/240 de ligne quand lineRule vaut auto.
                $properties[$key] = $key === 'line_rule' ? $value : XmlLoader::twipsToPoints($value);
            }
        }

        // Numérotation de liste (`w:numPr`).
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

        // Pagination.
        $keepNext = XmlLoader::flag(XmlLoader::firstChild($paragraphProperties, 'w:keepNext'));
        if ($keepNext !== null) {
            $properties['keep_with_next'] = $keepNext;
        }

        $pageBreakBefore = XmlLoader::flag(XmlLoader::firstChild($paragraphProperties, 'w:pageBreakBefore'));
        if ($pageBreakBefore !== null) {
            $properties['page_break_before'] = $pageBreakBefore;
        }

        return $properties;
    }

    /**
     * Extrait `w:outlineLvl` d'un `w:pPr`.
     *
     * Rappel : 0 = niveau 1. C'est LE signal déterministe du niveau de titre.
     */
    private function parseOutlineLevel(?DOMElement $paragraphProperties): ?int
    {
        if ($paragraphProperties === null) {
            return null;
        }

        $outline = XmlLoader::firstChild($paragraphProperties, 'w:outlineLvl');
        $value = XmlLoader::attributeValue($outline);

        return $value === null ? null : (int) $value;
    }
}
