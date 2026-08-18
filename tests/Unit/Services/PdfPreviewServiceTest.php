<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\DocumentGeneration\PdfPreviewService;
use Tests\TestCase;

/**
 * Tests du PdfPreviewService : conversion DOCX → PDF via LibreOffice.
 *
 * La conversion réelle nécessite LibreOffice installé. Si soffice est
 * introuvable, les tests de conversion sont ignorés (markTestSkipped) ;
 * le test de détection du chemin reste vérifié sur les candidats connus.
 */
class PdfPreviewServiceTest extends TestCase
{
    public function test_soffice_path_detecte_un_chemin_installe(): void
    {
        $service = new PdfPreviewService();

        $path = $service->sofficePath();

        if ($path === null) {
            $this->markTestSkipped('LibreOffice non installé sur ce système.');
        }

        $this->assertFileExists($path, 'Le chemin de soffice doit exister');
    }

    public function test_convertit_un_docx_en_pdf(): void
    {
        $service = new PdfPreviewService();
        if ($service->sofficePath() === null) {
            $this->markTestSkipped('LibreOffice non installé — conversion ignorée.');
        }

        // Petit DOCX minimal généré via PhpWord
        $tempDir = sys_get_temp_dir() . '/formadoc_pdf_' . uniqid();
        mkdir($tempDir, 0777, true);

        try {
            $phpWord = new \PhpOffice\PhpWord\PhpWord();
            $section = $phpWord->addSection();
            $section->addText('Contenu de test pour l\'aperçu PDF.');
            $section->addTitle('Titre de test', 1);

            $docxPath = $tempDir . '/test.docx';
            \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'Word2007')->save($docxPath);

            $pdfPath = $service->convertToPdf($docxPath);

            $this->assertFileExists($pdfPath, 'Le PDF doit être généré');
            $this->assertStringEndsWith('.pdf', $pdfPath);

            // Le PDF est un vrai fichier PDF (%PDF en tête)
            $head = file_get_contents($pdfPath, false, null, 0, 5);
            $this->assertSame('%PDF-', $head, 'Le fichier doit commencer par %PDF-');
        } finally {
            foreach (glob($tempDir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($tempDir);
        }
    }
}
