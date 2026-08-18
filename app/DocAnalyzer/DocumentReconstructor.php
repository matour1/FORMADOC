<?php

declare(strict_types=1);

namespace App\DocAnalyzer;

use App\Models\CoverPageTemplate;
use App\Services\DocumentGeneration\CoverGenerationService;
use App\Services\DocumentGeneration\CoverPageRenderer;
use DOMDocument;
use DOMXPath;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use ZipArchive;

/**
 * Reconstruction d'un document DOCX à partir de la structure détectée par le
 * DocAnalyzer (Phase 2 — Génération DOCX).
 *
 * Le document généré suit la convention d'un rapport de stage :
 *   - Section 1 (frontispice) : numérotation de page ROMAINE (i, ii, iii…),
 *     sommaire (champ TOC mis à jour à l'ouverture), liste des figures et
 *     liste des tableaux générées depuis les légendes détectées.
 *   - Section 2 (corps) : numérotation ARABE recommençant à 1, titres avec
 *     les styles natifs Heading1/Heading2/Heading3 (utilisés par le TOC).
 *   - En-têtes et pieds de page appliqués aux deux sections, avec un champ
 *     PAGE au format de la section (romain puis arabe).
 *
 * Limite PhpWord : le writer n'écrit que `w:pgNumType w:start`, jamais
 * `w:fmt` (romain/arabe). Un post-traitement XML sur document.xml ajoute
 * `w:fmt` à chaque section après la génération.
 *
 * Contrat d'entrée (AnalyzerResult + légendes) :
 *   { titres, sous_titres, en_tetes, pieds_de_page, tableaux, images,
 *     elements_flottants, legends }
 * Chaque item de titre : { texte, position {section_index, element_index,
 * parent}, niveau (optionnel) }.
 * Chaque légende : { type (Figure|Tableau|…), number, label }.
 */
class DocumentReconstructor
{
    /**
     * Namespace WordprocessingML (post-traitement XML).
     */
    private const WORD_NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    /**
     * Titres de frontispice exclus du corps (gérés dans la section 1 :
     * sommaire, listes…). Comparés en majuscules, sans accents.
     *
     * @var string[]
     */
    private const FRONTISPIECE_TITLES = [
        'SOMMAIRE',
        'TABLE DES MATIERES',
        'TABLE DES MATIÈRES',
        'LISTE DES FIGURES',
        'LISTE DES TABLEAUX',
        'LISTE DES ABREVIATIONS',
        'LISTE DES ABRÉVIATIONS',
        'LISTE DES ANNEXES',
        'SIGLES ET ABREVIATIONS',
        'SIGLES ET ABRÉVIATIONS',
        'REMERCIEMENTS',
        'DEDICACE',
        'RESUME',
        'RÉSUMÉ',
        'ABSTRACT',
    ];

    /**
     * Formats de numérotation de page par section (index de section générée).
     *
     * @var string[]
     */
    private const PAGE_NUMBERING_FORMATS = ['lowerRoman', 'decimal'];

    /**
     * Gabarit de mise en forme normalisé (TemplateStyleResolver::normalize).
     *
     * @var array<string, mixed>
     */
    private array $gabarit = [];

