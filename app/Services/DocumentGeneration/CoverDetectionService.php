<?php

declare(strict_types=1);

namespace App\Services\DocumentGeneration;

use App\DocAnalyzer\DocumentParser;

/**
 * Détection DÉTERMINISTE des zones d'une couverture d'exemple (Phase 3).
 *
 * Aucun LLM n'intervient ici : chaque ligne de la couverture est classée via
 * des mots-clés normalisés (sans accents, minuscules), puis des heuristiques
 * de repli :
 *  - titre     : la ligne à la plus grande police (si aucun mot-clé trouvé) ;
 *  - date      : l'expression d'année « 20XX » ou « 20XX-20YY » ;
 *  - nom/encadrant : uniquement par mots-clés.
 *
 * Sortie :
 *  - lines : toutes les lignes (texte + styles + rôle éventuel), dans l'ordre ;
 *  - zones : les zones détectées { type, label, value, line_index }.
 */
class CoverDetectionService
{
    /**
     * Mots-clés normalisés (minuscules, sans accents) par rôle.
     *
     * @var array<string, string[]>
     */
    private const ROLE_KEYWORDS = [
        'nom' => [
            'presente par',
            'presentee par',
            'realise par',
            'realisee par',
            'soutenu par',
            'soutenue par',
            'elabore par',
            'elaboree par',
            'redige par',
            'redigee par',
            'etudiant',
            'etudiante',
        ],
        'encadrant' => [
            'encadre par',
            'encadree par',
            'encadrement',
            'encadreur',
            'supervise par',
            'supervisee par',
            'sous la direction',
            'maitre de stage',
            'maitresse de stage',
            'directeur',
            'directrice',
            'tuteur',
            'tutrice',
        ],
        'titre' => [
            'theme',
            'sujet',
            'titre',
            'intitule',
            'memoire',
        ],
        'date' => [
            'annee academique',
            'annee universitaire',
            'promotion',
            'session',
        ],
    ];

    /**
     * Ordre de priorité d'un rôle sur une ligne donnée.
     *
     * @var string[]
     */
    private const ROLE_ORDER = ['nom', 'encadrant', 'titre', 'date'];

    /**
     * Carte de suppression des accents (après passage en minuscules).
     *
     * @var array<string, string>
     */
    private const ACCENT_MAP = [
        'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a', 'å' => 'a',
        'ç' => 'c',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'î' => 'i', 'ï' => 'i', 'í' => 'i', 'ì' => 'i',
        'ô' => 'o', 'ö' => 'o', 'ó' => 'o', 'ò' => 'o', 'õ' => 'o',
        'û' => 'u', 'ü' => 'u', 'ù' => 'u', 'ú' => 'u',
        'ÿ' => 'y',
        'œ' => 'oe',
        'æ' => 'ae',
    ];

    /**
     * Détecte les zones d'une couverture d'exemple.
     *
     * @param string $examplePath Chemin absolu du DOCX de couverture d'exemple
     *
     * @return array{lines: array<int, array<string, mixed>>, zones: array<int, array<string, mixed>>}
     */
    public function detect(string $examplePath): array
    {
        $parser = new DocumentParser($examplePath);
        $parsed = $parser->parse();

        $lines = [];

        foreach (($parsed['sections'] ?? []) as $section) {
            foreach (($section['body'] ?? []) as $element) {
                if (!$this->isTextual($element)) {
                    continue;
                }

                $text = trim((string) ($element['text'] ?? ''));
                if ($text === '') {
                    continue;
                }

                // PhpWord peut ré-encoder l'apostrophe ASCII en « &#039; »
                // lors de la relecture du DOCX : on décode les entités HTML.
                $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML401, 'UTF-8');

                $lines[] = [
                    'text' => $text,
                    'styles' => $element['styles'] ?? ['font' => null, 'paragraph' => null],
                    'role' => null,
                ];
            }
        }

        $zones = $this->classifyZones($lines);

