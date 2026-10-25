<?php

declare(strict_types=1);

namespace App\Services\DocumentGeneration;

use App\Document\Structure\Block;
use App\Document\Structure\BlockType;
use App\Models\Document;

/**
 * Service d'analyse et de vérification de conformité académique d'un document.
 *
 * Vérifie que le document respecte la structure, les éléments et les styles
 * du standard académique de référence (rapport de stage / mémoire DQP) :
 *  1. En-tête (thème du rapport), Pied de page (mention du rédacteur) et
 *     double pagination (romaine `i, ii, iii…` en Section 1, arabe `1, 2, 3…`
 *     en Section 2 à partir de l'Introduction générale).
 *  2. Pièces liminaires en Section 1 : Sommaire (niveaux 1-2), Dédicace,
 *     Remerciements, Avant-propos, Liste des sigles et abréviations, Liste des
 *     tableaux, Liste des figures, Résumé, Abstract.
 *  3. Corps du rapport en Section 2 : Introduction générale, Parties /
 *     Chapitres / Sections hiérarchisés (niveaux 1 à 3), Conclusion générale,
 *     Références bibliographiques / Bibliographie.
 *  4. Section 3 : Table des matières complète (tous niveaux 1-9) en toute fin.
 *  5. Normes typographiques : Police Times New Roman 12pt, corps justifié
 *     (`both`), interligne 1,5, marges 2,5 cm, tableaux et figures numérotés.
 */
final class FormattingComplianceService
{
    /**
     * Extrait et résout la configuration d'en-tête et de pied de page du document.
     *
     * Priorité :
     *  1. Valeurs saisies/validées par l'utilisateur dans `structure['header_footer_config']`
     *  2. Valeurs détectées dans les en-têtes/pieds de page du document source
     *
     * @return array{
     *     theme: string,
     *     redacteur: string,
     *     mention_redacteur: string,
     *     detected_header: string,
     *     detected_footer: string,
     *     is_configured: bool
     * }
     */
    public function extractHeaderFooterConfig(Document $document): array
    {
        $structureModel = $document->structure;
        $rawStructure = is_array($structureModel?->structure) ? $structureModel->structure : [];
        $savedConfig = is_array($rawStructure['header_footer_config'] ?? null)
            ? $rawStructure['header_footer_config']
            : [];

        $structural = $structureModel?->structuralDocument();

        $detectedHeader = trim((string) ($rawStructure['en_tetes'][0]['texte'] ?? ''));
        $detectedFooter = trim((string) ($rawStructure['pieds_de_page'][0]['texte'] ?? ''));

        if ($structural !== null) {
            if ($detectedHeader === '') {
                foreach ($structural->blocksOfType(BlockType::Header) as $headerBlock) {
                    if (trim($headerBlock->text) !== '') {
                        $detectedHeader = trim($headerBlock->text);
                        break;
                    }
                }
            }

            if ($detectedFooter === '') {
                foreach ($structural->blocksOfType(BlockType::Footer) as $footerBlock) {
                    if (trim($footerBlock->text) !== '') {
                        $detectedFooter = trim($footerBlock->text);
                        break;
                    }
                }
            }
        }

        $cleanedFooter = $this->cleanDetectedFooter($detectedFooter);
        [$detectedRedacteur, $detectedMention] = $this->parseAuthorFromFooter($cleanedFooter);

        $theme = trim((string) ($savedConfig['theme'] ?? ''));
        if ($theme === '') {
            $theme = $detectedHeader;
        }

        $redacteur = trim((string) ($savedConfig['redacteur'] ?? ''));
        if ($redacteur === '') {
            $redacteur = $detectedRedacteur;
        }

        $mentionRedacteur = trim((string) ($savedConfig['mention_redacteur'] ?? ''));
        if ($mentionRedacteur === '') {
            $mentionRedacteur = $detectedMention;
        }
        if ($mentionRedacteur === '' && $redacteur !== '') {
            $mentionRedacteur = "RÉDIGÉ EN VUE D'ÊTRE PRÉSENTÉ PAR : ".$redacteur;
        }

        return [
            'theme' => $theme,
            'redacteur' => $redacteur,
            'mention_redacteur' => $mentionRedacteur,
            'effective_header' => $theme !== '' ? $theme : $detectedHeader,
            'effective_footer' => $mentionRedacteur !== '' ? $mentionRedacteur : $cleanedFooter,
            'detected_header' => $detectedHeader,
            'detected_footer' => $cleanedFooter,
            'is_configured' => $theme !== '' && ($redacteur !== '' || $mentionRedacteur !== ''),
        ];
    }