    /**
     * Génère un DOCX complet à partir de la structure analysée.
     *
     * @param array<string, mixed> $analysis   Résultat du DocAnalyzer (+ legends)
     * @param string               $outputPath Chemin absolu du fichier à créer
     * @param null|array<string, mixed> $cover  Couverture optionnelle :
     *                                           - Phase 3 (fichier exemple) :
     *                                             { detection, values }
     *                                           - Builder visuel :
     *                                             { cover_page_template: CoverPageTemplate,
     *                                               values }
     *                                           Si fournie, une section couverture
     *                                           est préfixée.
     *
     * @return string Le chemin du fichier généré
     *
     * @throws \RuntimeException Si l'écriture du DOCX échoue
     */
    public function reconstruct(array $analysis, string $outputPath, ?array $cover = null, ?array $gabarit = null): string
    {
        $phpWord = new PhpWord();
        $phpWord->getSettings()->setUpdateFields(true);

        // Gabarit de mise en forme (params normalisés). null → défauts.
        $this->gabarit = \App\Services\DocumentGeneration\TemplateStyleResolver::normalize($gabarit);
        $this->registerTitleStyles($phpWord);

        // ── Section 0 : couverture (optionnelle) ──────────────────────────────
        // Sans pageNumberingStart : pas de numéro de page, pas d'en-tête/pied.
        if (!empty($cover)) {
            $this->renderCover($phpWord, $cover);
        }

        // ── Section 1 : frontispice (numérotation romaine) ────────────────────
        $sectionStyle = \App\Services\DocumentGeneration\TemplateStyleResolver::sectionStyle($this->gabarit);
        $frontSection = $phpWord->addSection(array_merge(['pageNumberingStart' => 1], $sectionStyle));
        $this->applyHeaderFooter($frontSection, $analysis, 'roman');
        $this->writeFrontispiece($frontSection, $analysis);

        // ── Section 2 : corps (numérotation arabe, redémarre à 1) ─────────────
        $bodySection = $phpWord->addSection(array_merge(['pageNumberingStart' => 1], $sectionStyle));
        $this->applyHeaderFooter($bodySection, $analysis, 'Arabic');
        $this->writeBody($bodySection, $analysis);

        // ── Écriture du DOCX ───────────────────────────────────────────────────
        try {
            $writer = IOFactory::createWriter($phpWord, 'Word2007');
            $writer->save($outputPath);
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                "DocumentReconstructor : échec de génération du DOCX ({$e->getMessage()})",
                0,
                $e
            );
        }

        // ── Post-traitement : w:pgNumType w:fmt (romain/arabe) ────────────────
        $this->applyPageNumberingFormats($outputPath);

        // ── Post-traitement : w:gridSpan sur cellules fusionnées (page de garde)
        $this->applyGridSpan($outputPath);

