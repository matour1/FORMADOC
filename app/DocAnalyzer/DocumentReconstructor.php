<?php

declare(strict_types=1);

namespace App\DocAnalyzer;

use App\Services\DocumentGeneration\CoverGenerationService;
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
     * Génère un DOCX complet à partir de la structure analysée.
     *
     * @param array<string, mixed> $analysis   Résultat du DocAnalyzer (+ legends)
     * @param string               $outputPath Chemin absolu du fichier à créer
     * @param null|array<string, mixed> $cover  Couverture optionnelle (Phase 3) :
     *                                           { detection, values }. Si fournie,
     *                                           une section couverture est préfixée.
     *
     * @return string Le chemin du fichier généré
     *
     * @throws \RuntimeException Si l'écriture du DOCX échoue
     */
    public function reconstruct(array $analysis, string $outputPath, ?array $cover = null): string
    {
        $phpWord = new PhpWord();
        $phpWord->getSettings()->setUpdateFields(true);

        $this->registerTitleStyles($phpWord);

        // ── Section 0 : couverture (Phase 3, optionnelle) ─────────────────────
        // Sans pageNumberingStart : pas de numéro de page, pas d'en-tête/pied.
        if (!empty($cover['detection']) && is_array($cover['values'] ?? null)) {
            (new CoverGenerationService())->addCoverSection(
                $phpWord,
                $cover['detection'],
                $cover['values']
            );
        }

        // ── Section 1 : frontispice (numérotation romaine) ────────────────────
        $frontSection = $phpWord->addSection(['pageNumberingStart' => 1]);
        $this->applyHeaderFooter($frontSection, $analysis, 'roman');
        $this->writeFrontispiece($frontSection, $analysis);

        // ── Section 2 : corps (numérotation arabe, redémarre à 1) ─────────────
        $bodySection = $phpWord->addSection(['pageNumberingStart' => 1]);
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

        return $outputPath;
    }

    /**
     * Enregistre les styles de titres natifs (nécessaire pour que PhpWord
     * associe chaque Title au style HeadingN → utilisé par le TOC).
     */
    private function registerTitleStyles(PhpWord $phpWord): void
    {
        $phpWord->addTitleStyle(1, ['bold' => true, 'size' => 16, 'color' => '000000']);
        $phpWord->addTitleStyle(2, ['bold' => true, 'size' => 14, 'color' => '000000']);
        $phpWord->addTitleStyle(3, ['bold' => true, 'size' => 12, 'color' => '000000']);
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
     * Écrit le corps du document : tous les titres et sous-titres (hors
     * frontispice), triés par position, avec leur style HeadingN.
     *
     * @param array<string, mixed> $analysis
     */
    private function writeBody(Section $section, array $analysis): void
    {
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
}
