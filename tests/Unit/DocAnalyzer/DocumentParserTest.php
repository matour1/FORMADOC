<?php

declare(strict_types=1);

namespace Tests\Unit\DocAnalyzer;

use App\DocAnalyzer\DocumentParser;
use InvalidArgumentException;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use RuntimeException;
use Tests\TestCase;

/**
 * Tests du DocumentParser : validation de l'extraction structurelle DOCX.
 *
 * On génère un document contrôlé via PhpWord (titres de styles connus,
 * en-tête, pied de page, paragraphe normal) et on vérifie que le parser
 * restitue fidèlement textes, styles et positions.
 */
class DocumentParserTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/formadoc_parser_' . uniqid();
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
     * Génère un DOCX contrôlé : titres niveaux 1-3, paragraphe normal,
     * en-tête, pied de page et un tableau.
     */
    private function createControlledDocx(string $filename = 'controle.docx'): string
    {
        $phpWord = new PhpWord();

        // Styles de titres connus
        $phpWord->addTitleStyle(1, ['bold' => true, 'size' => 16]);
        $phpWord->addTitleStyle(2, ['bold' => true, 'size' => 14]);
        $phpWord->addTitleStyle(3, ['bold' => true, 'size' => 12]);

        $section = $phpWord->addSection();

        // En-tête et pied de page
        $header = $section->addHeader();
        $header->addText('EN-TÊTE FORMADOC');

        $footer = $section->addFooter();
        $footer->addText('Page 1');

        // Contenu
        $section->addTitle('Résumé', 1);
        $section->addText('Ceci est un paragraphe normal.', ['size' => 11]);

        $section->addTitle('1. Contexte général', 1);
        $section->addTitle('1.1 Sous-section', 2);
        $section->addTitle('1.1.1 Détail', 3);

        // Tableau simple
        $table = $section->addTable();
        $table->addRow();
        $table->addCell()->addText('Cellule A');
        $table->addCell()->addText('Cellule B');

        $path = $this->tempDir . '/' . $filename;
        IOFactory::createWriter($phpWord, 'Word2007')->save($path);

        return $path;
    }

    public function test_constructeur_rejette_un_fichier_inexistant(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new DocumentParser($this->tempDir . '/inexistant.docx');
    }

    public function test_parse_rejette_un_fichier_non_word(): void
    {
        $path = $this->tempDir . '/faux.docx';
        file_put_contents($path, 'Ceci n\'est pas un DOCX valide.');

        $this->expectException(RuntimeException::class);

        (new DocumentParser($path))->parse();
    }

    public function test_raw_text_contient_tous_les_contenus(): void
    {
        $path = $this->createControlledDocx();
        $result = (new DocumentParser($path))->parse();

        $this->assertNotSame('', $result['raw_text']);
        $this->assertStringContainsString('Résumé', $result['raw_text']);
        $this->assertStringContainsString('Ceci est un paragraphe normal.', $result['raw_text']);
        $this->assertStringContainsString('1. Contexte général', $result['raw_text']);
        $this->assertStringContainsString('Cellule A', $result['raw_text']);
    }

    public function test_les_titres_sont_detectes_avec_leur_profondeur(): void
    {
        $path = $this->createControlledDocx();
        $result = (new DocumentParser($path))->parse();

        $body = $result['sections'][0]['body'];
        $titres = array_values(array_filter(
            $body,
            fn (array $el) => $el['type'] === 'titre'
        ));

        $this->assertNotEmpty($titres);
        $this->assertSame('Résumé', $titres[0]['text']);
        $this->assertSame(1, $titres[0]['depth']);
        $this->assertSame('1. Contexte général', $titres[1]['text']);
        $this->assertSame(1, $titres[1]['depth']);
        $this->assertSame('1.1 Sous-section', $titres[2]['text']);
        $this->assertSame(2, $titres[2]['depth']);
        $this->assertSame('1.1.1 Détail', $titres[3]['text']);
        $this->assertSame(3, $titres[3]['depth']);
    }

    public function test_en_tete_et_pied_de_page_sont_extraits_avec_parent(): void
    {
        $path = $this->createControlledDocx();
        $result = (new DocumentParser($path))->parse();

        $section = $result['sections'][0];

        $this->assertNotEmpty($section['headers']);
        $this->assertSame('EN-TÊTE FORMADOC', $section['headers'][0]['text']);
        $this->assertSame('header', $section['headers'][0]['position']['parent']);

        $this->assertNotEmpty($section['footers']);
        $this->assertSame('Page 1', $section['footers'][0]['text']);
        $this->assertSame('footer', $section['footers'][0]['position']['parent']);
    }

    public function test_element_index_est_strictement_croissant(): void
    {
        $path = $this->createControlledDocx();
        $result = (new DocumentParser($path))->parse();

        $indexes = [];
        foreach ($result['sections'] as $section) {
            foreach (['body', 'headers', 'footers'] as $zone) {
                foreach ($section[$zone] as $el) {
                    $indexes[] = $el['position']['element_index'];
                }
            }
        }

        $this->assertNotEmpty($indexes);
        foreach ($indexes as $i => $index) {
            if ($i > 0) {
                $this->assertGreaterThan($indexes[$i - 1], $index);
            }
        }
    }

    public function test_les_styles_font_sont_extraits(): void
    {
        $path = $this->createControlledDocx();
        $result = (new DocumentParser($path))->parse();

        $body = $result['sections'][0]['body'];
        $titre = null;
        $paragraphe = null;

        foreach ($body as $el) {
            if ($el['type'] === 'titre' && $el['text'] === 'Résumé') {
                $titre = $el;
            }
            if ($el['type'] === 'texte' && $el['text'] === 'Ceci est un paragraphe normal.') {
                $paragraphe = $el;
            }
        }

        $this->assertNotNull($titre, 'Le titre "Résumé" doit être présent');
        $this->assertNotNull($paragraphe, 'Le paragraphe normal doit être présent');

        $this->assertSame(16, $titre['styles']['font']['basic']['size']);
        $this->assertTrue($titre['styles']['font']['style']['bold']);

        $this->assertSame(11, $paragraphe['styles']['font']['basic']['size']);
    }

    public function test_les_tableaux_sont_detectes(): void
    {
        $path = $this->createControlledDocx();
        $result = (new DocumentParser($path))->parse();

        $tableaux = array_values(array_filter(
            $result['sections'][0]['body'],
            fn (array $el) => $el['type'] === 'tableau'
        ));

        $this->assertCount(1, $tableaux);
        $this->assertStringContainsString('Cellule A', $tableaux[0]['text']);
        $this->assertSame(1, $tableaux[0]['rows_count']);
    }

    public function test_context_text_contient_des_balises_de_style(): void
    {
        $path = $this->createControlledDocx();
        $result = (new DocumentParser($path))->parse();

        $this->assertStringContainsString('<titre>Résumé</titre>', $result['context_text']);
        $this->assertStringContainsString(
            '<texte>Ceci est un paragraphe normal.</texte>',
            $result['context_text']
        );
    }

    public function test_context_text_with_positions_contient_les_marqueurs(): void
    {
        $path = $this->createControlledDocx();
        $result = (new DocumentParser($path))->parse();

        $this->assertStringContainsString('[POS:section_0,element_', $result['context_text_with_positions']);
        $this->assertStringContainsString(',parent_body]', $result['context_text_with_positions']);
        $this->assertStringContainsString(',parent_header]', $result['context_text_with_positions']);
        $this->assertStringContainsString(',parent_footer]', $result['context_text_with_positions']);
    }

    public function test_parse_fonctionne_sur_le_fixture_existant(): void
    {
        $fixture = storage_path('test_scripts/reports/rapport_test_structure.docx');

        if (!file_exists($fixture)) {
            $this->markTestSkipped('Fixture rapport_test_structure.docx absent — générer via storage/test_scripts/generate_test_report.php');
        }

        $result = (new DocumentParser($fixture))->parse();

        $this->assertStringContainsString('Résumé', $result['raw_text']);
        $this->assertStringContainsString('Figure 1: Architecture générale de la plateforme', $result['raw_text']);
        $this->assertNotEmpty($result['sections'][0]['body']);
    }

    /**
     * Injecte une textbox (wps:txbx) contenant un texte dans l'en-tête
     * du DOCX généré — comme le font Word pour les en-têtes "graphiques".
     * PhpWord ignore ces textboxes : c'est le cas que le parser doit couvrir
     * en relisant le XML brut.
     */
    private function createDocxWithGraphicalHeader(string $filename = 'graphique.docx'): string
    {
        $phpWord = new PhpWord();
        $section = $phpWord->addSection();
        $header = $section->addHeader();
        $header->addText('480695'); // Artefact que PhpWord lit (coordonnée)
        $section->addText('Contenu du corps.');

        $path = $this->tempDir . '/' . $filename;
        IOFactory::createWriter($phpWord, 'Word2007')->save($path);

        // Injecter une textbox dans header1.xml avec le texte du "thème"
        $zip = new \ZipArchive();
        $zip->open($path);
        $headerXml = $zip->getFromName('word/header1.xml');

        $textboxXml = '<w:p xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            . '<w:pPr><w:jc w:val="center"/></w:pPr>'
            . '<w:r><w:t>THEME: ETAPE DE CONCEPTION DU PROJET MEDORIA</w:t></w:r>'
            . '</w:p>';

        // Remplacer le dernier </w:hdr> par textbox + </w:hdr>
        $textboxWrapper = '<mc:AlternateContent xmlns:mc="http://schemas.openxmlformats.org/markup-compatibility/2006">'
            . '<mc:Choice Requires="wps"><w:drawing><wp:anchor xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing">'
            . '<wps:txbx xmlns:wps="http://schemas.microsoft.com/office/word/2010/wordprocessingShape">'
            . '<w:txbxContent>' . $textboxXml . '</w:txbxContent></wps:txbx>'
            . '</wp:anchor></w:drawing></mc:Choice>'
            . '<mc:Fallback><w:pict><v:rect xmlns:v="urn:schemas-microsoft-com:vml">'
            . '<v:textbox><w:txbxContent>' . $textboxXml . '</w:txbxContent></v:textbox>'
            . '</v:rect></w:pict></mc:Fallback></mc:AlternateContent>';

        $headerXml = str_replace('</w:hdr>', $textboxWrapper . '</w:hdr>', $headerXml);

        $zip->addFromString('word/header1.xml', $headerXml);
        $zip->close();

        return $path;
    }

    public function test_les_en_tetes_graphiques_textboxes_sont_extraits_via_xml(): void
    {
        $path = $this->createDocxWithGraphicalHeader();
        $result = (new DocumentParser($path))->parse();

        $headers = $result['sections'][0]['headers'];

        $this->assertNotEmpty($headers, 'L\'en-tête doit contenir un élément');

        // Le texte du thème (dans la textbox) doit être détecté, pas l'artefact
        $allText = implode(' ', array_column($headers, 'text'));
        $this->assertStringContainsString('ETAPE DE CONCEPTION DU PROJET MEDORIA', $allText);

        // L'artefact numérique '480695' ne doit plus apparaître seul
        $this->assertStringNotContainsString('480695', $allText);
    }
}
