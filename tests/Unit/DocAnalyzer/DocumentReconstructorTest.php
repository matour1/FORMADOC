<?php

declare(strict_types=1);

namespace Tests\Unit\DocAnalyzer;

use App\DocAnalyzer\DocumentReconstructor;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use Tests\TestCase;

/**
 * Tests du DocumentReconstructor : génération d'un DOCX à partir de la
 * structure détectée par le DocAnalyzer (Phase 2 — Génération DOCX).
 *
 * On vérifie que le document généré contient bien :
 *  - des titres avec les styles natifs Heading1/Heading2/Heading3 (TOC),
 *  - une section frontispice en numérotation romaine, puis le corps en
 *    numérotation arabe recommençant à 1 (bascule via section break),
 *  - un champ TOC (sommaire) mis à jour automatiquement (updateFields),
 *  - en-têtes et pieds de page (avec champ PAGE au bon format),
 *  - une liste des figures / tableaux générée depuis les légendes.
 */
class DocumentReconstructorTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/formadoc_reconstruct_' . uniqid();
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tempDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tempDir);
        parent::tearDown();
    }

    /**
     * Structure d'analyse minimale et réaliste (contrat AnalyzerResult +
     * légendes), typique d'un rapport de stage :
     *  - 3 titres niveau 1, 2 sous-titres niveau 2, 1 sous-titre niveau 3
     *  - en-tête + pied de page réels
     *  - légendes figures / tableaux
     */
    private function sampleAnalysis(): array
    {
        return [
            'titres' => [
                [
                    'texte' => 'SOMMAIRE',
                    'position' => ['section_index' => 0, 'element_index' => 0, 'parent' => 'body'],
                    'styles' => [],
                    'type' => 'titres',
                    'niveau' => 1,
                ],
                [
                    'texte' => 'INTRODUCTION',
                    'position' => ['section_index' => 1, 'element_index' => 10, 'parent' => 'body'],
                    'styles' => [],
                    'type' => 'titres',
                    'niveau' => 1,
                ],
                [
                    'texte' => 'CONCLUSION',
                    'position' => ['section_index' => 1, 'element_index' => 90, 'parent' => 'body'],
                    'styles' => [],
                    'type' => 'titres',
                    'niveau' => 1,
                ],
            ],
            'sous_titres' => [
                [
                    'texte' => '1. CONTEXTE GENERAL',
                    'position' => ['section_index' => 1, 'element_index' => 11, 'parent' => 'body'],
                    'styles' => [],
                    'type' => 'sous_titres',
                    'niveau' => 2,
                ],
                [
                    'texte' => '1.1 Présentation du projet',
                    'position' => ['section_index' => 1, 'element_index' => 12, 'parent' => 'body'],
                    'styles' => [],
                    'type' => 'sous_titres',
                    'niveau' => 3,
                ],
                [
                    'texte' => '2. METHODOLOGIE',
                    'position' => ['section_index' => 1, 'element_index' => 40, 'parent' => 'body'],
                    'styles' => [],
                    'type' => 'sous_titres',
                    'niveau' => 2,
                ],
            ],
            'en_tetes' => [
                [
                    'texte' => 'CONCEPTION ET REALISATION D\'UNE PLATEFORME',
                    'position' => ['section_index' => 0, 'element_index' => 2, 'parent' => 'header'],
                    'styles' => ['font' => null, 'paragraph' => null],
                    'type' => 'en_tetes',
                ],
            ],
            'pieds_de_page' => [
                [
                    'texte' => 'Rédigé par : JEAN',
                    'position' => ['section_index' => 0, 'element_index' => 3, 'parent' => 'footer'],
                    'styles' => ['font' => null, 'paragraph' => null],
                    'type' => 'pieds_de_page',
                ],
            ],
            'tableaux' => [],
            'images' => [],
            'elements_flottants' => [],
            'legends' => [
                ['type' => 'Figure', 'number' => 1, 'label' => 'Architecture du système', 'line' => 12],
                ['type' => 'Figure', 'number' => 2, 'label' => 'Diagramme de classes', 'line' => 30],
                ['type' => 'Tableau', 'number' => 1, 'label' => 'Récapitulatif des besoins', 'line' => 25],
            ],
        ];
    }

    /**
     * Extrait le XML d'une partie du DOCX généré (document.xml, settings.xml…).
     */
    private function readPart(string $docxPath, string $partName): string
    {
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($docxPath), 'Le DOCX généré doit être un ZIP valide');
        $xml = $zip->getFromName($partName);
        $zip->close();

        $this->assertIsString($xml, "La partie {$partName} doit exister");
        $this->assertNotEmpty($xml, "La partie {$partName} ne doit pas être vide");

        return $xml;
    }

    /**
     * Relit le DOCX généré via PhpWord pour vérifier les éléments.
     */
    private function reloadDocx(string $docxPath): PhpWord
    {
        $phpWord = IOFactory::load($docxPath);

        return $phpWord;
    }

    // ── Génération de base ────────────────────────────────────────────────────

    public function test_genere_un_docx_valide(): void
    {
        $outputPath = $this->tempDir . '/sortie.docx';

        $reconstructor = new DocumentReconstructor();
        $result = $reconstructor->reconstruct($this->sampleAnalysis(), $outputPath);

        $this->assertFileExists($outputPath);
        $this->assertSame($outputPath, $result);

        // Le fichier doit être un ZIP OOXML valide
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($outputPath));
        $this->assertNotFalse($zip->getFromName('word/document.xml'));
        $this->assertNotFalse($zip->getFromName('[Content_Types].xml'));
        $zip->close();
    }

    // ── Styles de titres natifs ───────────────────────────────────────────────

    public function test_les_titres_utilisent_les_styles_natifs_heading(): void
    {
        $outputPath = $this->tempDir . '/titres.docx';
        (new DocumentReconstructor())->reconstruct($this->sampleAnalysis(), $outputPath);

        $xml = $this->readPart($outputPath, 'word/document.xml');

        // Titres niveau 1 → pStyle Heading1
        $this->assertStringContainsString('w:val="Heading1"', $xml);
        // Sous-titres niveau 2 → Heading2
        $this->assertStringContainsString('w:val="Heading2"', $xml);
        // Sous-titres niveau 3 → Heading3
        $this->assertStringContainsString('w:val="Heading3"', $xml);

        // Les textes des titres sont présents dans le corps
        $this->assertStringContainsString('INTRODUCTION', $xml);
        $this->assertStringContainsString('CONCLUSION', $xml);
        $this->assertStringContainsString('CONTEXTE GENERAL', $xml);
    }

    // ── Sections : bascule romain → arabe ─────────────────────────────────────

    public function test_la_premiere_section_est_en_romain_et_le_corps_en_arabe(): void
    {
        $outputPath = $this->tempDir . '/sections.docx';
        (new DocumentReconstructor())->reconstruct($this->sampleAnalysis(), $outputPath);

        $xml = $this->readPart($outputPath, 'word/document.xml');

        // Section frontispice : pgNumType fmt=lowerRoman (i, ii, iii…)
        $this->assertStringContainsString('w:fmt="lowerRoman"', $xml);

        // Corps : pgNumType fmt=decimal (1, 2, 3…) avec redémarrage à 1
        $this->assertStringContainsString('w:fmt="decimal"', $xml);
        $this->assertStringContainsString('w:start="1"', $xml);

        // Il doit y avoir exactement 2 sections (frontispice + corps)
        $this->assertSame(2, substr_count($xml, '<w:sectPr'));
    }

    public function test_le_champ_page_du_pied_utilise_le_bon_format(): void
    {
        $outputPath = $this->tempDir . '/page.docx';
        (new DocumentReconstructor())->reconstruct($this->sampleAnalysis(), $outputPath);

        $xml = $this->readPart($outputPath, 'word/footer1.xml');

        // Le pied de page contient un champ PAGE au format romain
        $this->assertStringContainsString('PAGE', $xml);
        $this->assertStringContainsString('\\* roman', $xml);
    }

    // ── Sommaire (TOC) ────────────────────────────────────────────────────────

    public function test_le_sommaire_est_un_champ_toc_avec_mise_a_jour_automatique(): void
    {
        $outputPath = $this->tempDir . '/toc.docx';
        (new DocumentReconstructor())->reconstruct($this->sampleAnalysis(), $outputPath);

        $documentXml = $this->readPart($outputPath, 'word/document.xml');

        // Le champ TOC est présent dans le corps (instruction TOC \o "1-3")
        $this->assertStringContainsString('TOC', $documentXml);
        $this->assertStringContainsString('\\o', $documentXml);

        // settings.xml : updateFields activé → Word met à jour les champs
        $settingsXml = $this->readPart($outputPath, 'word/settings.xml');
        $this->assertStringContainsString('w:updateFields', $settingsXml);
        $this->assertStringContainsString('w:val="true"', $settingsXml);
    }

    // ── En-têtes et pieds de page ─────────────────────────────────────────────

    public function test_en_tete_et_pied_de_page_du_corps(): void
    {
        $outputPath = $this->tempDir . '/entete.docx';
        (new DocumentReconstructor())->reconstruct($this->sampleAnalysis(), $outputPath);

        // En-tête : le texte détecté doit être présent
        $headerXml = $this->readPart($outputPath, 'word/header1.xml');
        $this->assertStringContainsString('CONCEPTION ET REALISATION', $headerXml);

        // Pied de page : texte détecté + champ PAGE
        $footerXml = $this->readPart($outputPath, 'word/footer1.xml');
        $this->assertStringContainsString('Rédigé par : JEAN', $footerXml);
        $this->assertStringContainsString('PAGE', $footerXml);
    }

    // ── Liste des figures / tableaux ──────────────────────────────────────────

    public function test_liste_des_figures_et_tableaux_generee_depuis_les_legendes(): void
    {
        $outputPath = $this->tempDir . '/liste.docx';
        (new DocumentReconstructor())->reconstruct($this->sampleAnalysis(), $outputPath);

        $xml = $this->readPart($outputPath, 'word/document.xml');

        // La liste des figures est générée avec le titre natif
        $this->assertStringContainsString('Liste des figures', $xml);
        $this->assertStringContainsString('Architecture du système', $xml);
        $this->assertStringContainsString('Diagramme de classes', $xml);

        // La liste des tableaux est générée
        $this->assertStringContainsString('Liste des tableaux', $xml);
        $this->assertStringContainsString('Récapitulatif des besoins', $xml);
    }

    public function test_listes_absentes_si_pas_de_legendes(): void
    {
        $analysis = $this->sampleAnalysis();
        $analysis['legends'] = [];

        $outputPath = $this->tempDir . '/sans_legendes.docx';
        (new DocumentReconstructor())->reconstruct($analysis, $outputPath);

        $xml = $this->readPart($outputPath, 'word/document.xml');
        $this->assertStringNotContainsString('Liste des figures', $xml);
        $this->assertStringNotContainsString('Liste des tableaux', $xml);
    }

    // ── Robustesse ────────────────────────────────────────────────────────────

    public function test_genere_un_document_rechargeable_par_phpword(): void
    {
        $outputPath = $this->tempDir . '/rechargeable.docx';
        (new DocumentReconstructor())->reconstruct($this->sampleAnalysis(), $outputPath);

        // La relecture via PhpWord doit fonctionner (ZIP + XML valides)
        $phpWord = $this->reloadDocx($outputPath);
        $this->assertInstanceOf(PhpWord::class, $phpWord);
        $this->assertNotEmpty($phpWord->getSections());
    }

    // ── Couverture (Phase 3) ─────────────────────────────────────────────────

    public function test_prefixe_une_section_couverture_si_fournie(): void
    {
        $outputPath = $this->tempDir . '/avec_couverture.docx';

        $cover = [
            'detection' => [
                'lines' => [
                    [
                        'text' => 'UNIVERSITE EXEMPLE',
                        'styles' => ['font' => null, 'paragraph' => null],
                        'role' => null,
                    ],
                    [
                        'text' => 'Présenté par : ANCIEN NOM',
                        'styles' => ['font' => null, 'paragraph' => null],
                        'role' => 'nom',
                    ],
                ],
                'zones' => [
                    [
                        'type' => 'nom',
                        'label' => 'Présenté par :',
                        'value' => 'ANCIEN NOM',
                        'line_index' => 1,
                    ],
                ],
            ],
            'values' => ['nom' => 'NOUVEAU NOM'],
        ];

        (new DocumentReconstructor())->reconstruct($this->sampleAnalysis(), $outputPath, $cover);

        $xml = $this->readPart($outputPath, 'word/document.xml');

        // La couverture est préfixée : lignes non-zones conservées, valeur remplacée
        $this->assertStringContainsString('UNIVERSITE EXEMPLE', $xml);
        $this->assertStringContainsString('NOUVEAU NOM', $xml);
        $this->assertStringNotContainsString('ANCIEN NOM', $xml);

        // Trois sections : couverture + frontispice + corps
        $this->assertSame(3, substr_count($xml, '<w:sectPr'));

        // La numérotation romaine/arabe reste appliquée aux sections numérotées
        $this->assertStringContainsString('w:fmt="lowerRoman"', $xml);
        $this->assertStringContainsString('w:fmt="decimal"', $xml);
    }

    public function test_sans_couverture_le_document_reste_a_deux_sections(): void
    {
        $outputPath = $this->tempDir . '/sans_couverture.docx';

        (new DocumentReconstructor())->reconstruct($this->sampleAnalysis(), $outputPath);

        $xml = $this->readPart($outputPath, 'word/document.xml');

        $this->assertSame(2, substr_count($xml, '<w:sectPr'));
        $this->assertStringContainsString('w:fmt="lowerRoman"', $xml);
        $this->assertStringContainsString('w:fmt="decimal"', $xml);
    }
}
