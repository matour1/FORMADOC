<?php

declare(strict_types=1);

namespace Tests\Unit\DocumentGeneration;

use App\Services\DocumentGeneration\CoverDetectionService;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use Tests\TestCase;

/**
 * Tests du CoverDetectionService : détection déterministe (regex/mots-clés)
 * des zones d'une couverture d'exemple (titre, nom, encadrant, date).
 *
 * Aucun LLM n'intervient ici : la détection repose sur des mots-clés
 * normalisés puis des heuristiques de repli (taille de police pour le titre,
 * expression d'année pour la date).
 */
class CoverDetectionServiceTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/formadoc_cover_detect_' . uniqid();
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
     * Construit une couverture d'exemple typique et l'enregistre en DOCX.
     */
    private function createCoverDocx(array $lines, string $filename = 'couverture.docx'): string
    {
        $phpWord = new PhpWord();
        $section = $phpWord->addSection();

        foreach ($lines as $line) {
            $section->addText(
                $line['text'],
                $line['font'] ?? null,
                $line['paragraph'] ?? null
            );
        }

        $path = $this->tempDir . '/' . $filename;
        IOFactory::createWriter($phpWord, 'Word2007')->save($path);

        return $path;
    }

    /**
     * Couverture standard : les 4 zones sont annoncées par des mots-clés.
     */
    private function coverStandard(): string
    {
        return $this->createCoverDocx([
            ['text' => 'REPUBLIQUE DU CAMEROUN', 'font' => ['size' => 14, 'bold' => true], 'paragraph' => ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::CENTER]],
            ['text' => 'UNIVERSITE DE DOUALA', 'font' => ['size' => 12], 'paragraph' => ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::CENTER]],
            ['text' => 'Thème : CONCEPTION D\'UNE APPLICATION WEB', 'font' => ['size' => 16, 'bold' => true], 'paragraph' => ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::CENTER]],
            ['text' => 'Présenté par : JEAN DUPONT', 'font' => ['size' => 11]],
            ['text' => 'Encadré par : Dr. MARTIN', 'font' => ['size' => 11]],
            ['text' => 'Année académique 2024-2025', 'font' => ['size' => 11]],
        ]);
    }

    public function test_detecte_les_quatre_zones_attendues(): void
    {
        $result = (new CoverDetectionService())->detect($this->coverStandard());

        $zones = $result['zones'];
        $byType = [];
        foreach ($zones as $zone) {
            $byType[$zone['type']] = $zone;
        }

        $this->assertArrayHasKey('titre', $byType);
        $this->assertArrayHasKey('nom', $byType);
        $this->assertArrayHasKey('encadrant', $byType);
        $this->assertArrayHasKey('date', $byType);

        $this->assertSame('Thème :', $byType['titre']['label']);
        $this->assertSame('CONCEPTION D\'UNE APPLICATION WEB', $byType['titre']['value']);

        $this->assertSame('Présenté par :', $byType['nom']['label']);
        $this->assertSame('JEAN DUPONT', $byType['nom']['value']);

        $this->assertSame('Encadré par :', $byType['encadrant']['label']);
        $this->assertSame('Dr. MARTIN', $byType['encadrant']['value']);

        $this->assertSame('Année académique', $byType['date']['label']);
        $this->assertSame('2024-2025', $byType['date']['value']);
    }

    public function test_les_lignes_non_zones_sont_conservees_avec_leur_texte(): void
    {
        $result = (new CoverDetectionService())->detect($this->coverStandard());

        $texts = array_map(static fn (array $line) => $line['text'], $result['lines']);

        $this->assertContains('REPUBLIQUE DU CAMEROUN', $texts);
        $this->assertContains('UNIVERSITE DE DOUALA', $texts);
        $this->assertCount(6, $result['lines']);
    }

    public function test_detecte_le_titre_par_taille_si_aucun_mot_cle(): void
    {
        // Aucun « Thème/Sujet/Titre » : le titre est la ligne à la plus
        // grande police (taille 20 ici).
        $path = $this->createCoverDocx([
            ['text' => 'REPUBLIQUE DU CAMEROUN', 'font' => ['size' => 14, 'bold' => true]],
            ['text' => 'CONCEPTION D\'UNE APPLICATION WEB', 'font' => ['size' => 20, 'bold' => true]],
            ['text' => 'Présenté par : JEAN DUPONT', 'font' => ['size' => 11]],
        ]);

        $result = (new CoverDetectionService())->detect($path);

        $titre = collect($result['zones'])->firstWhere('type', 'titre');
        $this->assertNotNull($titre);
        $this->assertSame('CONCEPTION D\'UNE APPLICATION WEB', $titre['value']);
        $this->assertSame('', $titre['label']);
    }

    public function test_detecte_la_date_par_annee_si_aucun_mot_cle(): void
    {
        // Aucun « Année académique » : la date est détectée par l'expression
        // d'année (2024-2025).
        $path = $this->createCoverDocx([
            ['text' => 'Thème : CONCEPTION', 'font' => ['size' => 16]],
            ['text' => 'Présenté par : JEAN', 'font' => ['size' => 11]],
            ['text' => '2024-2025', 'font' => ['size' => 11]],
        ]);

        $result = (new CoverDetectionService())->detect($path);

        $date = collect($result['zones'])->firstWhere('type', 'date');
        $this->assertNotNull($date);
        $this->assertSame('2024-2025', $date['value']);
    }

    public function test_retourne_aucune_zone_sur_un_document_sans_couverture(): void
    {
        $path = $this->createCoverDocx([
            ['text' => 'PARAGRAPHE ORDINAIRE SANS INDICE', 'font' => ['size' => 11]],
        ]);

        $result = (new CoverDetectionService())->detect($path);

        $this->assertSame([], $result['zones']);
        $this->assertCount(1, $result['lines']);
    }

    public function test_conserve_les_styles_des_lignes(): void
    {
        $result = (new CoverDetectionService())->detect($this->coverStandard());

        // La ligne du titre conserve ses styles (police 16, gras)
        $titreLine = $result['lines'][2];
        $this->assertNotNull($titreLine['styles']['font']);
        $this->assertSame(16, $titreLine['styles']['font']['basic']['size']);
        $this->assertTrue($titreLine['styles']['font']['style']['bold']);
    }
}
