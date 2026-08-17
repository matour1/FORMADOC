<?php

declare(strict_types=1);

namespace App\DocAnalyzer;

/**
 * Détection de titres par motifs regex sur le texte positionné.
 *
 * Complément déterministe au RuleBasedDetector : quand les styles Word
 * (HeadingN, tailles, gras) n'ont rien détecté, cette passe analyse le
 * texte brut pour repérer les titres par leur FORME :
 *   - numérotation décimale : "1. Introduction", "1.1 Contexte", "2.3.1 …"
 *   - mots-clés de niveau 1  : CHAPITRE, INTRODUCTION, CONCLUSION, SOMMAIRE,
 *                              BIBLIOGRAPHIE, ANNEXE(S), REMERCIEMENTS…
 *
 * Les légendes (Figure/Tableau/Image n°) sont exclues pour éviter les faux
 * positifs. Les positions [POS:section_X,element_Y,parent_Z] d'origine sont
 * conservées telles quelles pour la fusion et le tri (ResultMerger).
 */
class RegexTitleDetector
{
    /**
     * Numérotation décimale : 1 à 4 segments de 1-2 chiffres, avec point
     * final optionnel, suivis d'un espace et d'une majuscule
     * (ex : "1. Introduction", "1.1 Contexte", "12.3.4 Analyse").
     */
    private const NUMERATION_PATTERN = '/^\d{1,2}(?:\.\d{1,2}){0,3}\.?\s+\p{Lu}/u';

    /**
     * Mots-clés de niveau 1 (début de ligne, suivis d'un espace, signe de
     * ponctuation ou fin de ligne).
     */
    private const MOTS_CLES_NIVEAU_1 = [
        'CHAPITRE\s+\d+',
        'INTRODUCTION',
        'CONCLUSION',
        'RÉSUMÉ',
        'RESUME',
        'ABSTRACT',
        'SOMMAIRE',
        'TABLE\s+DES\s+MATIÈRES',
        'BIBLIOGRAPHIE',
        'ANNEXE',
        'ANNEXES',
        'REMERCIEMENTS',
        'DÉDICACE',
        'DEDICACE',
        'AVANT\s*-?\s*PROPOS',
        'LISTE\s+DES\s+(?:FIGURES|TABLEAUX)',
        'SIGLES\s+ET\s+ABRÉVIATIONS',
        'GLOSSAIRE',
    ];

    /**
     * Légendes exclues des titres (faux positifs).
     */
    private const LEGENDE_PATTERN = '/^(?:figure|tableau|image|schéma|schema|graphique)\s*\d+/iu';

    /**
     * Détecte les titres et sous-titres par motifs regex.
     *
     * @param string $contextTextWithPositions Sortie context_text_with_positions du DocumentParser
     *
     * @return array<string, array<int, array<string, mixed>>> titres + sous_titres
     */
    public function detect(string $contextTextWithPositions): array
    {
        $titres = [];
        $sousTitres = [];

        foreach (preg_split('/\r?\n/', $contextTextWithPositions) ?: [] as $line) {
            $parsed = $this->parseLine($line);
            if ($parsed === null) {
                continue;
            }

            [$position, $text] = $parsed;

            $niveau = $this->titreNiveau($text);
            if ($niveau === null) {
                continue;
            }

            $item = [
                'texte' => $text,
                'position' => $position,
                'styles' => [],
                'type' => 'titre',
                'niveau' => $niveau,
                'source' => 'regex',
            ];

            if ($niveau === 1) {
                $titres[] = $item;
            } else {
                $sousTitres[] = $item;
            }
        }

        return ['titres' => $titres, 'sous_titres' => $sousTitres];
    }

    /**
     * Détermine le niveau de titre d'un texte, ou null s'il ne s'agit pas
     * d'un titre selon les motifs.
     */
    private function titreNiveau(string $text): ?int
    {
        $text = trim($text);

        if ($text === '' || mb_strlen($text) < 3) {
            return null;
        }

        // Légendes : jamais des titres
        if (preg_match(self::LEGENDE_PATTERN, $text) === 1) {
            return null;
        }

        // Mots-clés de niveau 1
        $motsCles = implode('|', self::MOTS_CLES_NIVEAU_1);
        if (preg_match('/^(?:' . $motsCles . ')(?:\s|[:.\-—]|$)/iu', $text) === 1) {
            return 1;
        }

        // Numérotation décimale : le niveau = nombre de segments
        if (preg_match(self::NUMERATION_PATTERN, $text) === 1) {
            // Préfixe numérique = tout ce qui précède le premier espace.
            // "1." → 1 segment (niveau 1) ; "1.1" → 2 segments (niveau 2) ;
            // "1.1.1" → 3 segments (niveau 3). Le point final éventuel
            // ("1.") ne compte pas comme séparateur de segments.
            $prefix = substr($text, 0, (int) strpos($text, ' '));
            $level = substr_count(rtrim($prefix, '.'), '.') + 1;

            return min($level, 3);
        }

        return null;
    }

    /**
     * Extrait la position et le texte d'une ligne [POS:...]TEXTE.
     *
     * @return null|array{0: array{section_index: int, element_index: int, parent: string}, 1: string}
     */
    private function parseLine(string $line): ?array
    {
        if (preg_match('/^\[POS:section_(\d+),element_(\d+),parent_([a-z_]+)\](.*)$/i', $line, $m) !== 1) {
            return null;
        }

        $parent = strtolower((string) $m[3]);

        // Les en-têtes/pieds de page ne contiennent pas de titres
        if ($parent !== 'body') {
            return null;
        }

        return [
            [
                'section_index' => (int) $m[1],
                'element_index' => (int) $m[2],
                'parent' => $parent,
            ],
            trim((string) $m[4]),
        ];
    }
}