    /**
     * Analyse la conformité structurelle et typographique du document.
     *
     * @return array{
     *     score: int,
     *     status: string,
     *     status_label: string,
     *     passed_count: int,
     *     total_count: int,
     *     header_footer: array<string, mixed>,
     *     missing_sections: array<int, string>,
     *     categories: array<int, array{
     *         key: string,
     *         title: string,
     *         passed: int,
     *         total: int,
     *         checks: array<int, array{
     *             id: string,
     *             label: string,
     *             passed: bool,
     *             auto_applied: bool,
     *             detail: string
     *         }>
     *     }>
     * }
     */
    public function evaluate(Document $document): array
    {
        $headerFooter = $this->extractHeaderFooterConfig($document);
        $headings = $this->collectNormalizedHeadings($document);
        $counts = $this->collectElementCounts($document);

        $hasTitle = $headings !== [];
        $hasHeader = $headerFooter['theme'] !== '';
        $hasFooterAuthor = $headerFooter['redacteur'] !== '' || $headerFooter['mention_redacteur'] !== '' || $headerFooter['detected_footer'] !== '';

        $hasDedicace = $this->matchesAnyHeading($headings, ['DEDICACE']);
        $hasRemerciements = $this->matchesAnyHeading($headings, ['REMERCIEMENTS', 'REMERCIMENTS']);
        $hasAvantPropos = $this->matchesAnyHeading($headings, ['AVANT-PROPOS', 'AVANT PROPOS', 'PREFACE']);
        $hasSigles = $this->matchesAnyHeading($headings, [
            'LISTE DES SIGLES',
            'LISTE DES SIGLE',
            'LISTE DES ABREVIATIONS',
            'SIGLES ET ABREVIATIONS',
            'TABLE DES ABREVIATIONS',
        ]);
        $hasResume = $this->matchesAnyHeading($headings, ['RESUME']);
        $hasAbstract = $this->matchesAnyHeading($headings, ['ABSTRACT', 'EXECUTIVE SUMMARY']);

        $hasIntro = $this->matchesAnyHeading($headings, ['INTRODUCTION', 'INTRODUCTION GENERALE']);
        $hasPartsOrChapters = $this->matchesAnyHeading($headings, [
            'PREMIERE PARTIE',
            'DEUXIEME PARTIE',
            'PARTIE',
            'CHAPITRE',
            'SECTION',
        ]) || $counts['headings_level_1'] >= 2;
        $hasSubsections = $counts['headings_level_2_plus'] >= 1;
        $hasConclusion = $this->matchesAnyHeading($headings, ['CONCLUSION', 'CONCLUSION GENERALE']);
        $hasBibliographie = $this->matchesAnyHeading($headings, [
            'BIBLIOGRAPHIE',
            'REFERENCES BIBLIOGRAPHIQUES',
            'WEBOGRAPHIE',
            'REFERENCES',
        ]);

        $missingSections = [];
        if (! $hasDedicace) {
            $missingSections[] = 'Dédicace';
        }
        if (! $hasRemerciements) {
            $missingSections[] = 'Remerciements';
        }
        if (! $hasAvantPropos) {
            $missingSections[] = 'Avant-propos';
        }
        if (! $hasSigles) {
            $missingSections[] = 'Liste des sigles et abréviations';
        }
        if (! $hasResume) {
            $missingSections[] = 'Résumé';
        }
        if (! $hasAbstract) {
            $missingSections[] = 'Abstract';
        }
        if (! $hasIntro) {
            $missingSections[] = 'Introduction générale';
        }
        if (! $hasConclusion) {
            $missingSections[] = 'Conclusion générale';
        }
        if (! $hasBibliographie) {
            $missingSections[] = 'Références bibliographiques';
        }

        $categories = [
            [
                'key' => 'header_footer',
                'title' => 'En-tête, Pied de page & Pagination',
                'checks' => [
                    [
                        'id' => 'header_theme',
                        'label' => 'En-tête avec le thème du rapport',
                        'passed' => $hasHeader,
                        'auto_applied' => false,
                        'detail' => $hasHeader
                            ? 'Thème configuré : « '.mb_strimwidth($headerFooter['theme'], 0, 70, '…').' »'
                            : 'Renseignez le thème du rapport à l\'étape En-tête & Pied de page.',
                    ],
                    [
                        'id' => 'footer_author',
                        'label' => 'Pied de page avec mention du rédacteur',
                        'passed' => $hasFooterAuthor,
                        'auto_applied' => false,
                        'detail' => $hasFooterAuthor
                            ? 'Mention configurée : « '.($headerFooter['mention_redacteur'] !== '' ? $headerFooter['mention_redacteur'] : 'Rédigé par '.$headerFooter['redacteur']).' »'
                            : 'Renseignez le nom du rédacteur à l\'étape En-tête & Pied de page.',
                    ],
                    [
                        'id' => 'dual_pagination',
                        'label' => 'Double numérotation (Romaine i, ii, iii… puis Arabe 1, 2, 3…)',
                        'passed' => true,
                        'auto_applied' => true,
                        'detail' => 'Appliquée automatiquement : chiffres romains en Section 1 (pièces liminaires) et chiffres arabes dès l\'Introduction.',
                    ],
                ],
            ],
            [
                'key' => 'front_matter',
                'title' => 'Section 1 — Pièces liminaires (Pagination romaine)',
                'checks' => [
                    [
                        'id' => 'sommaire_tete',
                        'label' => 'Sommaire en tête de document (niveaux 1 et 2)',
                        'passed' => $hasTitle,
                        'auto_applied' => true,
                        'detail' => $hasTitle
                            ? 'Généré automatiquement en ouverture de la Section 1 avec mise à jour des champs Word.'
                            : 'Aucun titre détecté pour générer le sommaire.',
                    ],
                    [
                        'id' => 'dedicace_remerciements',
                        'label' => 'Dédicace & Remerciements sur pages dédiées',
                        'passed' => $hasDedicace || $hasRemerciements,
                        'auto_applied' => false,
                        'detail' => ($hasDedicace && $hasRemerciements)
                            ? 'Dédicace et Remerciements détectés et placés en Section 1 (numérotation romaine).'
                            : ($hasDedicace || $hasRemerciements
                                ? 'Une des deux sections est présente ('
                                    .($hasDedicace ? 'Dédicace' : 'Remerciements').').'
                                : 'Non détectés dans le document source.'),
                    ],
                    [
                        'id' => 'avant_propos_sigles',
                        'label' => 'Avant-propos & Liste des sigles et abréviations',
                        'passed' => $hasAvantPropos || $hasSigles,
                        'auto_applied' => false,
                        'detail' => ($hasAvantPropos && $hasSigles)
                            ? 'Avant-propos et Liste des sigles détectés en Section 1.'
                            : ($hasAvantPropos || $hasSigles
                                ? 'Section détectée : '.($hasAvantPropos ? 'Avant-propos' : 'Liste des sigles').'.'
                                : 'Optionnel ou absent du document source.'),
                    ],
                    [
                        'id' => 'listes_tableaux_figures',
                        'label' => 'Liste des tableaux & Liste des figures',
                        'passed' => true,
                        'auto_applied' => true,
                        'detail' => ($counts['captions'] > 0)
                            ? sprintf('%d légende(s) détectée(s) : listes générées automatiquement (Tableaux puis Figures).', $counts['captions'])
                            : 'Aucune légende détectée (les listes seront générées dès qu\'une légende est présente).',
                    ],
                    [
                        'id' => 'resume_abstract',
                        'label' => 'Résumé (FR) & Abstract (EN)',
                        'passed' => $hasResume || $hasAbstract,
                        'auto_applied' => false,
                        'detail' => ($hasResume && $hasAbstract)
                            ? 'Résumé et Abstract détectés et placés sur pages dédiées en Section 1.'
                            : ($hasResume || $hasAbstract
                                ? 'Un seul volet détecté ('.($hasResume ? 'Résumé' : 'Abstract').').'
                                : 'Résumé / Abstract non détectés dans le document source.'),
                    ],
                ],
            ],
            [
                'key' => 'body_structure',
                'title' => 'Sections 2 & 3 — Corps du rapport & Table des matières',
                'checks' => [
                    [
                        'id' => 'introduction_generale',
                        'label' => 'Introduction générale (début de la pagination arabe à 1)',
                        'passed' => $hasIntro,
                        'auto_applied' => false,
                        'detail' => $hasIntro
                            ? 'Introduction détectée : ouvre la Section 2 en page 1 (chiffres arabes).'
                            : 'Intitulé « INTRODUCTION » ou « INTRODUCTION GÉNÉRALE » non détecté.',
                    ],
                    [
                        'id' => 'hierarchie_parties_chapitres',
                        'label' => 'Hiérarchie Parties / Chapitres / Sous-sections (niveaux 1 à 3)',
                        'passed' => $hasPartsOrChapters && ($hasSubsections || $counts['headings_total'] >= 2),
                        'auto_applied' => true,
                        'detail' => sprintf(
                            '%d titre(s) principal(aux) (niv. 1) et %d sous-titre(s) (niv. 2–3) stylés avec sauts de page automatiques.',
                            $counts['headings_level_1'],
                            $counts['headings_level_2_plus']
                        ),
                    ],
                    [
                        'id' => 'conclusion_generale',
                        'label' => 'Conclusion générale sur page dédiée',
                        'passed' => $hasConclusion,
                        'auto_applied' => false,
                        'detail' => $hasConclusion
                            ? 'Conclusion détectée et placée sur une nouvelle page.'
                            : 'Intitulé « CONCLUSION » ou « CONCLUSION GÉNÉRALE » non détecté.',
                    ],
                    [
                        'id' => 'bibliographie',
                        'label' => 'Références bibliographiques / Bibliographie',
                        'passed' => $hasBibliographie,
                        'auto_applied' => false,
                        'detail' => $hasBibliographie
                            ? 'Section bibliographique détectée sur page dédiée.'
                            : 'Section « RÉFÉRENCES BIBLIOGRAPHIQUES » ou « BIBLIOGRAPHIE » non détectée.',
                    ],
                    [
                        'id' => 'table_des_matieres_fin',
                        'label' => 'Table des matières complète (niveaux 1 à 9) en fin de document',
                        'passed' => $hasTitle,
                        'auto_applied' => true,
                        'detail' => $hasTitle
                            ? 'Générée automatiquement en Section 3 (toute fin du document, tous niveaux).'
                            : 'En attente de titres détectés.',
                    ],
                ],
            ],
            [
                'key' => 'typography_media',
                'title' => 'Normes typographiques, Tableaux & Figures',
                'checks' => [
                    [
                        'id' => 'typography_standard',
                        'label' => 'Police Times New Roman 12pt, corps justifié, interligne 1,5, marges 2,5 cm',
                        'passed' => true,
                        'auto_applied' => true,
                        'detail' => 'Gabarit académique appliqué : justification intégrale (`both`), interligne 1,5, marges normalisées.',
                    ],
                    [
                        'id' => 'tables_figures_integrity',
                        'label' => 'Tableaux restylés (contenu intact), figures & formes vectorielles préservées',
                        'passed' => true,
                        'auto_applied' => true,
                        'detail' => sprintf(
                            '%d tableau(x), %d figure(s)/image(s) et %d légende(s) pris en charge sans altération des données.',
                            $counts['tables'],
                            $counts['figures'],
                            $counts['captions']
                        ),
                    ],
                ],
            ],
        ];

        $passedCount = 0;
        $totalCount = 0;

        foreach ($categories as &$category) {
            $catPassed = 0;
            $catTotal = count($category['checks']);

            foreach ($category['checks'] as $check) {
                $totalCount++;
                if ($check['passed']) {
                    $passedCount++;
                    $catPassed++;
                }
            }

            $category['label'] = $category['title'];
            $category['items'] = $category['checks'];
            $category['passed'] = $catPassed;
            $category['total'] = $catTotal;
        }
        unset($category);

        $score = $totalCount > 0 ? (int) round(($passedCount / $totalCount) * 100) : 0;

        [$status, $statusLabel] = match (true) {
            $score >= 80 => ['conforme', 'Conforme au standard académique'],
            $score >= 55 => ['partiel', 'Conformité partielle — vérifiez les éléments signalés'],
            default => ['attention', 'Structure incomplète — éléments académiques manquants'],
        };

        return [
            'score' => $score,
            'status' => $status,
            'status_label' => $statusLabel,
            'passed_count' => $passedCount,
            'total_count' => $totalCount,
            'header_footer' => $headerFooter,
            'missing_sections' => $missingSections,
            'categories' => $categories,
        ];
    }

