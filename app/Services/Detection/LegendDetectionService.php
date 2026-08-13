<?php

namespace App\Services\Detection;

use Illuminate\Support\Facades\Log;

/**
 * Détection des légendes de figures/tableaux/annexes par expression régulière.
 *
 * Règle déterministe — AUCUN LLM ici (règle impérative n°1 du projet).
 * Formats observés : `Figure N:`, `Tableau N:`, `Annexe N:`, `Image N:`,
 * `Planche N:`, `Schéma N:` (où N est un nombre).
 *
 * @see PROMPTS_ET_TESTS.md — variations à vérifier en Phase 1
 *      (points au lieu de deux-points, abréviations "Fig.", numérotation
 *      par chapitre "Figure 2.3") — étendre la regex si besoin, rester déterministe.
 */
class LegendDetectionService
{
    /**
     * Types de légendes reconnus, ordonnés du plus long au plus court
     * pour éviter les faux positifs partiels (ex: "Figure" seul).
     */
    private const LEGEND_TYPES = [
        'Figure',
        'Tableau',
        'Annexe',
        'Image',
        'Planche',
        'Schéma',
    ];

    /**
     * Détecte toutes les légendes dans un texte.
     *
     * @param string $text
     * @return array<int, array{type: string, number: string, label: string, line: int, raw: string}>
     */
    public function execute(string $text): array
    {
        try {
            Log::info('LegendDetectionService started', ['text_length' => strlen($text)]);

            if (empty(trim($text))) {
                throw new \Exception('LegendDetectionService : texte vide');
            }

            $legends = $this->detect($text);

            Log::info('LegendDetectionService completed', ['count' => count($legends)]);
            return $legends;
        } catch (\Exception $e) {
            Log::error('LegendDetectionService failed', [
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
            ]);
            throw $e;
        }
    }

    /**
     * Parse le texte ligne par ligne et extrait les légendes.
     *
     * @param string $text
     * @return array<int, array{type: string, number: string, label: string, line: int, raw: string}>
     */
    private function detect(string $text): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $text);
        $legends = [];

        // Alternance types courts/longs pour le pattern de groupe
        $typesPattern = implode('|', array_map(fn ($t) => preg_quote($t, '/'), self::LEGEND_TYPES));

        // Format : "Type N: libellé" — N peut être entier simple ou numéroté par chapitre (2.3)
        $pattern = '/^(?<type>' . $typesPattern . ')\s+(?<number>\d+(?:\.\d+)*)\s*:\s*(?<label>.+)$/iu';

        foreach ($lines as $index => $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }

            if (preg_match($pattern, $trimmed, $matches)) {
                $legends[] = [
                    'type' => ucfirst(mb_strtolower($matches['type'])),
                    'number' => $matches['number'],
                    'label' => trim($matches['label']),
                    'line' => $index + 1, // ligne 1-based pour l'affichage
                    'raw' => $trimmed,
                ];
            }
        }

        return $legends;
    }
}