        return [
            'lines' => $lines,
            'zones' => $zones,
        ];
    }

    /**
     * Classe chaque ligne (mots-clés) puis applique les heuristiques de repli.
     *
     * @param array<int, array<string, mixed>> $lines
     *
     * @return array<int, array<string, mixed>>
     */
    private function classifyZones(array &$lines): array
    {
        $zones = [];

        foreach ($lines as $i => &$line) {
            $normalized = $this->normalize((string) $line['text']);
            $matched = false;

            foreach (self::ROLE_ORDER as $role) {
                foreach (self::ROLE_KEYWORDS[$role] as $keyword) {
                    if (mb_strpos($normalized, $keyword) !== false) {
                        [$label, $value] = $this->splitLabelValue((string) $line['text'], $role);
                        $line['role'] = $role;
                        $zones[] = [
                            'type' => $role,
                            'label' => $label,
                            'value' => $value,
                            'line_index' => $i,
                        ];
                        $matched = true;

                        break;
                    }
                }

                if ($matched) {
                    break;
                }
            }
        }
        unset($line);

        // Repli titre : la ligne (non déjà classée) à la plus grande police.
        // Ne s'applique qu'à un document multi-lignes (une vraie couverture).
        if (!$this->hasZone($zones, 'titre') && count($lines) >= 2) {
            $index = $this->largestFontLineIndex($lines);
            if ($index !== null) {
                $lines[$index]['role'] = 'titre';
                $zones[] = [
                    'type' => 'titre',
                    'label' => '',
                    'value' => $lines[$index]['text'],
                    'line_index' => $index,
                ];
            }
        }

        // Repli date : la première ligne (non déjà classée) contenant une année.
        if (!$this->hasZone($zones, 'date')) {
            $index = $this->yearLineIndex($lines);
            if ($index !== null) {
                $lines[$index]['role'] = 'date';
                $zones[] = [
                    'type' => 'date',
                    'label' => '',
                    'value' => $lines[$index]['text'],
                    'line_index' => $index,
                ];
            }
        }

        return $zones;
    }

    /**
     * Sépare le label (ex. « Présenté par : ») de la valeur (ex. « JEAN »).
     *
     * @return array{0: string, 1: string} [label, value]
     */
    private function splitLabelValue(string $original, string $role): array
    {
        // Séparation par « : » (cas le plus courant).
        $colonPos = mb_strpos($original, ':');
        if ($colonPos !== false) {
            $label = trim(mb_substr($original, 0, $colonPos + 1));
            $value = trim(mb_substr($original, $colonPos + 1));
            if ($value !== '') {
                return [$label, $value];
            }
        }

        // Date : on isole l'expression d'année (20XX ou 20XX-20YY).
        if ($role === 'date' && preg_match('/(20\d{2}(?:\s*[-–—\/]\s*20\d{2})?)/u', $original, $m)) {
            $label = trim(str_replace($m[1], '', $original));

            return [$label, $m[1]];
        }

        // Repli : toute la ligne est la valeur.
        return ['', $original];
    }

    /**
     * Index de la ligne (non classée) à la plus grande taille de police.
     *
     * @param array<int, array<string, mixed>> $lines
     */
    private function largestFontLineIndex(array $lines): ?int
    {
        $best = null;
        $bestSize = -1.0;

        foreach ($lines as $i => $line) {
            if ($line['role'] !== null) {
                continue;
            }

            $size = $this->fontSize($line['styles']['font'] ?? null);
            if ($size !== null && $size > $bestSize) {
                $bestSize = $size;
                $best = $i;
            }
        }

        return $best;
    }

    /**
     * Index de la première ligne (non classée) contenant une année 20XX.
     *
     * @param array<int, array<string, mixed>> $lines
     */
    private function yearLineIndex(array $lines): ?int
    {
        foreach ($lines as $i => $line) {
            if ($line['role'] !== null) {
                continue;
            }

            if (preg_match('/20\d{2}/', (string) $line['text'])) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Taille de police extraite du style imbriqué (null si absente).
     *
     * @param null|array<string, mixed> $font
     */
    private function fontSize(?array $font): ?float
    {
        $size = $font['basic']['size'] ?? null;

        return $size !== null ? (float) $size : null;
    }

    /**
     * Un élément est-il une ligne de texte exploitable ?
     *
     * @param array<string, mixed> $element
     */
    private function isTextual(array $element): bool
    {
        return in_array($element['type'] ?? '', ['texte', 'titre'], true);
    }

    /**
     * Une zone d'un type donné a-t-elle déjà été détectée ?
     *
     * @param array<int, array<string, mixed>> $zones
     */
    private function hasZone(array $zones, string $type): bool
    {
        foreach ($zones as $zone) {
            if (($zone['type'] ?? '') === $type) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalise un texte : minuscules puis suppression des accents.
     */
    private function normalize(string $text): string
    {
        return strtr(mb_strtolower($text), self::ACCENT_MAP);
    }
}
