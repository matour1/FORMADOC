<?php

declare(strict_types=1);

namespace Tests\Unit\DocumentGeneration;

use App\Services\DocumentGeneration\CoverDetectionService;
use App\Services\DocumentGeneration\CoverGenerationService;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use Tests\TestCase;

/**
 * Tests du CoverGenerationService : génération d'une couverture à partir de
 * l'exemple + des valeurs saisies, en conservant la structure et les styles
 * (police, taille, gras, alignement) des zones détectées.
 */
class CoverGenerationServiceTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/formadoc_cover_gen_' . uniqid();
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

    private function createCoverDocx(): string
    {
        $phpWord = new PhpWord();
        $section = $phpWord->addSection();

        $center = ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::CENTER];

        $section->addText('REPUBLIQUE DU CAMEROUN', ['size' => 14, 'bold' => true], $center);
        $section->addText('UNIVERSITE DE DOUALA', ['size' => 12], $center);
        $section->addText('Thème : CONCEPTION INITIALE', ['size' => 16, 'bold' => true], $center);
        $section->addText('Présenté par : ANCIEN NOM', ['size' => 11]);
        $section->addText('Encadré par : ANCIEN ENCADRANT', ['size' => 11]);
        $section->addText('Année académique 2024-2025', ['size' => 11]);

        $path = $this->tempDir . '/exemple.docx';
        IOFactory::createWriter($phpWord, 'Word2007')->save($path);

        return $path;
    }

    private function generate(array $values): string
    {
        $examplePath = $this->createCoverDocx();
        $detection = (new CoverDetectionService())->detect($examplePath);

        $outputPath = $this->tempDir . '/couverture_generee.docx';
        (new CoverGenerationService())->generate($detection, $values, $outputPath);

        return $outputPath;
    }

    public function test_genere_une_couverture_avec_les_valeurs_remplacees(): void
    {
        $outputPath = $this->generate([
            'titre' => 'NOUVELLE APPLICATION WEB',
            'nom' => 'MARIE CURIE',
            'encadrant' => 'Dr. EINSTEIN',
            'date' => '2025-2026',
        ]);

        $this->assertFileExists($outputPath);

        $xml = $this->readDocumentXml($outputPath);

        $this->assertStringContainsString('NOUVELLE APPLICATION WEB', $xml);
        $this->assertStringContainsString('MARIE CURIE', $xml);
        $this->assertStringContainsString('Dr. EINSTEIN', $xml);
        $this->assertStringContainsString('2025-2026', $xml);
    }

    public function test_conserve_les_lignes_non_zones(): void
    {
        $outputPath = $this->generate([
            'titre' => 'NOUVEAU TITRE',
            'nom' => 'X',
            'encadrant' => 'Y',
            'date' => '2025',
        ]);

        $xml = $this->readDocumentXml($outputPath);

        // Les lignes non remplacées (institution) sont conservées
        $this->assertStringContainsString('REPUBLIQUE DU CAMEROUN', $xml);
        $this->assertStringContainsString('UNIVERSITE DE DOUALA', $xml);
    }

    public function test_conserve_le_label_et_remplace_seulement_la_valeur(): void
    {
        $outputPath = $this->generate([
            'titre' => 'TITRE FINAL',
            'nom' => 'NOM FINAL',
            'encadrant' => 'ENCADRANT FINAL',
            'date' => '2030',
        ]);

        $xml = $this->readDocumentXml($outputPath);

        // Le label « Présenté par : » est conservé, seule la valeur change
        $this->assertStringContainsString('Présenté par :', $xml);
        $this->assertStringContainsString('NOM FINAL', $xml);
        $this->assertStringNotContainsString('ANCIEN NOM', $xml);
    }

    public function test_conserve_les_styles_de_police_des_zones(): void
    {
        $outputPath = $this->generate([
            'titre' => 'TITRE STYLE',
            'nom' => 'NOM',
            'encadrant' => 'ENCADRANT',
            'date' => '2025',
        ]);

        $xml = $this->readDocumentXml($outputPath);

        // Le titre reste en taille 16 (32 demi-points) et gras
        $this->assertStringContainsString('w:val="32"', $xml);
        $this->assertStringContainsString('<w:b ', $xml);
    }

    public function test_conserve_l_alignement_centre(): void
    {
        $outputPath = $this->generate([
            'titre' => 'TITRE CENTRE',
            'nom' => 'NOM',
            'encadrant' => 'ENCADRANT',
            'date' => '2025',
        ]);

        $xml = $this->readDocumentXml($outputPath);

        $this->assertStringContainsString('w:val="center"', $xml);
    }

    public function test_genere_un_docx_rechargeable_par_phpword(): void
    {
        $outputPath = $this->generate([
            'titre' => 'TITRE',
            'nom' => 'NOM',
            'encadrant' => 'ENCADRANT',
            'date' => '2025',
        ]);

        $phpWord = IOFactory::load($outputPath);
        $this->assertInstanceOf(PhpWord::class, $phpWord);
        $this->assertNotEmpty($phpWord->getSections());
    }

    private function readDocumentXml(string $docxPath): string
    {
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($docxPath), 'Le DOCX généré doit être un ZIP valide');
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        $this->assertIsString($xml);
        $this->assertNotEmpty($xml);

        return $xml;
    }
}
