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
        $this->tempDir = sys_get_temp_dir().'/formadoc_parser_'.uniqid();
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tempDir.'/*') ?: [] as $file) {
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
        $phpWord = new PhpWord;

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

        // Liste à puces (2 niveaux)
        $section->addListItem('Premier item', 0);
        $section->addListItem('Sous-item imbriqué', 1);

        $path = $this->tempDir.'/'.$filename;
        IOFactory::createWriter($phpWord, 'Word2007')->save($path);

        return $path;
    }

    public function test_constructeur_rejette_un_fichier_inexistant(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new DocumentParser($this->tempDir.'/inexistant.docx');
    }

    public function test_parse_rejette_un_fichier_non_word(): void
    {
        $path = $this->tempDir.'/faux.docx';
        file_put_contents($path, 'Ceci n\'est pas un DOCX valide.');

        $this->expectException(RuntimeException::class);

        (new DocumentParser($path))->parse();
    }

    public function test_parse_supporte_le_texte_brut_txt(): void
    {
        // Un .txt n'est pas une archive ZIP : le parser doit le traiter
        // comme un PhpWord virtuel (1 section, 1 paragraphe par ligne).
        $path = $this->tempDir.'/rapport.txt';
        file_put_contents($path, "RAPPORT DE STAGE\n\n1. Introduction\nCeci est un paragraphe.\n1.1 Contexte\nFin du rapport.\n");

        $result = (new DocumentParser($path))->parse();

        $this->assertStringContainsString('1. Introduction', $result['raw_text']);
        $this->assertStringContainsString('Ceci est un paragraphe.', $result['raw_text']);

        $body = $result['sections'][0]['body'];
        $textes = array_column($body, 'text');
        $this->assertContains('1. Introduction', $textes);
        $this->assertContains('1.1 Contexte', $textes);

        // Positions préservées : les lignes sont dans le body de la section 0
        foreach ($body as $el) {
            $this->assertSame('body', $el['position']['parent']);
            $this->assertSame(0, $el['position']['section_index']);
        }
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

        // Contenu réel des cellules : indispensable à la reconstruction fidèle
        $this->assertIsArray($tableaux[0]['rows'] ?? null, 'Les lignes du tableau doivent être extraites');
        $this->assertCount(1, $tableaux[0]['rows']);
        $this->assertSame(['Cellule A', 'Cellule B'], $tableaux[0]['rows'][0]['cells']);
    }

    public function test_les_listes_sont_detectees_avec_leur_profondeur(): void
    {
        $path = $this->createControlledDocx();
        $result = (new DocumentParser($path))->parse();

        $listes = array_values(array_filter(
            $result['sections'][0]['body'],
            fn (array $el) => $el['type'] === 'liste'
        ));

        $this->assertCount(2, $listes);
        $this->assertSame('Premier item', $listes[0]['text']);
        $this->assertSame(0, $listes[0]['depth']);
        $this->assertSame('Sous-item imbriqué', $listes[1]['text']);
        $this->assertSame(1, $listes[1]['depth']);
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

        if (! file_exists($fixture)) {
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
        $phpWord = new PhpWord;
        $section = $phpWord->addSection();
        $header = $section->addHeader();
        $header->addText('480695'); // Artefact que PhpWord lit (coordonnée)
        $section->addText('Contenu du corps.');

        $path = $this->tempDir.'/'.$filename;
        IOFactory::createWriter($phpWord, 'Word2007')->save($path);

        // Injecter une textbox dans header1.xml avec le texte du "thème"
        $zip = new \ZipArchive;
        $zip->open($path);
        $headerXml = $zip->getFromName('word/header1.xml');

        $textboxXml = '<w:p xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            .'<w:pPr><w:jc w:val="center"/></w:pPr>'
            .'<w:r><w:t>THEME: ETAPE DE CONCEPTION DU PROJET MEDORIA</w:t></w:r>'
            .'</w:p>';

        // Remplacer le dernier </w:hdr> par textbox + </w:hdr>
        $textboxWrapper = '<mc:AlternateContent xmlns:mc="http://schemas.openxmlformats.org/markup-compatibility/2006">'
            .'<mc:Choice Requires="wps"><w:drawing><wp:anchor xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing">'
            .'<wps:txbx xmlns:wps="http://schemas.microsoft.com/office/word/2010/wordprocessingShape">'
            .'<w:txbxContent>'.$textboxXml.'</w:txbxContent></wps:txbx>'
            .'</wp:anchor></w:drawing></mc:Choice>'
            .'<mc:Fallback><w:pict><v:rect xmlns:v="urn:schemas-microsoft-com:vml">'
            .'<v:textbox><w:txbxContent>'.$textboxXml.'</w:txbxContent></v:textbox>'
            .'</v:rect></w:pict></mc:Fallback></mc:AlternateContent>';

        $headerXml = str_replace('</w:hdr>', $textboxWrapper.'</w:hdr>', $headerXml);

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

    // ── Phase 3 : images zip://, champs SEQ et codes de champs ────────────────

    /**
     * Génère un DOCX contenant une image + des légendes avec champs SEQ.
     */
    private function createDocxWithImageAndSeq(string $filename = 'image_seq.docx'): string
    {
        $phpWord = new PhpWord;
        $section = $phpWord->addSection();

        // Petite image PNG 1×1 valide
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        );
        $imgPath = $this->tempDir.'/pixel.png';
        file_put_contents($imgPath, $png);

        $section->addImage($imgPath);
        $section->addText('Figure { SEQ Figure \* ARABIC } : Architecture générale');

        $path = $this->tempDir.'/'.$filename;
        IOFactory::createWriter($phpWord, 'Word2007')->save($path);

        return $path;
    }

    public function test_read_image_data_avec_source_zip(): void
    {
        $path = $this->createDocxWithImageAndSeq();
        $parser = new DocumentParser($path);

        // Source au format du Reader PhpWord : zip:///chemin/doc.docx#word/media/section_image1.png
        // (le writer PhpWord nomme les images "section_imageN.ext")
        $source = 'zip://'.str_replace('\\', '/', $path).'#word/media/section_image1.png';

        $data = $parser->readImageData($source);

        $this->assertNotNull($data, 'Le binaire de l\'image doit être extrait depuis le ZIP');
        $this->assertNotEmpty($data);

        // C'est bien un PNG (signature)
        $this->assertStringStartsWith("\x89PNG", $data);
    }

    public function test_read_image_data_avec_chemin_fichier(): void
    {
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        );
        $imgPath = $this->tempDir.'/pixel.png';
        file_put_contents($imgPath, $png);

        $parser = new DocumentParser($this->createDocxWithImageAndSeq());

        $data = $parser->readImageData($imgPath);

        $this->assertNotNull($data);
        $this->assertStringStartsWith("\x89PNG", $data);
    }

    public function test_read_image_data_source_invalide_retourne_null(): void
    {
        $parser = new DocumentParser($this->createDocxWithImageAndSeq());

        $this->assertNull($parser->readImageData(''));
        $this->assertNull($parser->readImageData('zip:///inexistant.docx#word/media/x.png'));
        $this->assertNull($parser->readImageData($this->tempDir.'/inexistant.png'));
    }

    public function test_resolve_seq_fields_remplace_par_les_numeros(): void
    {
        $parser = new DocumentParser($this->createDocxWithImageAndSeq());

        $text = "Figure { SEQ Figure \\* ARABIC } : Architecture\n"
            ."Tableau { SEQ Tableau \\* ARABIC } : Résultats\n"
            .'Figure { SEQ Figure \\* ARABIC } : Diagramme';

        $resolved = $parser->resolveSeqFields($text);

        $this->assertStringContainsString('Figure 1 : Architecture', $resolved);
        $this->assertStringContainsString('Tableau 1 : Résultats', $resolved);
        $this->assertStringContainsString('Figure 2 : Diagramme', $resolved);
        $this->assertStringNotContainsString('SEQ', $resolved);
    }

    public function test_les_compteurs_seq_sont_globaux_au_document(): void
    {
        $parser = new DocumentParser($this->createDocxWithImageAndSeq());

        // Plusieurs éléments successifs : la numérotation doit continuer
        // (1, 2, 3…) comme Word, et non repartir de 1 à chaque élément.
        $a = $parser->resolveSeqFields('Figure { SEQ Figure \* ARABIC } : A');
        $b = $parser->resolveSeqFields('Figure { SEQ Figure \* ARABIC } : B');
        $c = $parser->resolveSeqFields('Tableau { SEQ Tableau \* ARABIC } : C');
        $d = $parser->resolveSeqFields('Figure { SEQ Figure \* ARABIC } : D');

        $this->assertStringContainsString('Figure 1 : A', $a);
        $this->assertStringContainsString('Figure 2 : B', $b);
        $this->assertStringContainsString('Tableau 1 : C', $c);
        $this->assertStringContainsString('Figure 3 : D', $d);
    }

    public function test_strip_field_codes_retire_toc_page_ref(): void
    {
        $parser = new DocumentParser($this->createDocxWithImageAndSeq());

        $text = '{ TOC \\o "1-3" \\h \\z \\u } Contenu { PAGE } et { REF _Toc123 \\h }';

        $stripped = $parser->stripFieldCodes($text);

        $this->assertStringContainsString('Contenu', $stripped);
        $this->assertStringNotContainsString('TOC', $stripped);
        $this->assertStringNotContainsString('PAGE', $stripped);
        $this->assertStringNotContainsString('REF', $stripped);
    }

    public function test_les_champs_seq_sont_resolus_dans_le_parse(): void
    {
        $path = $this->createDocxWithImageAndSeq();
        $result = (new DocumentParser($path))->parse();

        // Le texte extrait ne contient plus le code de champ SEQ littéral
        $this->assertStringNotContainsString('SEQ', $result['raw_text']);
        $this->assertStringContainsString('Figure 1 : Architecture générale', $result['raw_text']);
    }

    public function test_les_images_docx_encapsulees_dans_textrun_sont_detectees(): void
    {
        // Le Reader PhpWord 1.4 encapsule les images d'un DOCX dans un
        // TextRun dont le texte est "[image:section_image1.png]" — jamais
        // en élément Image de premier niveau. Le parse doit re-typifier cet
        // élément en 'image' et extraire son binaire depuis le ZIP source.
        $path = $this->createDocxWithImageAndSeq();
        $result = (new DocumentParser($path))->parse();

        $body = $result['sections'][0]['body'];
        $images = array_values(array_filter(
            $body,
            static fn (array $el): bool => ($el['type'] ?? '') === 'image'
        ));

        $this->assertNotEmpty($images, 'L\'image du DOCX doit être détectée (type image)');

        $image = $images[0];
        $this->assertSame('section_image1.png', $image['image_name'] ?? null);
        $this->assertSame('png', $image['image_extension'] ?? null);
        $this->assertNotEmpty($image['image_data'] ?? null, 'Le binaire de l\'image doit être extrait');

        // Le binaire est bien un PNG
        $this->assertStringStartsWith("\x89PNG", base64_decode((string) $image['image_data']));
    }
}
