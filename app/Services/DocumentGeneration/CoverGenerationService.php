<?php

declare(strict_types=1);

namespace App\Services\DocumentGeneration;

use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use RuntimeException;

/**
 * Génération d'une couverture DOCX à partir de la détection des zones
 * (Phase 3 — Couverture).
 *
 * La structure et les styles (police, taille, gras, alignement…) de la
 * couverture d'exemple sont conservés : seules les VALEURS des zones
 * détectées (titre, nom, encadrant, date) sont remplacées par les valeurs
 * saisies par l'étudiant.
 */
class CoverGenerationService
{
    /**
     * Génère une couverture autonome (DOCX) depuis une détection de zones.
     *
     * @param array<string, mixed> $detection  Sortie de CoverDetectionService::detect()
     * @param array<string, string> $values    Valeurs par rôle (nom, titre, encadrant, date)
     * @param string                $outputPath Chemin absolu du fichier à créer
     *
     * @return string Le chemin du fichier généré
     *
     * @throws RuntimeException Si l'écriture du DOCX échoue
     */
    public function generate(array $detection, array $values, string $outputPath): string
    {
        $phpWord = new PhpWord();
        $this->addCoverSection($phpWord, $detection, $values);

        try {
            $writer = IOFactory::createWriter($phpWord, 'Word2007');
            $writer->save($outputPath);
        } catch (\Throwable $e) {
            throw new RuntimeException(
                "CoverGenerationService : échec de génération de la couverture ({$e->getMessage()})",
                0,
                $e
            );
        }

        return $outputPath;
    }

    /**
     * Ajoute une section couverture à un document PhpWord existant (utilisé
     * par le DocumentReconstructor pour préfixer la couverture au document
     * reconstruit).
     *
     * @param array<string, mixed> $detection Sortie de CoverDetectionService::detect()
     * @param array<string, string> $values   Valeurs par rôle
     */
    public function addCoverSection(PhpWord $phpWord, array $detection, array $values): Section
    {
        $section = $phpWord->addSection();

        $zoneByLine = [];
        foreach (($detection['zones'] ?? []) as $zone) {
            $zoneByLine[(int) ($zone['line_index'] ?? 0)] = $zone;
        }

        foreach (($detection['lines'] ?? []) as $i => $line) {
            $text = (string) ($line['text'] ?? '');

            $zone = $zoneByLine[$i] ?? null;
            if ($zone !== null) {
                $text = $this->replaceValue($text, $zone, $values);
            }

            $this->addStyledText($section, $text, $line['styles'] ?? null);
        }

        return $section;
    }

    /**
     * Remplace la valeur d'une zone en conservant son label.
     *
     * @param array<string, mixed>   $zone
     * @param array<string, string>  $values
     */
    private function replaceValue(string $original, array $zone, array $values): string
    {
        $type = (string) ($zone['type'] ?? '');
        if (!array_key_exists($type, $values)) {
            return $original;
        }

        $value = trim((string) $values[$type]);
        if ($value === '') {
            return $original;
        }

        $label = (string) ($zone['label'] ?? '');

        return $label !== '' ? $label . ' ' . $value : $value;
    }

    /**
     * Ajoute une ligne de texte avec ses styles d'origine (mappés en format
     * plat addText).
     *
     * @param null|array<string, mixed> $styles
     */
    private function addStyledText(Section $section, string $text, ?array $styles): void
    {
        $font = StyleMapper::toFlatFont($styles['font'] ?? null);
        $paragraph = StyleMapper::toFlatParagraph($styles['paragraph'] ?? null);

        $section->addText($text, $font, $paragraph);
    }
}