    /**
     * Collecte tous les titres normalisés (majuscules, sans accents) du document.
     *
     * @return array<int, string>
     */
    private function collectNormalizedHeadings(Document $document): array
    {
        $structureModel = $document->structure;
        $structural = $structureModel?->structuralDocument();
        $rawHeadings = [];

        if ($structural !== null) {
            foreach ($structural->headings() as $heading) {
                $rawHeadings[] = $heading->text;
            }
        }

        $legacy = is_array($structureModel?->structure) ? $structureModel->structure : [];
        foreach (($legacy['titres'] ?? []) as $item) {
            $rawHeadings[] = (string) ($item['texte'] ?? '');
        }
        foreach (($legacy['sous_titres'] ?? []) as $item) {
            $rawHeadings[] = (string) ($item['texte'] ?? '');
        }
        foreach (($legacy['body_complet'] ?? []) as $element) {
            if (($element['type'] ?? '') === 'titre') {
                $rawHeadings[] = (string) ($element['text'] ?? '');
            }
        }

        $normalized = [];
        foreach ($rawHeadings as $text) {
            $norm = $this->normalizeHeading($text);
            if ($norm !== '') {
                $normalized[] = $norm;
            }
        }

        return array_values(array_unique($normalized));
    }

    /**
     * Compte les éléments par catégorie (titres niv.1, niv.2+, tableaux, figures, légendes).
     *
     * @return array{
     *     headings_total: int,
     *     headings_level_1: int,
     *     headings_level_2_plus: int,
     *     tables: int,
     *     figures: int,
     *     captions: int
     * }
     */
    private function collectElementCounts(Document $document): array
    {
        $structureModel = $document->structure;
        $structural = $structureModel?->structuralDocument();

        if ($structural !== null) {
            $headings = $structural->headings();
            $level1 = count(array_filter($headings, static fn (Block $b): bool => ($b->headingLevel ?? 1) <= 1));
            $level2Plus = count($headings) - $level1;

            return [
                'headings_total' => count($headings),
                'headings_level_1' => $level1,
                'headings_level_2_plus' => $level2Plus,
                'tables' => count($structural->blocksOfType(BlockType::Table)),
                'figures' => count($structural->blocksOfType(BlockType::Figure))
                    + count($structural->blocksOfType(BlockType::Image))
                    + count($structural->blocksOfType(BlockType::Shape)),
                'captions' => count($structural->blocksOfType(BlockType::Caption)),
            ];
        }

        $legacy = is_array($structureModel?->structure) ? $structureModel->structure : [];
        $level1 = count($legacy['titres'] ?? []);
        $level2Plus = count($legacy['sous_titres'] ?? []);

        return [
            'headings_total' => $level1 + $level2Plus,
            'headings_level_1' => $level1,
            'headings_level_2_plus' => $level2Plus,
            'tables' => count($legacy['tableaux'] ?? []),
            'figures' => count($legacy['images'] ?? []),
            'captions' => count($legacy['legends'] ?? []),
        ];
    }

