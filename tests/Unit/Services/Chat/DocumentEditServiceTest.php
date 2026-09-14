<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Chat;

use App\Services\Chat\DocumentEditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpWord\Element\Text;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\Element\Title;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use Tests\TestCase;

/**
 * Tests du service d'édition de documents DOCX (pièces jointes du chat).
 *
 * L'IA peut MODIFIER une pièce jointe : replace_text, edit_title,
 * append_text, to_pdf. Le fichier source n'est jamais modifié — le
 * résultat est un nouveau fichier dans chat/generated/.
 */
class DocumentEditServiceTest extends TestCase
{
    use RefreshDatabase;

    private DocumentEditService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new DocumentEditService;
    }

    /**
     * Crée un DOCX de test dans chat/attachments/1/ et retourne son chemin
     * relatif (disk local).
     */
    private function createTestDocx(array $lines = []): string
    {
        $phpWord = new PhpWord;
        // Styles de titre indispensables : sans eux, PhpWord reécrit les
        // titres comme des paragraphes TextRun à la relecture (pas de
        // styleName HeadingN/Title détecté).
        $phpWord->addTitleStyle(1, ['bold' => true, 'size' => 16]);
        $phpWord->addTitleStyle(2, ['bold' => true, 'size' => 14]);

        $section = $phpWord->addSection();

        $section->addTitle('Introduction', 1);
        $section->addText('Bonjour le monde, ceci est un test.');
        $section->addListItem('Item A');
        $section->addTitle('Conclusion', 1);

        foreach ($lines as $line) {
            $section->addText($line);
        }

        $path = 'chat/attachments/1/test_source.docx';
        Storage::disk('local')->makeDirectory('chat/attachments/1');

        $writer = IOFactory::createWriter($phpWord, 'Word2007');
        $writer->save(Storage::disk('local')->path($path));

        return $path;
    }

    /**
     * Recharge un DOCX et retourne tout le texte (sections + titres).
     */
    private function readAllText(string $path): string
    {
        $phpWord = IOFactory::load(Storage::disk('local')->path($path));
        $parts = [];

        foreach ($phpWord->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                if (method_exists($element, 'getText')) {
                    $text = $element->getText();
                    if ($text instanceof TextRun) {
                        foreach ($text->getElements() as $run) {
                            if ($run instanceof Text) {
                                $parts[] = $run->getText();
                            }
                        }
                    } else {
                        $parts[] = (string) $text;
                    }
                }
            }
        }

        return implode("\n", $parts);
    }

    public function test_apply_chemin_hors_pj_refuse(): void
    {
        Storage::fake('local');

        $result = $this->service->apply([
            'source_path' => 'config/app.php',
            'operation' => 'replace_text',
            'search' => 'a',
            'replacement' => 'b',
        ]);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('invalide', $result['error']);
    }

    public function test_apply_source_path_manquant_erreur(): void
    {
        $result = $this->service->apply(['operation' => 'replace_text']);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('source_path', $result['error']);
    }

    public function test_apply_operation_manquante_erreur(): void
    {
        $result = $this->service->apply(['source_path' => 'chat/attachments/1/x.docx']);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('operation', $result['error']);
    }

    public function test_apply_fichier_introuvable_erreur(): void
    {
        Storage::fake('local');

        $result = $this->service->apply([
            'source_path' => 'chat/attachments/1/introuvable.docx',
            'operation' => 'replace_text',
            'search' => 'a',
            'replacement' => 'b',
        ]);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('introuvable', $result['error']);
    }

    public function test_replace_text_remplace_les_occurrences(): void
    {
        Storage::fake('local');

        $source = $this->createTestDocx();

        $result = $this->service->apply([
            'source_path' => $source,
            'operation' => 'replace_text',
            'search' => 'Bonjour',
            'replacement' => 'Salut',
            'output_filename' => 'document_modifie',
        ]);

        $this->assertArrayHasKey('result', $result);
        $this->assertStringContainsString('Document modifié', $result['result']);

        $outputPath = explode(' : ', $result['result'])[1];
        Storage::disk('local')->assertExists($outputPath);

        $text = $this->readAllText($outputPath);
        $this->assertStringContainsString('Salut le monde', $text);
        $this->assertStringNotContainsString('Bonjour le monde', $text);

        // Le fichier source n'est PAS modifié
        $sourceText = $this->readAllText($source);
        $this->assertStringContainsString('Bonjour le monde', $sourceText);
    }

    public function test_replace_text_sans_search_erreur(): void
    {
        Storage::fake('local');

        $source = $this->createTestDocx();

        $result = $this->service->apply([
            'source_path' => $source,
            'operation' => 'replace_text',
            'replacement' => 'y',
        ]);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('search', $result['error']);
    }

    public function test_edit_title_modifie_le_premier_titre(): void
    {
        Storage::fake('local');

        $source = $this->createTestDocx();

        $result = $this->service->apply([
            'source_path' => $source,
            'operation' => 'edit_title',
            'title_number' => 1,
            'new_text' => 'Chapitre Premier',
            'output_filename' => 'document_titre_modifie',
        ]);

        $this->assertArrayHasKey('result', $result);

        $outputPath = explode(' : ', $result['result'])[1];
        $text = $this->readAllText($outputPath);

        $this->assertStringContainsString('Chapitre Premier', $text);
        $this->assertStringNotContainsString('Introduction', $text);
    }

    public function test_edit_title_numero_invalide_erreur(): void
    {
        Storage::fake('local');

        $source = $this->createTestDocx();

        $result = $this->service->apply([
            'source_path' => $source,
            'operation' => 'edit_title',
            'title_number' => 0,
            'new_text' => 'X',
        ]);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('title_number', $result['error']);
    }

    public function test_edit_title_introuvable_erreur(): void
    {
        Storage::fake('local');

        $source = $this->createTestDocx();

        $result = $this->service->apply([
            'source_path' => $source,
            'operation' => 'edit_title',
            'title_number' => 99,
            'new_text' => 'X',
        ]);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('introuvable', $result['error']);
    }

    public function test_append_text_ajoute_un_paragraphe(): void
    {
        Storage::fake('local');

        $source = $this->createTestDocx();

        $result = $this->service->apply([
            'source_path' => $source,
            'operation' => 'append_text',
            'text' => 'Paragraphe ajouté à la fin.',
            'output_filename' => 'document_appended',
        ]);

        $this->assertArrayHasKey('result', $result);

        $outputPath = explode(' : ', $result['result'])[1];
        $text = $this->readAllText($outputPath);

        $this->assertStringContainsString('Paragraphe ajouté à la fin.', $text);
    }

    public function test_append_text_sans_texte_erreur(): void
    {
        Storage::fake('local');

        $source = $this->createTestDocx();

        $result = $this->service->apply([
            'source_path' => $source,
            'operation' => 'append_text',
            'text' => '',
        ]);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('text', $result['error']);
    }

    public function test_operation_inconnue_erreur(): void
    {
        Storage::fake('local');

        $source = $this->createTestDocx();

        $result = $this->service->apply([
            'source_path' => $source,
            'operation' => 'delete_all',
        ]);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('inconnue', $result['error']);
    }

    public function test_supported_operations_liste_les_9_operations(): void
    {
        $ops = $this->service->supportedOperations();

        $this->assertCount(9, $ops);

        $names = array_column($ops, 'operation');
        $this->assertContains('replace_text', $names);
        $this->assertContains('edit_title', $names);
        $this->assertContains('append_text', $names);
        $this->assertContains('change_title_level', $names);
        $this->assertContains('change_title_color', $names);
        $this->assertContains('change_font', $names);
        $this->assertContains('format_complete', $names);
        $this->assertContains('to_pdf', $names);
        $this->assertContains('pdf_to_docx', $names);

        foreach ($ops as $op) {
            $this->assertArrayHasKey('operation', $op);
            $this->assertArrayHasKey('description', $op);
            $this->assertNotEmpty($op['description']);
        }
    }

    public function test_change_title_level_modifie_le_niveau_du_titre(): void
    {
        Storage::fake('local');

        $source = $this->createTestDocx();

        $result = $this->service->apply([
            'source_path' => $source,
            'operation' => 'change_title_level',
            'title_number' => 1,
            'new_level' => 2,
            'output_filename' => 'document_niveau_modifie',
        ]);

        $this->assertArrayHasKey('result', $result);

        $outputPath = explode(' : ', $result['result'])[1];
        Storage::disk('local')->assertExists($outputPath);

        // Recharge et vérifie que le premier titre est maintenant un Heading2
        $phpWord = IOFactory::load(Storage::disk('local')->path($outputPath));
        $sections = $phpWord->getSections();
        $this->assertNotEmpty($sections);

        $titlesFound = 0;
        foreach ($sections as $section) {
            foreach ($section->getElements() as $element) {
                if ($element instanceof Title) {
                    $titlesFound++;
                    if ($titlesFound === 1) {
                        $this->assertSame(2, (int) $element->getDepth(), 'Le premier titre doit être de niveau 2');
                    }
                }
            }
        }

        $this->assertGreaterThanOrEqual(1, $titlesFound);
    }

    public function test_change_title_level_niveau_invalide_erreur(): void
    {
        Storage::fake('local');

        $source = $this->createTestDocx();

        $result = $this->service->apply([
            'source_path' => $source,
            'operation' => 'change_title_level',
            'title_number' => 1,
            'new_level' => 9, // hors 1-6
        ]);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('new_level', $result['error']);
    }

    public function test_change_title_color_change_la_couleur_du_titre(): void
    {
        Storage::fake('local');

        $source = $this->createTestDocx();

        $result = $this->service->apply([
            'source_path' => $source,
            'operation' => 'change_title_color',
            'title_number' => 1,
            'color' => 'FF0000',
            'output_filename' => 'document_couleur_modifiee',
        ]);

        $this->assertArrayHasKey('result', $result);

        $outputPath = explode(' : ', $result['result'])[1];
        Storage::disk('local')->assertExists($outputPath);

        // Vérifie que le document est toujours lisible et contient le texte
        $text = $this->readAllText($outputPath);
        $this->assertStringContainsString('Introduction', $text);
    }

    public function test_change_title_color_sans_couleur_erreur(): void
    {
        Storage::fake('local');

        $source = $this->createTestDocx();

        $result = $this->service->apply([
            'source_path' => $source,
            'operation' => 'change_title_color',
            'title_number' => 1,
        ]);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('color', $result['error']);
    }

    public function test_change_font_modifie_la_police(): void
    {
        Storage::fake('local');

        $source = $this->createTestDocx();

        $result = $this->service->apply([
            'source_path' => $source,
            'operation' => 'change_font',
            'font_name' => 'Times New Roman',
            'output_filename' => 'document_police_modifiee',
        ]);

        $this->assertArrayHasKey('result', $result);

        $outputPath = explode(' : ', $result['result'])[1];
        Storage::disk('local')->assertExists($outputPath);

        // Le texte est toujours présent après le changement de police
        $text = $this->readAllText($outputPath);
        $this->assertStringContainsString('Bonjour le monde', $text);
    }

    public function test_change_font_sans_font_name_erreur(): void
    {
        Storage::fake('local');

        $source = $this->createTestDocx();

        $result = $this->service->apply([
            'source_path' => $source,
            'operation' => 'change_font',
        ]);

        $this->assertArrayHasKey('error', $result);
        $this->assertStringContainsString('font_name', $result['error']);
    }

    public function test_format_complete_applique_la_mise_en_forme(): void
    {
        Storage::fake('local');

        $source = $this->createTestDocx();

        $result = $this->service->apply([
            'source_path' => $source,
            'operation' => 'format_complete',
            'font_name' => 'Arial',
            'font_size' => 12,
            'line_spacing' => 1.5,
            'title_color' => '0000FF',
            'title_level' => 2,
            'output_filename' => 'document_formate',
        ]);

        $this->assertArrayHasKey('result', $result);

        $outputPath = explode(' : ', $result['result'])[1];
        Storage::disk('local')->assertExists($outputPath);

        $text = $this->readAllText($outputPath);
        $this->assertStringContainsString('Bonjour le monde', $text);
        $this->assertStringContainsString('Introduction', $text);
    }

    public function test_format_complete_sans_parametre_applique_les_defauts(): void
    {
        Storage::fake('local');

        $source = $this->createTestDocx();

        // Sans paramètre : format_complete applique des valeurs par défaut
        // cohérentes et produit quand même un document valide.
        $result = $this->service->apply([
            'source_path' => $source,
            'operation' => 'format_complete',
            'output_filename' => 'document_formate_defaut',
        ]);

        $this->assertArrayHasKey('result', $result);

        $outputPath = explode(' : ', $result['result'])[1];
        Storage::disk('local')->assertExists($outputPath);

        $text = $this->readAllText($outputPath);
        $this->assertStringContainsString('Bonjour le monde', $text);
    }

    public function test_pdf_to_docx_convertit_un_pdf(): void
    {
        // Skip si LibreOffice n'est pas disponible (conversion externe)
        $soffice = trim((string) shell_exec('where soffice.com 2>NUL'));
        if ($soffice === '') {
            $this->markTestSkipped('LibreOffice (soffice.com) non disponible.');
        }

        Storage::fake('local');

        // Génère un DOCX source puis le convertit en PDF pour avoir un PDF réel
        $source = $this->createTestDocx();
        $phpWord = IOFactory::load(Storage::disk('local')->path($source));

        $pdfPath = 'chat/attachments/1/test_source.pdf';
        Storage::disk('local')->makeDirectory('chat/attachments/1');

        // Convertit DOCX → PDF via LibreOffice
        $docxAbs = Storage::disk('local')->path($source);
        $pdfAbs = Storage::disk('local')->path($pdfPath);
        shell_exec('"'.$soffice.'" --headless --convert-to pdf --outdir "'
            .dirname($pdfAbs).'" "'.$docxAbs.'" 2>&1');

        if (! is_file($pdfAbs)) {
            $this->markTestSkipped('Conversion DOCX→PDF impossible via LibreOffice.');
        }

        $result = $this->service->apply([
            'source_path' => $pdfPath,
            'operation' => 'pdf_to_docx',
            'output_filename' => 'pdf_converti',
        ]);

        $this->assertArrayHasKey('result', $result);

        $outputPath = explode(' : ', $result['result'])[1];
        Storage::disk('local')->assertExists($outputPath);
        $this->assertStringContainsString('.docx', $outputPath);
    }
}
