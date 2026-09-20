<?php

namespace App\Services\Detection;

use Illuminate\Support\Facades\Log;

/**
 * Détection des légendes de figures/tableaux/annexes par expression régulière.
 *
 * Règle déterministe — AUCUN LLM ici (règle impérative n°1 du projet).
 * Formats observés sur les rapports réels :
 *   - `Figure N: libellé` (N entier ou numéroté par chapitre `2.3`)
 *   - `Figure N:libellé` (deux-points sans espace)
 *   - `Figure { SEQ Figure \* ARABIC }: libellé` (champ Word auto-numéroté)
 *   - `Figure 2: libellé<TAB>17` (entrée TOC — le numéro de page est retiré)
 *
 * Anti-doublons : les entrées des "LISTE DES FIGURES/TABLEAUX" (TOC) sont
 * ignorées via la détection du bloc, et deux légendes identiques (même type,
 * numéro et libellé normalisé) ne sont conservées qu'une seule fois.
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
     * Nombre maximal de lignes vides consécutives tolérées dans un bloc TOC.
     */
    private const TOC_GAP_TOLERANCE = 2;

    /**
     * Détecte toutes les légendes dans un texte.
     *
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
     * @return array<int, array{type: string, number: string, label: string, line: int, raw: string}>
     */
    private function detect(string $text): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $text);
        $legends = [];

        // Alternance types courts/longs pour le pattern de groupe
        $typesPattern = implode('|', array_map(fn ($t) => preg_quote($t, '/'), self::LEGEND_TYPES));

        // Formats :
        //  1. "Type N: libellé" — N entier simple ou numéroté par chapitre (2.3)
        //  2. "Type { SEQ Type \* ARABIC }: libellé" — champ Word auto-numéroté
        $number = '(?<number>\d+(?:\.\d+)*)';
        $seqField = '\{\s*SEQ\s+(?<seqtype>'.$typesPattern.')[^}]*\}';
        $pattern = '/^(?<type>'.$typesPattern.')\s*(?:'.$number.'|'.$seqField.')\s*:\s*(?<label>.+)$/iu';

        $tocBoundaries = $this->findTocBoundaries($lines);

        // Compteurs de numérotation SEQ par type (résolution "à la Word")
        $seqCounters = array_fill_keys(self::LEGEND_TYPES, 0);

        foreach ($lines as $index => $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }

            // On ignore les lignes qui sont dans une "LISTE DES ..." (TOC) :
            // ce sont des entrées dupliquées des légendes du corps.
            if ($this->isInToc($index, $tocBoundaries)) {
                continue;
            }

            if (! preg_match($pattern, $trimmed, $matches)) {
                continue;
            }

            $type = ucfirst(mb_strtolower($matches['type']));
            $label = $this->cleanLabel($matches['label']);

            if ($label === '') {
                continue;
            }

            // Numéro : soit explicite (Figure 2), soit résolu depuis le champ
            // SEQ (Word numérote automatiquement par type : 1, 2, 3…)
            if (! empty($matches['number'])) {
                $number = $matches['number'];
            } else {
                $seqType = ucfirst(mb_strtolower($matches['seqtype'] ?? $type));
                $seqCounters[$seqType] = ($seqCounters[$seqType] ?? 0) + 1;
                $number = (string) $seqCounters[$seqType];
            }

            $legends[] = [
                'type' => $type,
                'number' => $number,
                'label' => $label,
                'line' => $index + 1, // ligne 1-based pour l'affichage
                'raw' => $trimmed,
            ];
        }

        return $this->deduplicate($legends);
    }

    /**
     * Repère les blocs "LISTE DES FIGURES / TABLEAUX / ..." (tables des
     * matières générées par Word). On retourne une liste de plages
     * [start, end] (index 0-based) à ignorer lors de la détection.
     *
     * @param  string[]  $lines
     * @return array<int, array{0: int, 1: int}>
     */
    private function findTocBoundaries(array $lines): array
    {
        $typesPattern = implode('|', array_map(fn ($t) => preg_quote($t, '/'), self::LEGEND_TYPES));
        $tocTitle = '/^LISTE\s+DES\s+('.$typesPattern.')S?$/iu';

        $boundaries = [];
        $count = count($lines);

        for ($i = 0; $i < $count; $i++) {
            if (! preg_match($tocTitle, trim($lines[$i]))) {
                continue;
            }

            $start = $i;
            $end = $i;
            $consecutiveEmpty = 0;

            for ($j = $i + 1; $j < $count; $j++) {
                $trimmed = trim($lines[$j]);

                if ($trimmed === '') {
                    $consecutiveEmpty++;
                    if ($consecutiveEmpty > self::TOC_GAP_TOLERANCE) {
                        break;
                    }

                    continue;
                }

                $consecutiveEmpty = 0;

                // Un nouveau titre majeur (SOMMAIRE, INTRODUCTION, LISTE DES…,
                // RESUME, DEDICACE, REMERCIEMENTS…) met fin au bloc TOC.
                if (preg_match('/^(SOMMAIRE|INTRODUCTION|LISTE\s+DES|RESUME|ABSTRACT|DEDICACE|REMERCIEMENTS|SIGLE|CHAPITRE|SECTION\s*\d|PARTIE\s*\d)/iu', $trimmed)) {
                    break;
                }

                // Une entrée TOC ressemble à "Figure 2: libellé<TAB>17"
                // ou "Tableau { SEQ Tableau \* ARABIC }: libellé".
                if (preg_match('/^(?:'.$typesPattern.')\s*(?:\d+(?:\.\d+)*|\{SEQ[^}]*\})\s*:/iu', $trimmed)) {
                    $end = $j;
                }
            }

            $boundaries[] = [$start, $end];
            $i = $end;
        }

        return $boundaries;
    }

    /**
     * Vérifie si une ligne (index 0-based) tombe dans un bloc TOC.
     *
     * @param  array<int, array{0: int, 1: int}>  $tocBoundaries
     */
    private function isInToc(int $lineIndex, array $tocBoundaries): bool
    {
        foreach ($tocBoundaries as [$start, $end]) {
            if ($lineIndex > $start && $lineIndex <= $end) {
                return true;
            }
        }

        return false;
    }

    /**
     * Nettoie le libellé : retire le numéro de page en fin de ligne
     * (entrées TOC "…<TAB>17", séparateur fiable = tabulation ou points
     * de suite) et les espaces superflus.
     */
    private function cleanLabel(string $label): string
    {
        $label = trim($label);

        // "libellé<TAB>17" ou "libellé<TAB> 17" (numéro de page TOC)
        $label = preg_replace('/\t+\s*\d+\s*$/', '', $label) ?? $label;

        // Points de suite TOC : "libellé......17"
        $label = preg_replace('/[.\x{2026}]{3,}\s*\d+\s*$/u', '', $label) ?? $label;

        // Entités HTML résiduelles de l'extraction (&#039; → ')
        $label = html_entity_decode($label, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim($label);
    }

    /**
     * Supprime les doublons : deux légendes dont le type, le numéro et le
     * libellé sont équivalents après normalisation typographique ne sont
     * conservées qu'une fois. La première occurrence (numéro de ligne le
     * plus bas) gagne.
     *
     * @param  array<int, array{type: string, number: string, label: string, line: int, raw: string}>  $legends
     * @return array<int, array{type: string, number: string, label: string, line: int, raw: string}>
     */
    private function deduplicate(array $legends): array
    {
        $seen = [];
        $unique = [];

        foreach ($legends as $legend) {
            $key = $this->normalizeDedupKey($legend['type'].'|'.$legend['number'].'|'.$legend['label']);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $unique[] = $legend;
        }

        return $unique;
    }

    /**
     * Normalise une clé de déduplication : minuscules, suppression des
     * accents (translitération), des apostrophes (droites/courbes/backtick),
     * des espaces et du "s" final (tolérance singulier/pluriel). Permet de
     * rapprocher deux variantes typographiques du même libellé
     * ("critere evaluation d un project" vs "critère évaluation d'un
     * Project", "bilan des charge" vs "bilan des charges").
     */
    private function normalizeDedupKey(string $key): string
    {
        $key = mb_strtolower($key);
        $key = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $key) ?: $key;
        $key = preg_replace('/[\'\x{2018}\x{2019}`\s]+/u', '', $key) ?? $key;

        // Tolérance singulier/pluriel : on retire un "s" final uniquement
        // si la clé reste suffisamment longue (évite les collisions sur
        // les libellés très courts).
        if (str_ends_with($key, 's') && mb_strlen($key) > 5) {
            $key = mb_substr($key, 0, -1);
        }

        return $key;
    }
}