    /**
     * @param  array<int, string>  $headings
     * @param  array<int, string>  $keywords
     */
    private function matchesAnyHeading(array $headings, array $keywords): bool
    {
        foreach ($headings as $heading) {
            foreach ($keywords as $keyword) {
                $normKey = $this->normalizeHeading($keyword);
                if ($normKey !== '' && (str_starts_with($heading, $normKey) || str_contains($heading, $normKey))) {
                    return true;
                }
            }
        }

        return false;
    }

    private function normalizeHeading(string $text): string
    {
        $text = mb_strtoupper(trim($text));
        $text = strtr($text, [
            'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E',
            'À' => 'A', 'Â' => 'A', 'Ä' => 'A',
            'Î' => 'I', 'Ï' => 'I',
            'Ô' => 'O', 'Ö' => 'O',
            'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U',
            'Ç' => 'C',
        ]);
        $text = (string) preg_replace('/\s+/u', ' ', $text);

        return trim($text, " \t\n\r\0\x0B:;.,-–—");
    }

    private function cleanDetectedFooter(string $text): string
    {
        $text = preg_replace('/\{[^}]*PAGE[^}]*\}/i', '', $text) ?? $text;
        // Retire un numéro de page isolé en fin de chaîne (ex: "... PAR : NOM 12" ou "... | iv")
        $text = preg_replace('/(?:\s*\|\s*|\s+)(?:[0-9]+|[ivxlcdm]+)\s*$/iu', '', $text) ?? $text;

        return trim($text, " \t\n\r\0\x0B|");
    }

    /**
     * Extrait le nom du rédacteur et/ou la mention complète depuis un pied de page détecté.
     *
     * @return array{0: string, 1: string} [redacteur, mention_redacteur]
     */
    private function parseAuthorFromFooter(string $footer): array
    {
        if ($footer === '') {
            return ['', ''];
        }

        if (preg_match('/^(?:R[ÉE]DIG[ÉE]|PR[ÉE]SENT[ÉE]|R[ÉE]ALIS[ÉE])[^:]*:\s*(.+)$/iu', $footer, $matches) === 1) {
            return [trim($matches[1]), $footer];
        }

        if (preg_match('/^(?:R[ée]dig[ée]\s+par|Pr[ée]sent[ée]\s+par|R[ée]alis[ée]\s+par)\s*:?\s*(.+)$/iu', $footer, $matches) === 1) {
            return [trim($matches[1]), $footer];
        }

        return ['', $footer];
    }
}