        return $outputPath;
    }

    /**
     * Rend la couverture selon son origine :
     *   - builder visuel : CoverPageRenderer (ghost-table + placeholders)
     *   - fichier DOCX d'exemple : CoverGenerationService (détection de zones)
     *
     * @param array<string, mixed> $cover { detection, values } ou
     *                                     { cover_page_template, values }
     */
    private function renderCover(PhpWord $phpWord, array $cover): void
    {
        $values = is_array($cover['values'] ?? null) ? $cover['values'] : [];

        // Builder visuel (Phase 6) : un CoverPageTemplate tout prêt
        if (isset($cover['cover_page_template']) && $cover['cover_page_template'] instanceof CoverPageTemplate) {
            (new CoverPageRenderer())->render($phpWord, $cover['cover_page_template'], $values);

            return;
        }

        // Fichier DOCX d'exemple (Phase 3) : détection de zones
        if (!empty($cover['detection']) && is_array($cover['detection'])) {
            (new CoverGenerationService())->addCoverSection($phpWord, $cover['detection'], $values);
        }
    }

    /**
     * Enregistre les styles de titres natifs (nécessaire pour que PhpWord
     * associe chaque Title au style HeadingN → utilisé par le TOC).
     *
     * Les tailles/couleurs/polices ET le style de paragraphe (alignement,
     * espacements, interligne) proviennent du gabarit choisi.
     */
    private function registerTitleStyles(PhpWord $phpWord): void
    {
        $resolver = \App\Services\DocumentGeneration\TemplateStyleResolver::class;
        $paragraphStyle = $resolver::titleParagraphStyle($this->gabarit);

        $phpWord->addTitleStyle(1, $resolver::fontStyle($this->gabarit, 'titre1'), $paragraphStyle);
        $phpWord->addTitleStyle(2, $resolver::fontStyle($this->gabarit, 'titre2'), $paragraphStyle);
        $phpWord->addTitleStyle(3, $resolver::fontStyle($this->gabarit, 'titre3'), $paragraphStyle);
    }

    /**
     * Applique l'en-tête et le pied de page détectés à une section, avec un
     * champ PAGE au format demandé (roman pour le frontispice, arabe pour le
     * corps).
     *
     * @param array<string, mixed> $analysis
     */
    private function applyHeaderFooter(Section $section, array $analysis, string $pageFormat): void
    {
        $headerText = trim((string) ($analysis['en_tetes'][0]['texte'] ?? ''));
        if ($headerText !== '') {
            $section->addHeader()->addText($headerText, ['size' => 9]);
        }

        $footer = $section->addFooter();

        $footerText = $this->cleanFooterText((string) ($analysis['pieds_de_page'][0]['texte'] ?? ''));
        if ($footerText !== '') {
            $footer->addText($footerText, ['size' => 9]);
            $footer->addText('  |  ', ['size' => 9]);
        }

        // Champ PAGE : le format d'affichage suit la numérotation de section
        $footer->addField('PAGE', ['format' => $pageFormat]);
    }

    /**
     * Nettoie le texte de pied de page détecté : retire les champs PAGE
     * littéraux ({PAGE \* MERGEFORMAT}…) et les séparateurs résiduels, car
     * un champ PAGE propre est ré-ajouté par le reconstructeur.
     */
    private function cleanFooterText(string $text): string
    {
        $text = preg_replace('/\{[^}]*PAGE[^}]*\}/i', '', $text) ?? $text;
        $text = trim($text, " \t\n\r\0\x0B|");

        return $text;
    }

    /**
     * Écrit la section frontispice : sommaire (TOC), liste des figures et
     * liste des tableaux (depuis les légendes). Rien si aucun titre.
     *
     * @param array<string, mixed> $analysis
     */
    private function writeFrontispiece(Section $section, array $analysis): void
    {
        $hasTitles = !empty($analysis['titres']) || !empty($analysis['sous_titres']);
        if (!$hasTitles) {
            return;
        }

        // Sommaire : titre natif + champ TOC (niveaux 1-3, mis à jour à l'ouverture)
        $section->addTitle('SOMMAIRE', 1);
        $section->addTOC(null, null, 1, 3);

        // Liste des figures (légendes de type Figure)
        $figures = $this->legendsByType($analysis, ['figure', 'fig']);
        if ($figures !== []) {
            $section->addTitle('Liste des figures', 1);
            foreach ($figures as $legend) {
                $section->addText(sprintf(
                    'Figure %s : %s',
                    $legend['number'] ?? '?',
                    $legend['label'] ?? ''
                ));
            }
        }

        // Liste des tableaux (légendes de type Tableau)
        $tableaux = $this->legendsByType($analysis, ['tableau', 'table']);
        if ($tableaux !== []) {
            $section->addTitle('Liste des tableaux', 1);
            foreach ($tableaux as $legend) {
                $section->addText(sprintf(
                    'Tableau %s : %s',
                    $legend['number'] ?? '?',
                    $legend['label'] ?? ''
                ));
            }
        }
    }

    /**
     * Écrit le corps du document : TOUS les éléments détectés (titres,
     * sous-titres, paragraphes, listes, tableaux, images) dans l'ordre
     * d'apparition, avec la mise en forme du gabarit choisi.
     *
     * La source de vérité est `body_complet` (extrait par DocumentParser,
     * conservé dans la structure) : chaque élément est restitué à sa
     * position, sans perte de contenu. Les catégories `titres`/`sous_titres`
     * servent uniquement de repli si `body_complet` est absent (anciens
     * documents analysés avant cette version).
     *
     * @param array<string, mixed> $analysis
     */
    private function writeBody(Section $section, array $analysis): void
    {
        $resolver = \App\Services\DocumentGeneration\TemplateStyleResolver::class;
        $bodyComplet = $analysis['body_complet'] ?? [];

        // ── Mode principal : body_complet présent (reconstruction fidèle) ──
        if (is_array($bodyComplet) && $bodyComplet !== []) {
            foreach ($bodyComplet as $element) {
                $texte = trim((string) ($element['text'] ?? ''));
                $type = (string) ($element['type'] ?? 'autre');
                if ($texte === '' && !in_array($type, ['image', 'saut', 'tableau'], true)) {
                    continue;
                }

                // Titres de frontispice (SOMMAIRE, listes…) : gérés en section 1
                if (in_array(mb_strtoupper($texte), self::FRONTISPIECE_TITLES, true)) {
                    continue;
                }

                $this->writeElement($section, $element, $resolver);
            }

            return;
        }

        // ── Repli : uniquement les catégories titres/sous_titres triées ─────
        $items = [];

        foreach (($analysis['titres'] ?? []) as $item) {
            $item['niveau'] = (int) ($item['niveau'] ?? 1);
            $items[] = $item;
        }

        foreach (($analysis['sous_titres'] ?? []) as $item) {
            $item['niveau'] = (int) ($item['niveau'] ?? 2);
            $items[] = $item;
        }

        // Tri par position (section_index, puis element_index)
        usort($items, static function (array $a, array $b): int {
            return self::comparePositions(
                $a['position'] ?? null,
                $b['position'] ?? null
            );
        });

        foreach ($items as $item) {
            $texte = trim((string) ($item['texte'] ?? ''));
            if ($texte === '') {
                continue;
            }

            // Les titres de frontispice (SOMMAIRE, listes…) sont en section 1
            if (in_array(mb_strtoupper($texte), self::FRONTISPIECE_TITLES, true)) {
                continue;
            }

            $niveau = min(3, max(1, (int) ($item['niveau'] ?? 1)));
            $section->addTitle($texte, $niveau);
        }
    }

    /**
     * Restitue un élément unique du body complet selon son type.
     *
     * @param array<string, mixed> $element
     * @param class-string         $resolver
     */
    private function writeElement(Section $section, array $element, string $resolver): void
    {
        $type = $element['type'] ?? 'autre';
        $texte = trim((string) ($element['text'] ?? ''));

        switch ($type) {
            case 'titre':
                $niveau = min(3, max(1, (int) ($element['depth'] ?? 1)));
                $section->addTitle($texte, $niveau);
                break;

            case 'liste':
                $depth = (int) ($element['depth'] ?? 0);
                $section->addListItem(
                    $texte,
                    $depth,
                    $resolver::fontStyle($this->gabarit, 'corps'),
                    null,
                    $resolver::bodyParagraphStyle($this->gabarit)
                );
                break;

            case 'tableau':
                $this->writeTable($section, $element, $resolver);
                break;

            case 'image':
                $this->writeImage($section, $element);
                break;

            case 'saut':
                $section->addTextBreak();
                break;

            default:
                // 'texte' et tout type inconnu : paragraphe de corps stylé
                $section->addText(
                    $texte,
                    $resolver::fontStyle($this->gabarit, 'corps'),
                    $resolver::bodyParagraphStyle($this->gabarit)
                );
                break;
        }
    }

    /**
     * Restitue un tableau avec son contenu (lignes → cellules) et le style
     * de gabarit (bordure, en-tête coloré).
     *
     * @param array<string, mixed> $element
     * @param class-string         $resolver
     */
    private function writeTable(Section $section, array $element, string $resolver): void
    {
        $rows = $element['rows'] ?? [];
        if (!is_array($rows) || $rows === []) {
            // Tableau sans contenu extrait : on le signale sans le perdre
            $section->addText('[Tableau]', $resolver::fontStyle($this->gabarit, 'corps'));
            return;
        }

        $table = $section->addTable($resolver::tableStyle($this->gabarit));
        $headerStyle = $resolver::tableHeaderStyle($this->gabarit);
        $cellStyle = ['valign' => 'center'];
        $bodyFont = $resolver::fontStyle($this->gabarit, 'corps');

        foreach ($rows as $rowIndex => $row) {
            $cells = is_array($row['cells'] ?? null) ? $row['cells'] : [];
            $table->addRow();

            foreach ($cells as $cellIndex => $cellText) {
                $isHeader = $rowIndex === 0;
                $font = $isHeader ? $headerStyle : $bodyFont;

                $table->addCell(null, $cellStyle)->addText(
                    (string) $cellText,
                    $font
                );
            }
        }
    }

    /**
     * Restitue une image depuis son binaire (base64 stocké dans la structure).
     *
     * @param array<string, mixed> $element
     */
    private function writeImage(Section $section, array $element): void
    {
        $data = $element['image_data'] ?? null;
        if (!is_string($data) || $data === '') {
            $name = (string) ($element['image_name'] ?? 'image');
            $section->addText("[Image: {$name}]");

            return;
        }

        $extension = (string) ($element['image_extension'] ?? 'png');
        $tmpPath = tempnam(sys_get_temp_dir(), 'fdimg_');
        if ($tmpPath === false) {
            return;
        }

        // Extension correcte pour que PhpWord détecte le type MIME
        $tmpPath = $tmpPath . '.' . $extension;
        file_put_contents($tmpPath, base64_decode($data));

        try {
            $section->addImage($tmpPath, [
                'width' => 320,
                'height' => 240,
                'alignment' => 'center',
            ]);
        } catch (\Throwable $e) {
            // Image illisible : on la signale sans bloquer la génération
            $section->addText('[Image: ' . ($element['image_name'] ?? '') . ']');
        } finally {
            @unlink($tmpPath);
        }
    }

    /**
     * Retourne les légendes d'un type donné (insensible à la casse).
     *
     * @param array<string, mixed> $analysis
     * @param string[]             $types
     *
     * @return array<int, array<string, mixed>>
     */
    private function legendsByType(array $analysis, array $types): array
    {
        $legends = [];

        foreach (($analysis['legends'] ?? []) as $legend) {
            $type = mb_strtolower((string) ($legend['type'] ?? ''));
            if (in_array($type, $types, true)) {
                $legends[] = $legend;
            }
        }

        return $legends;
    }

    /**
     * Compare deux positions (null = fin de liste).
     *
     * @param mixed $a
     * @param mixed $b
     */
    private static function comparePositions($a, $b): int
    {
        if ($a === null && $b === null) {
            return 0;
        }
        if ($a === null) {
            return 1;
        }
        if ($b === null) {
            return -1;
        }

        $sa = (int) ($a['section_index'] ?? 0);
        $sb = (int) ($b['section_index'] ?? 0);
        if ($sa !== $sb) {
            return $sa <=> $sb;
        }

        $ea = (int) ($a['element_index'] ?? 0);
        $eb = (int) ($b['element_index'] ?? 0);

        return $ea <=> $eb;
    }

    /**
     * Post-traitement XML : ajoute w:fmt (lowerRoman / decimal) à chaque
     * section NUMÉROTÉE (celles qui possèdent déjà un w:pgNumType, créé par
     * PhpWord via pageNumberingStart), car PhpWord n'écrit que w:start.
     *
     * La section couverture (Phase 3), ajoutée sans pageNumberingStart, n'a
     * pas de w:pgNumType : on la laisse sans format de numérotation.
     *
     * En cas d'échec (ZIP illisible, XML invalide), on laisse le fichier tel
     * quel : la génération ne doit jamais être bloquée par ce raffinement.
     */
    private function applyPageNumberingFormats(string $docxPath): void
    {
        $zip = new ZipArchive();
        if ($zip->open($docxPath) !== true) {
            return;
        }

        $xml = $zip->getFromName('word/document.xml');
        if ($xml === false) {
            $zip->close();

            return;
        }

        $dom = new DOMDocument();
        if (!$dom->loadXML($xml)) {
            $zip->close();

            return;
        }

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('w', self::WORD_NS);

        $sectPrs = $xpath->query('//w:sectPr');

        if ($sectPrs !== false) {
            $formatIndex = 0;

            foreach ($sectPrs as $sectPr) {
                // w:pgNumType existe déjà (pageNumberingStart=1) : on le trouve.
                // Une section sans w:pgNumType (couverture) est ignorée.
                $pgNumType = null;
                foreach ($sectPr->childNodes as $child) {
                    if ($child instanceof \DOMElement && $child->nodeName === 'w:pgNumType') {
                        $pgNumType = $child;

                        break;
                    }
                }

                if ($pgNumType === null) {
                    continue;
                }

                if (!isset(self::PAGE_NUMBERING_FORMATS[$formatIndex])) {
                    break;
                }

                $pgNumType->setAttributeNS(self::WORD_NS, 'w:fmt', self::PAGE_NUMBERING_FORMATS[$formatIndex]);
                $pgNumType->setAttributeNS(self::WORD_NS, 'w:start', '1');

                $formatIndex++;
            }
        }

        $newXml = $dom->saveXML();
        if (is_string($newXml) && $newXml !== '') {
            $zip->deleteName('word/document.xml');
            $zip->addFromString('word/document.xml', $newXml);
        }

        $zip->close();
    }

    /**
     * Post-traitement : nettoie les marqueurs `<!--gridspan:N-->` résiduels
     * et garantit `w:gridSpan` sur les cellules fusionnées (page de garde).
     *
     * Depuis PhpWord 1.4, `w:gridSpan` est écrit nativement par le writer
     * Word2007 : ce post-traitement ne sert donc que de filet de sécurité
     * pour les DOCX générés par des versions antérieures (marqueur HTML
     * injecté par le CoverPageRenderer). Public car utilisé aussi par
     * l'aperçu serveur (CoverPageTemplateController::preview).
     */
    public function applyGridSpan(string $docxPath): void
    {
        try {
            $zip = new ZipArchive();
            if ($zip->open($docxPath) !== true) {
                return;
            }

            $xml = $zip->getFromName('word/document.xml');
            if ($xml === false) {
                $zip->close();
                return;
            }

            // Aucun marqueur → rien à faire (PhpWord natif a déjà écrit gridSpan)
            if (strpos($xml, 'gridspan') === false) {
                $zip->close();
                return;
            }

            $dom = new DOMDocument();
            if (!$dom->loadXML($xml)) {
                $zip->close();
                return;
            }

            $xpath = new DOMXPath($dom);
            $xpath->registerNamespace('w', self::WORD_NS);
            $ns = self::WORD_NS;

            // 1) Cellules portant un marqueur (ancien format) : injecte gridSpan
            $markerTcs = $xpath->query('//w:tc[contains(., "gridspan")]');
            if ($markerTcs !== false) {
                foreach ($markerTcs as $tc) {
                    if (!$tc instanceof \DOMElement) {
                        continue;
                    }
                    // Le marqueur est soit un commentaire XML, soit du texte échappé
                    $span = $this->extractGridSpanMarker($xpath, $tc);
                    if ($span <= 1) {
                        continue;
                    }

                    $tcPr = $xpath->query('./w:tcPr', $tc)->item(0);
                    if (!$tcPr instanceof \DOMElement) {
                        $tcPr = $dom->createElementNS($ns, 'w:tcPr');
                        $tc->insertBefore($tcPr, $tc->firstChild);
                    }
                    if (!$xpath->query('./w:gridSpan', $tcPr)->item(0) instanceof \DOMElement) {
                        $gridSpan = $dom->createElementNS($ns, 'w:gridSpan');
                        $gridSpan->setAttributeNS($ns, 'w:val', (string) $span);
                        $tcPr->appendChild($gridSpan);
                    }
                }
            }

            // 2) Supprime les commentaires XML marqueurs
            foreach ($xpath->query('//comment()') as $comment) {
                if ($comment instanceof \DOMComment && preg_match('/gridspan:\d+/', $comment->nodeValue ?? '')) {
                    $comment->parentNode?->removeChild($comment);
                }
            }

            // 3) Supprime les paragraphes dont le texte n'est QUE le marqueur
            $markerPs = $xpath->query('//w:p[contains(., "gridspan")]');
            if ($markerPs !== false) {
                $toRemove = [];
                foreach ($markerPs as $p) {
                    if (!$p instanceof \DOMElement) {
                        continue;
                    }
                    $clean = preg_replace('/<!--gridspan:\d+-->/', '', trim($p->textContent ?? '')) ?? '';
                    if (trim($clean) === '') {
                        $toRemove[] = $p;
                    }
                }
                foreach ($toRemove as $p) {
                    $p->parentNode?->removeChild($p);
                }
            }

            // 4) Filet de sécurité : retire le marqueur du texte résiduel
            foreach ($xpath->query('//w:t[contains(., "gridspan")]') as $t) {
                if ($t instanceof \DOMText) {
                    $t->nodeValue = preg_replace('/<!--gridspan:\d+-->/', '', $t->nodeValue ?? '') ?? $t->nodeValue;
                }
            }

            $newXml = $dom->saveXML();
            if (is_string($newXml) && $newXml !== '') {
                $zip->deleteName('word/document.xml');
                $zip->addFromString('word/document.xml', $newXml);
            }

            $zip->close();
        } catch (\Throwable $e) {
            // Post-traitement best-effort : jamais bloquant
        }
    }

    /**
     * Extrait la valeur d'un marqueur gridspan (commentaire XML ou texte)
     * présent dans la cellule.
     */
    private function extractGridSpanMarker(DOMXPath $xpath, \DOMElement $tc): int
    {
        // 1) Commentaires XML directs
        foreach ($xpath->query('.//comment()', $tc) as $comment) {
            if ($comment instanceof \DOMComment
                && preg_match('/<!--\s*gridspan:\s*(\d+)\s*-->/', $comment->nodeValue ?? '', $m)) {
                return max(1, min(12, (int) $m[1]));
            }
        }
        // 2) Texte échappé
        if (preg_match('/<!--\s*gridspan:\s*(\d+)\s*-->/', $tc->textContent ?? '', $m)) {
            return max(1, min(12, (int) $m[1]));
        }
        return 1;
    }
}
