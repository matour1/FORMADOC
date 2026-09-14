<?php

declare(strict_types=1);

namespace App\Document\Classification;

/**
 * Détection du niveau hiérarchique par le PATTERN DE NUMÉROTATION du texte.
 *
 * Pourquoi ce signal est le plus fiable (REFONTE_ARCHITECTURE.md §7) :
 * la numérotation est **saisie par l'utilisateur**. Elle exprime son intention
 * réelle, alors que les styles Word sont souvent mal appliqués (tout en
 * « Titre 1 » par paresse, ou formatage manuel sans style du tout).
 *
 * C'est ce signal qui permet de détecter les CONTRADICTIONS : un paragraphe en
 * style « Titre 1 » dont le texte commence par « 1.1 » est un niveau 2 — le
 * style et le texte se contredisent, la confiance doit donc chuter sous 0,7.
 *
 * Aucun appel LLM ici : c'est une reconnaissance de motifs, déterministe et
 * gratuite.
 */
final class HeadingNumberingPattern
{
    /**
     * Longueur maximale du texte d'un titre numéroté.
     *
     * Distinction décisive entre un TITRE et une ÉNUMÉRATION de procédure :
     *  - « 2. Situation géographique du RMS »        → titre (court)
     *  - « 1) Le médecin ouvre le dossier patient… » → énumération (phrase)
     *
     * Sans ce garde-fou, chaque étape numérotée d'un mode opératoire serait
     * détectée comme un titre, ce qui polluerait la table des matières. Une
     * valeur de 120 caractères laisse passer les titres longs sans laisser
     * entrer les phrases.
     */
    private const MAX_HEADING_LENGTH = 120;

    /**
     * Civilités et abréviations à ne pas confondre avec un niveau alphabétique.
     *
     * Sans cette liste noire, « M. ZIVO Desmond, promoteur… » serait détecté
     * comme un titre de niveau 2 (pattern « M. »).
     *
     * @var array<int, string>
     */
    private const NOT_A_HEADING_PREFIXES = [
        'M.', 'MM.', 'Mme', 'Mlle', 'Dr', 'Pr', 'Me', 'Mr', 'Mrs', 'Ms',
        'cf.', 'art.', 'al.', 'p.', 'pp.', 'fig.', 'env.', 'réf.',
    ];

    /**
     * Patterns de numérotation, du plus spécifique au plus général.
     *
     * L'ordre compte : « 1.1.1 » doit être testé avant « 1.1 » et « 1. »,
     * sinon la profondeur détectée serait toujours 1.
     *
     * Chaque entrée associe une expression régulière au niveau hiérarchique
     * qu'elle implique, et à un degré de fiabilité (`reliable`) qui distingue
     * les formes de numérotation de section des formes d'énumération.
     *
     * @var array<int, array{pattern: string, level: int, kind: string, reliable: bool}>
     */
    private const PATTERNS = [
        // Numérotation décimale profonde : 1.2.3.4 → niveau 4
        // La forme décimale « pointée » est réservée aux sections : très fiable.
        ['pattern' => '/^(\d+(?:\.\d+){3})[.)\s]/u', 'level' => 4, 'kind' => 'decimal', 'reliable' => true],
        // 1.2.3 → niveau 3
        ['pattern' => '/^(\d+(?:\.\d+){2})[.)\s]/u', 'level' => 3, 'kind' => 'decimal', 'reliable' => true],
        // 1.2 → niveau 2
        ['pattern' => '/^(\d+\.\d+)[.)\s]/u', 'level' => 2, 'kind' => 'decimal', 'reliable' => true],
        // 1. → niveau 1 (point = section)
        ['pattern' => '/^(\d+)\.\s/u', 'level' => 1, 'kind' => 'decimal', 'reliable' => true],
        // 1) → énumération : niveau 1 mais PEU FIABLE (voir isReliable)
        ['pattern' => '/^(\d+)\)\s/u', 'level' => 1, 'kind' => 'enumerated', 'reliable' => false],

        // Mot-clés explicites de niveau 1 (majuscules, usuels en français)
        ['pattern' => '/^(CHAPITRE|PARTIE|TITRE)\s+([IVXLCDM\d]+)/u', 'level' => 1, 'kind' => 'keyword', 'reliable' => true],
        ['pattern' => '/^(INTRODUCTION|CONCLUSION|RESUME|RÉSUMÉ|ABSTRACT|SOMMAIRE|REMERCIEMENTS|DEDICACE|DÉDICACE)\b/u', 'level' => 1, 'kind' => 'keyword', 'reliable' => true],
        ['pattern' => '/^(BIBLIOGRAPHIE|ANNEXES?|GLOSSAIRE)\b/u', 'level' => 1, 'kind' => 'keyword', 'reliable' => true],

        // Numérotation romaine : I., II., III. → niveau 1
        ['pattern' => '/^([IVXLCDM]+)[.)]\s/u', 'level' => 1, 'kind' => 'roman', 'reliable' => true],

        // Numérotation alphabétique : A., B. → niveau 2 (sous-section)
        ['pattern' => '/^([A-Z])[.)]\s/u', 'level' => 2, 'kind' => 'alpha', 'reliable' => false],
    ];

