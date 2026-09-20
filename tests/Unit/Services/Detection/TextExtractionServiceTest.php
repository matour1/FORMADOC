<?php

namespace Tests\Unit\Services\Detection;

use App\Services\Detection\TextExtractionService;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use Tests\TestCase;

/**
 * Tests du TextExtractionService (extraction DOCX/TXT).
 */
class TextExtractionServiceTest extends TestCase
{
    private TextExtractionService $service;

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TextExtractionService;
        $this->tempDir = sys_get_temp_dir().'/formadoc_tests_'.uniqid();
        mkdir($this->tempDir, 0777, true);
    }

    protected function tearDown(): void
    {
        // Nettoyage des fichiers temporaires
        foreach (glob($this->tempDir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tempDir);
        parent::tearDown();
    }

    private function createDocx(string $content, string $filename = 'test.docx'): string
    {
        $phpWord = new PhpWord;
        $section = $phpWord->addSection();
        $section->addText($content);

        $path = $this->tempDir.'/'.$filename;
        IOFactory::createWriter($phpWord, 'Word2007')->save($path);

        return $path;
    }

    public function test_extrait_le_texte_d_un_docx(): void
    {
        $path = $this->createDocx("Introduction\nFigure 1: Architecture");

        $text = $this->service->execute($path);

        $this->assertStringContainsString('Introduction', $text);
        $this->assertStringContainsString('Figure 1: Architecture', $text);
    }

    public function test_extrait_le_texte_d_un_txt(): void
    {
        $path = $this->tempDir.'/test.txt';
        file_put_contents($path, "Chapitre 1\nCeci est un test.");

        $text = $this->service->execute($path);

        $this->assertStringContainsString('Chapitre 1', $text);
        $this->assertStringContainsString('Ceci est un test.', $text);
    }

    public function test_normalise_les_fins_de_ligne(): void
    {
        $path = $this->tempDir.'/test.txt';
        file_put_contents($path, "Ligne 1\r\nLigne 2\rLigne 3");

        $text = $this->service->execute($path);

        // \r\n et \r doivent devenir \n
        $this->assertStringNotContainsString("\r", $text);
        $this->assertSame("Ligne 1\nLigne 2\nLigne 3", $text);
    }

    public function test_convertit_iso_8859_1_en_utf8(): void
    {
        $path = $this->tempDir.'/test.txt';
        // "Étude" en ISO-8859-1
        $content = mb_convert_encoding('Étude de cas', 'ISO-8859-1', 'UTF-8');
        file_put_contents($path, $content);

        $text = $this->service->execute($path);

        $this->assertStringContainsString('Étude de cas', $text);
        $this->assertTrue(mb_check_encoding($text, 'UTF-8'));
    }

    public function test_fichier_introuvable_leve_une_exception(): void
    {
        $this->expectException(\Exception::class);
        $this->service->execute('/chemin/inexistant/test.docx');
    }

    public function test_extension_non_supportee_leve_une_exception(): void
    {
        $path = $this->tempDir.'/test.pdf';
        file_put_contents($path, 'fake pdf content');

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('extension non supportée');
        $this->service->execute($path);
    }

    public function test_docx_sans_texte_leve_une_exception(): void
    {
        $phpWord = new PhpWord;
        $phpWord->addSection();
        $path = $this->tempDir.'/vide.docx';
        IOFactory::createWriter($phpWord, 'Word2007')->save($path);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('aucun texte extractible');
        $this->service->execute($path);
    }
}
