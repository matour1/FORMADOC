<?php

declare(strict_types=1);

namespace App\DocAnalyzer;

use DOMDocument;
use DOMXPath;
use InvalidArgumentException;
use PhpOffice\PhpWord\Element\AbstractContainer;
use PhpOffice\PhpWord\Element\AbstractElement;
use PhpOffice\PhpWord\Element\Footer;
use PhpOffice\PhpWord\Element\Header;
use PhpOffice\PhpWord\Element\Image;
use PhpOffice\PhpWord\Element\PreserveText;
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

        // Extraction XML brute des en-têtes/pieds de page (les textboxes
        // graphiques y sont ignorées par PhpWord : on complète par le XML).
        $xmlHeaderFooterTexts = $this->extractHeaderFooterXmlTexts();

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
                $headerParsed = [];

                foreach ($header->getElements() as $element) {
                    $parsed = $this->parseElement($element, $sectionIndex, 'header');
                    if ($parsed !== null) {
                        $headerParsed[] = $parsed;
                    }
                }

                // Les en-têtes "graphiques" (texte dans des textboxes
                // wps:txbx / v:textbox) sont ignorés par PhpWord, qui ne
                // retourne souvent qu'un artefact (coordonnée numérique).
                // Si le texte brut XML est plus riche, on le préfère.
                $headerParsed = $this->preferXmlText(
                    $headerParsed,
                    $xmlHeaderFooterTexts[$sectionIndex]['header'] ?? '',
                    $sectionIndex,
                    'header'
                );

                foreach ($headerParsed as $parsed) {
                    $headers[] = $parsed;
                    $contextParts[] = $this->elementToContextText($parsed);
                    $positionParts[] = $this->elementToPositionText($parsed);
                }
            }

            foreach ($section->getFooters() as $footer) {
                $footerParsed = [];

                foreach ($footer->getElements() as $element) {
                    $parsed = $this->parseElement($element, $sectionIndex, 'footer');
                    if ($parsed !== null) {
                        $footerParsed[] = $parsed;
                    }
                }

                // Même complément XML pour les pieds de page graphiques.
                $footerParsed = $this->preferXmlText(
                    $footerParsed,
                    $xmlHeaderFooterTexts[$sectionIndex]['footer'] ?? '',
                    $sectionIndex,
                    'footer'
                );

                foreach ($footerParsed as $parsed) {
                    $footers[] = $parsed;
                    $contextParts[] = $this->elementToContextText($parsed);
                    $positionParts[] = $this->elementToPositionText($parsed);
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
     * Les fichiers texte brut (.txt) ne sont pas des archives ZIP : on
     * construit alors un PhpWord virtuel (1 section, 1 paragraphe par ligne)
     * pour que le pipeline d'analyse (positions, regex, IA) fonctionne
     * identiquement.
     *
     * @throws RuntimeException Si le chargement échoue (fichier corrompu, format non supporté…)
     */
    private function load(): PhpWord
    {
        try {
            // Extension .txt → PhpWord ne sait pas le lire (ZIP) → texte brut
            if (strtolower(pathinfo($this->filePath, PATHINFO_EXTENSION)) === 'txt') {
                return $this->phpWordFromPlainText();
            }

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
     * Construit un PhpWord virtuel depuis un fichier texte brut.
     *
     * Chaque ligne non vide devient un paragraphe Text (styles vides) : le
     * RuleBasedDetector ne trouvera pas de titres par styles (aucun Heading),
     * ce qui déclenchera la passe regex, ou le fallback IA en cas d'échec.
     */
    private function phpWordFromPlainText(): PhpWord
    {
        $content = file_get_contents($this->filePath);

        if ($content === false) {
            throw new RuntimeException('DocumentParser : fichier texte illisible.');
        }

        $phpWord = new PhpWord();
        $section = $phpWord->addSection();

        foreach (preg_split('/\r?\n/', $content) ?: [] as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }
            $section->addText($trimmed);
        }

        return $phpWord;
    }

    /**
     * Lit les textes des en-têtes/pieds de page directement dans le XML DOCX.
     *
     * PhpWord ignore les textboxes graphiques (wps:txbx / v:textbox) dans les
     * en-têtes/pieds : le texte "décoratif" (titre du document, thème…) y est
     * donc perdu. On relit ici chaque partie header/footer via ZipArchive et
     * on extrait TOUS les <w:t> (y compris ceux des textboxes).
     *
     * Le rendu Word étant souvent dupliqué (mc:Choice + mc:Fallback), on
     * déduplique les paragraphes identiques et on ignore les valeurs
     * purement numériques (coordonnées wp:posOffset…).
     *
     * @return array<int, array{header: string, footer: string}>
     */
    private function extractHeaderFooterXmlTexts(): array
    {
        $result = [];
        $zip = null;

        // Les fichiers texte brut ne sont pas des archives ZIP : rien à lire.
        if (strtolower(pathinfo($this->filePath, PATHINFO_EXTENSION)) === 'txt') {
            return $result;
        }

        try {
            $zip = new \ZipArchive();
            if ($zip->open($this->filePath) !== true) {
                return $result;
            }

            // 1. Map rId → fichier (headerN.xml / footerN.xml)
            $rels = $zip->getFromName('word/_rels/document.xml.rels') ?: '';
            preg_match_all(
                '/<Relationship[^>]*Id="([^"]+)"[^>]*Target="([^"]+)"/',
                $rels,
                $matches,
                PREG_SET_ORDER
            );

            $rIdToFile = [];
            foreach ($matches as $rel) {
                $target = basename((string) $rel[2]);
                if (preg_match('/^(header|footer)\d+\.xml$/', $target, $m)) {
                    $rIdToFile[(string) $rel[1]] = [
                        'file' => 'word/' . $target,
                        'zone' => $m[1],
                    ];
                }
            }

            // 2. Lecture des sections dans l'ordre : chaque sectPr référence
            //    header/footer avec un rId (type default/even/first).
            $docXml = $zip->getFromName('word/document.xml') ?: '';
            $dom = new DOMDocument();
            if ($docXml === '' || !@$dom->loadXML($docXml)) {
                return $result;
            }

            $xp = new DOMXPath($dom);
            $xp->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
            $xp->registerNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');

            $sectPrs = $xp->query('//w:sectPr');
            $sectionIndex = 0;

            foreach ($sectPrs as $sectPr) {
                // Le dernier sectPr (direct sous body) n'ouvre pas de section.
                $parent = $sectPr->parentNode;
                if ($parent !== null && $parent->parentNode !== null
                    && $parent->parentNode->localName === 'body') {
                    continue;
                }

                $entry = ['header' => '', 'footer' => ''];

                foreach (['headerReference' => 'header', 'footerReference' => 'footer'] as $tag => $zone) {
                    $nodes = $xp->query('.//w:' . $tag, $sectPr);
                    foreach ($nodes as $node) {
                        if (!$node instanceof \DOMElement) {
                            continue;
                        }

                        $rId = $node->getAttributeNS(
                            'http://schemas.openxmlformats.org/officeDocument/2006/relationships',
                            'id'
                        );
                        if (!isset($rIdToFile[$rId])) {
                            continue;
                        }

                        $file = $rIdToFile[$rId]['file'];
                        $partXml = $zip->getFromName($file) ?: '';
                        if ($partXml === '') {
                            continue;
                        }

                        $text = $this->extractTextsFromXml($partXml);
                        if (trim($text) !== '') {
                            $entry[$zone] = $text;
                        }
                    }
                }

                $result[$sectionIndex] = $entry;
                $sectionIndex++;
            }
        } finally {
            if ($zip !== null) {
                $zip->close();
            }
        }

        return $result;
    }

    /**
     * Extrait et nettoie les textes <w:t> d'une partie XML header/footer.
     */
    private function extractTextsFromXml(string $xml): string
    {
        $dom = new DOMDocument();
        if (!@$dom->loadXML($xml)) {
            return '';
        }

        $xp = new DOMXPath($dom);
        $xp->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

        // Supprime le rendu "Fallback" de mc:AlternateContent : Word écrit
        // le même contenu 2× (mc:Choice + mc:Fallback), on garde le Choice.
        $fallbacks = $xp->query('//*[local-name()="Fallback"]');
        foreach ($fallbacks as $fb) {
            $fb->parentNode->removeChild($fb);
        }

        // Paragraphes : on conserve leur ordre, en dédupliquant le double
        // rendu et les fragments consécutifs identiques.
        $paragraphs = [];
        $seen = [];

        $pNodes = $xp->query('//w:p');
        foreach ($pNodes as $pNode) {
            $tNodes = $xp->query('.//w:t', $pNode);
            $fragments = [];
            $previous = null;
            foreach ($tNodes as $tNode) {
                $fragment = (string) $tNode->nodeValue;
                // Dédup des fragments consécutifs identiques (double rendu)
                if ($fragment === $previous) {
                    continue;
                }
                $previous = $fragment;
                $fragments[] = $fragment;
            }

            $text = trim(html_entity_decode(implode('', $fragments), ENT_QUOTES | ENT_XML1, 'UTF-8'));

            // On ignore les valeurs purement numériques : ce sont souvent des
            // coordonnées (wp:posOffset) interprétées à tort comme du texte.
            if ($text === '' || preg_match('/^[\d\s.,-]+$/', $text)) {
                continue;
            }

            $key = mb_strtolower($text);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $paragraphs[] = $text;
        }

        return implode("\n", $paragraphs);
    }

    /**
     * Privilégie le texte XML complet des en-têtes/pieds lorsque PhpWord
     * n'a extrait qu'un artefact (ou rien).
     *
     * @param array<int, array<string, mixed>> $parsedElements
     *
     * @return array<int, array<string, mixed>>
     */
    private function preferXmlText(array $parsedElements, string $xmlText, int $sectionIndex, string $parent): array
    {
        $xmlText = trim($xmlText);

        if ($xmlText === '') {
            return $parsedElements;
        }

        // Texte PhpWord : concaténation des textes extraits
        $phpWordText = trim(implode(' ', array_map(
            static fn (array $el) => (string) ($el['text'] ?? ''),
            $parsedElements
        )));

        // Le texte XML est-il plus riche / différent ?
        $xmlNormalized = $this->normalizeForCompare($xmlText);
        $phpWordNormalized = $this->normalizeForCompare($phpWordText);

        $xmlHasMore = $this->isMoreInformative($xmlNormalized, $phpWordNormalized);

        if ($xmlHasMore) {
            $parsed = [
                'text' => $xmlText,
                'type' => 'texte',
                'styles' => ['font' => null, 'paragraph' => null],
                'position' => [
                    'section_index' => $sectionIndex,
                    'element_index' => $this->globalElementIndex++,
                    'parent' => $parent,
                ],
            ];

            return [$parsed];
        }

        return $parsedElements;
    }

    /**
     * Compare deux textes normalisés pour décider si le texte XML est plus
     * informatif que le texte PhpWord.
     */
    private function isMoreInformative(string $xmlText, string $phpWordText): bool
    {
        // Le XML est plus informatif s'il contient du texte non présent
        // dans l'extraction PhpWord (cas textboxes), ou si l'extraction
        // PhpWord est vide / purement numérique.
        if ($phpWordText === '') {
            return $xmlText !== '';
        }

        // Phrases significatives du XML absentes du PhpWord
        $xmlWords = preg_split('/[\s\p{P}]+/u', $xmlText) ?: [];
        $meaningful = array_values(array_filter(
            $xmlWords,
            static fn (string $w) => mb_strlen($w) > 2 && !preg_match('/^\d+$/', $w)
        ));

        $phpWordWords = preg_split('/[\s\p{P}]+/u', $phpWordText) ?: [];

        $missing = 0;
        foreach ($meaningful as $word) {
            if (!in_array($word, $phpWordWords, true)) {
                $missing++;
            }
        }

        // Si plus de la moitié des mots significatifs du XML manquent au
        // PhpWord, le XML est plus riche (textbox ignorée).
        return count($meaningful) > 0 && $missing / count($meaningful) > 0.5;
    }

    /**
     * Normalise un texte pour comparaison (minuscules, sans ponctuation).
     */
    private function normalizeForCompare(string $text): string
    {
        $text = mb_strtolower($text);
        $text = str_replace(["'", '’', '"', '«', '»'], ' ', $text);

        return preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text) ?? $text;
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
            $element instanceof Text, $element instanceof TextRun, $element instanceof PreserveText => 'texte',
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

        if ($element instanceof PreserveText) {
            return $this->preserveText($element);
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
     * Texte d'un élément PreserveText (contenu préservé entre accolades,
     * ex: champs Word "{ PAGE \* MERGEFORMAT }").
     */
    private function preserveText(PreserveText $preserveText): string
    {
        $text = $preserveText->getText();

        if (is_array($text)) {
            return implode('', array_map('strval', $text));
        }

        return (string) ($text ?? '');
    }

    /**
     * Concatène le texte d'un conteneur (TextRun, Header, Footer, Cell…).
     *
     * Gère les éléments imbriqués : Text, TextRun (récursif), Title (dans
     * les en-têtes Word peut contenir un style de titre), PreserveText,
     * et les tableaux (pieds de page en tableau — fréquent).
     */
    private function containerText(AbstractContainer $container): string
    {
        $parts = [];

        foreach ($container->getElements() as $child) {
            if ($child instanceof Text) {
                $parts[] = (string) ($child->getText() ?? '');
            } elseif ($child instanceof TextRun) {
                $parts[] = $this->containerText($child);
            } elseif ($child instanceof Title) {
                $parts[] = $this->titleText($child);
            } elseif ($child instanceof PreserveText) {
                $parts[] = $this->preserveText($child);
            } elseif ($child instanceof Table) {
                $parts[] = $this->tableText($child);
            } elseif ($child instanceof Image) {
                $name = $child->getName() ?: basename((string) $child->getSource());
                $parts[] = "[image:{$name}]";
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
                $cellText = trim($this->containerText($cell));
                if ($cellText !== '') {
                    $cells[] = $cellText;
                }
            }

            if ($cells !== []) {
                $rows[] = implode(' | ', $cells);
            }
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