    /**
     * Analyse un texte de titre et en déduit le niveau par numérotation.
     *
     * @param  string  $text  Texte du paragraphe (déjà nettoyé)
     * @return null|array{level: int, kind: string, matched: string} null si aucune numérotation reconnue
     */
    public function detect(string $text): ?array
    {
        $text = ltrim($text);

        if ($text === '') {
            return null;
        }

        // Un titre peut être précédé d'une puce ou d'un tiret.
        $text = ltrim($text, "•·–—\t ");

        // --- Garde-fou 1 : une phrase n'est pas un titre ---
        // Distingue « 2. Situation du RMS » (titre) de « 1) Le médecin ouvre le
        // dossier du patient et consulte l'historique… » (étape de procédure).
        // Sans ceci, chaque étape d'un mode opératoire deviendrait un titre de
        // la table des matières.
        if (mb_strlen($text) > self::MAX_HEADING_LENGTH) {
            return null;
        }

        // --- Garde-fou 2 : ne pas confondre une civilité avec un titre ---
        // « M. ZIVO Desmond, promoteur… » correspondrait au pattern « M. ».
        foreach (self::NOT_A_HEADING_PREFIXES as $prefix) {
            if (str_starts_with($text, $prefix.' ')) {
                return null;
            }
        }

        foreach (self::PATTERNS as $candidate) {
            if (preg_match($candidate['pattern'], $text, $matches) !== 1) {
                continue;
            }

            $rest = trim(mb_substr($text, mb_strlen($matches[0])));

            // Un titre numéroté porte du CONTENU après le numéro — SAUF les
            // mots-clés de section, qui constituent souvent le titre entier
            // (« INTRODUCTION », « CONCLUSION », « BIBLIOGRAPHIE »).
            if ($candidate['kind'] !== 'keyword' && mb_strlen($rest) < 2) {
                continue;
            }

            // Le niveau alphabétique exige un contenu plus substantiel : des
            // listes « A. », « B. » sont plus souvent des énumérations.
            if ($candidate['kind'] === 'alpha' && mb_strlen($rest) < 4) {
                continue;
            }

            // --- Garde-fou 3 : une énumération (1) 2) 3)) qui finit par un
            // point est une phrase, pas un intitulé. On ne retient les formes
            // peu fiables que si le texte ressemble vraiment à un titre.
            if (! $candidate['reliable'] && $this->looksLikeSentence($rest)) {
                continue;
            }

            return [
                'level' => $candidate['level'],
                'kind' => $candidate['kind'],
                'reliable' => $candidate['reliable'],
                'matched' => $matches[0],
            ];
        }

        return null;
    }

    /**
     * Le texte ressemble-t-il à une phrase plutôt qu'à un intitulé ?
     *
     * Un titre ne se termine jamais par un point final, alors qu'une étape de
     * procédure oui. Ce signal départage « 1) Authentification » (titre) de
     * « 1) Le système affiche les consultations précédentes. » (procédure).
     */
    private function looksLikeSentence(string $text): bool
    {
        return preg_match('/[.!?]\s*$/u', $text) === 1;
    }

    /**
     * Le texte porte-t-il une numérotation qui CONTREDIT le niveau du style ?
     *
     * Cas réel rencontré sur les documents du projet : un paragraphe en style
     * « Titre 1 » (donc niveau 1) dont le texte est « 1.1 Historique » — le
     * style a manifestement été appliqué à la légère. Signaler la contradiction
     * permet de faire trancher par l'utilisateur au lieu d'imposer un choix
     * arbitraire (§7 : confiance < 0,7 en cas de signaux contradictoires).
     *
     * @param  string  $text  Texte du paragraphe
     * @param  int  $styleLevel  Niveau déduit du style Word
     * @return null|array{pattern_level: int, style_level: int} null si cohérent
     */
    public function contradictionWith(string $text, int $styleLevel): ?array
    {
        $detected = $this->detect($text);

        if ($detected === null) {
            return null;
        }

        if ($detected['level'] === $styleLevel) {
            return null;
        }

        return [
            'pattern_level' => $detected['level'],
            'style_level' => $styleLevel,
        ];
    }

    /**
     * Le texte porte-t-il une numérotation de niveau 1 ?
     *
     * Utilisé par la classification pour trancher sur les documents où
     * l'auteur n'a appliqué qu'un seul style de titre à toutes les sections.
     */
    public function isTopLevel(string $text): bool
    {
        $detected = $this->detect($text);

        return $detected !== null && $detected['level'] === 1;
    }

    /**
     * Le texte est-il numéroté (quelle que soit la profondeur) ?
     */
    public function isNumbered(string $text): bool
    {
        return $this->detect($text) !== null;
    }
}
