<?php

declare(strict_types=1);

namespace App\Services\Detection;

/**
 * Détection DÉTERMINISTE des ambiguïtés de structure (Phase 4).
 *
 * Un titre est signalé « ambigu » lorsque sa numérotation explicite
 * (1., 1.1, 1.1.1, II., …) contredit le niveau que les règles ont détecté.
 * Aucun LLM n'intervient : la règle est purement lexicale et testable.
 */
class AmbiguityDetectionService
{
    /**
     * Niveau par défaut quand un item ne porte pas de niveau explicite.
     *
     * @var array<string, int>
     */
    private const NIVEAU_PAR_DEFAUT = [
        'titres' => 1,
        'sous_titres' => 2,
    ];

    /**
     * Parcourt les titres détectés et retourne la liste des ambiguïtés.
     *
     * @param  array<string, mixed>  $structure
     * @return array<int, array<string, mixed>>
     */
    public function detect(array $structure): array
    {
        $ambiguities = [];

        foreach (self::NIVEAU_PAR_DEFAUT as $categorie => $niveauParDefaut) {
            foreach (($structure[$categorie] ?? []) as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $texte = trim((string) ($item['texte'] ?? ''));
                if ($texte === '') {
                    continue;
                }

                $niveauDetecte = (int) ($item['niveau'] ?? $niveauParDefaut);
                $numerotation = $this->analyserNumerotation($texte);

                if ($numerotation !== null && $numerotation['niveau'] !== $niveauDetecte) {
                    $ambiguities[] = [
                        'id' => self::itemKey($item),
                        'texte' => $texte,
                        'niveau_detecte' => $niveauDetecte,
                        'niveau_suggere' => $numerotation['niveau'],
                        'raison' => sprintf(
                            'La numérotation « %s » suggère un niveau %d.',
                            $numerotation['token'],
                            $numerotation['niveau']
                        ),
                    ];
                }
            }
        }

        return $ambiguities;
    }

    /**
     * Construit une clé stable identifiant un item par sa position.
     *
     * @param  array<string, mixed>  $item
     */
    public static function itemKey(array $item): string
    {
        $position = $item['position'] ?? [];
        $section = (int) ($position['section_index'] ?? 0);
        $element = (int) ($position['element_index'] ?? 0);
        $parent = (string) ($position['parent'] ?? 'body');

        return sprintf('s%de%dp%s', $section, $element, $parent);
    }

    /**
     * Analyse la numérotation en tête de texte et en déduit un niveau.
     *
     * Retourne null si le texte ne commence pas par une numérotation connue.
     * Une année (ex. « 2025 Rapport ») n'est pas considérée comme un niveau.
     *
     * @return array{niveau: int, token: string}|null
     */
    private function analyserNumerotation(string $texte): ?array
    {
        // Numérotation romaine : « II. » → chapitre de niveau 1.
        if (preg_match('/^([IVXLC]+)\.\s+/u', $texte, $matches) === 1) {
            return ['niveau' => 1, 'token' => $matches[1].'.'];
        }

        // Numérotation décimale : « 1. », « 1.1 », « 1.1.1 » → niveau = nombre de segments.
        if (preg_match('/^(\d+(?:\.\d+)+)\s+/u', $texte, $matches) === 1) {
            return [
                'niveau' => substr_count($matches[1], '.') + 1,
                'token' => $matches[1],
            ];
        }

        return null;
    }
}
