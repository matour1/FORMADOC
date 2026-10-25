<?php

declare(strict_types=1);

namespace App\Services\DocumentGeneration;

use App\Document\Formatting\FigureFlowFormatter;
use App\Document\Numbering\NumberingCoordinator;
use App\Document\Structure\Block;
use App\Document\Structure\BlockType;
use App\Document\Structure\StructuralDocument;
use App\Models\Document;

/**
 * Construit le rapport utilisateur structuré de fin de traitement (§9, D-F1).
 *
 * **Principe du projet** : aucune correction silencieuse. L'utilisateur doit
 * savoir exactement ce que FORMADOC a corrigé, ce qui a été supprimé (page de
 * garde, ancien sommaire) et ce qui reste à vérifier (titres déduits, légendes
 * manquantes, numéros dupliqués, renvois croisés incertains, formes préservées).
 *
 * Le rapport est exposé en deux points :
 *  1. **Affiché** sur les écrans de validation (`documents.show`) et d'export
 *     (`documents.export`) ;
 *  2. **Téléchargeable** au format JSON structuré via `documents.report`.
 */
final class UserReportBuilder
{
    public function __construct(
        private readonly NumberingCoordinator $numbering = new NumberingCoordinator,
        private readonly FigureFlowFormatter $figureFlow = new FigureFlowFormatter,
    ) {}

    /**
     * Construit le rapport utilisateur complet pour un document.
     *
     * Lit `structural_json` en priorité (pipeline natif), et se replie sur
     * l'ancien `structure` pour les documents analysés avant l'activation du
     * pipeline natif (stratégie du strangler).
     *
     * @return array{
     *     document_id: string,
     *     filename: string,
     *     pipeline: string,
     *     generated_at: string,
     *     original_url: string,
     *     report_download_url: string,
     *     corrections: array<int, array{key: string, label: string, count: int, applied: bool}>,
     *     corrections_totals: array<string, int>,
     *     warnings: array<int, array{code: string, level: string, title: string, message: string, count: int}>,
     *     has_warnings: bool
     * }
     */
    public function build(Document $document): array
    {
        $structureModel = $document->structure;
        $structural = $structureModel?->structuralDocument();

        $originalUrl = route('documents.preview', $document);
        $reportDownloadUrl = route('documents.report', $document);

        if ($structural !== null) {
            return $this->buildFromStructural(
                $document,
                $structural,
                $originalUrl,
                $reportDownloadUrl,
            );
        }

        return $this->buildFromLegacy(
            $document,
            is_array($structureModel?->structure) ? $structureModel->structure : [],
            is_array($structureModel?->ambiguities) ? $structureModel->ambiguities : [],
            $originalUrl,
            $reportDownloadUrl,
        );
    }

    /**
     * Construit le rapport depuis le modèle structurel natif (`StructuralDocument`).
     *
     * @return array<string, mixed>
     */
    private function buildFromStructural(
        Document $document,
        StructuralDocument $structural,
        string $originalUrl,
        string $reportDownloadUrl,
    ): array {
        // Exécute la passe de renumérotation (déterministe, 0 token) pour
        // connaître les légendes renumérotées, les anomalies (doublons/trous)
        // et les renvois croisés résolus ou incertains.
        $numberingResult = $this->numbering->run($structural);
        /** @var StructuralDocument $renumberedDoc */
        $renumberedDoc = $numberingResult['document'];
        $figurePlan = $this->figureFlow->plan($renumberedDoc->blocks);

        $headings = $renumberedDoc->headings();
        $headingsConfirmed = array_values(array_filter(
            $headings,
            static fn (Block $b): bool => $b->confidence >= Block::AUTO_ACCEPT_THRESHOLD
        ));
        $headingsAmbiguous = array_values(array_filter(
            $headings,
            static fn (Block $b): bool => $b->confidence < Block::AUTO_ACCEPT_THRESHOLD
        ));

        $tablesCount = count($renumberedDoc->blocksOfType(BlockType::Table));
        $figuresCount = count($renumberedDoc->blocksOfType(BlockType::Figure))
            + count($renumberedDoc->blocksOfType(BlockType::Image));
        $captionsCount = count($renumberedDoc->blocksOfType(BlockType::Caption));
        $tocEntriesRemoved = count($renumberedDoc->blocksOfType(BlockType::TocEntry));
        $shapesPreserved = count($renumberedDoc->blocksOfType(BlockType::Shape));

        $emptyParagraphsNormalized = (int) ($structural->meta['empty_paragraphs_normalized'] ?? 0);
        $coverPage = is_array($structural->meta['cover_page'] ?? null)
            ? $structural->meta['cover_page']
            : null;
        $coverRemovedBlocks = (int) ($coverPage['supprime'] ?? 0);

        $renumberedCount = count(array_filter(
            $numberingResult['changes'] ?? [],
            static fn (array $change): bool => ($change['original'] ?? null) !== null
        ));
        $crossRefsResolved = (int) ($numberingResult['references']['resolved'] ?? 0);

        $totals = [
            'headings_formatted' => count($headings),
            'headings_confirmed' => count($headingsConfirmed),
            'headings_to_validate' => count($headingsAmbiguous),
            'tables_formatted' => $tablesCount,
            'figures_and_images' => $figuresCount,
            'captions_detected' => $captionsCount,
            'captions_renumbered' => $renumberedCount,
            'cross_refs_resolved' => $crossRefsResolved,
            'toc_entries_removed' => $tocEntriesRemoved,
            'empty_paragraphs_normalized' => $emptyParagraphsNormalized,
            'cover_page_removed_blocks' => $coverRemovedBlocks,
            'shapes_preserved' => $shapesPreserved,
        ];

        $corrections = [
            [
                'key' => 'headings_formatted',
                'label' => 'Titres mis en forme et hiérarchisés',
                'count' => count($headings),
                'applied' => count($headings) > 0,
            ],
            [
                'key' => 'tables_formatted',
                'label' => 'Tableaux restylés (contenu intact)',
                'count' => $tablesCount,
                'applied' => $tablesCount > 0,
            ],
            [
                'key' => 'figures_and_images',
                'label' => 'Figures et images mises en flux',
                'count' => $figuresCount,
                'applied' => $figuresCount > 0,
            ],
            [
                'key' => 'captions_detected',
                'label' => 'Légendes détectées et numérotées',
                'count' => $captionsCount,
                'applied' => $captionsCount > 0,
            ],
            [
                'key' => 'captions_renumbered',
                'label' => 'Numéros de légendes recalculés',
                'count' => $renumberedCount,
                'applied' => $renumberedCount > 0,
            ],
            [
                'key' => 'toc_entries_removed',
                'label' => 'Entrées d\'ancien sommaire purgées et régénérées',
                'count' => $tocEntriesRemoved,
                'applied' => $tocEntriesRemoved > 0,
            ],
            [
                'key' => 'empty_paragraphs_normalized',
                'label' => 'Paragraphes vides consécutifs normalisés en sauts de page',
                'count' => $emptyParagraphsNormalized,
                'applied' => $emptyParagraphsNormalized > 0,
            ],
            [
                'key' => 'cover_page_removed_blocks',
                'label' => 'Blocs de page de garde source retirés',
                'count' => $coverRemovedBlocks,
                'applied' => $coverRemovedBlocks > 0,
            ],
        ];

        $warnings = [];

        // 1. Page de garde supprimée ou candidat trop volumineux (§5.2)
        if ($coverRemovedBlocks > 0) {
            $raison = (string) ($coverPage['raison'] ?? 'première page détectée avant le corps du rapport');
            $extrait = is_string($coverPage['extrait'] ?? null) && $coverPage['extrait'] !== ''
                ? ' (début : « '.$coverPage['extrait'].' »)'
                : '';
            $warnings[] = [
                'code' => 'cover_page_removed',
                'level' => 'info',
                'title' => 'Page de garde supprimée',
                'message' => sprintf(
                    'La page de garde d\'origine a été retirée (%d bloc%s — %s)%s. FORMADOC applique le gabarit directement à partir des pièces liminaires.',
                    $coverRemovedBlocks,
                    $coverRemovedBlocks > 1 ? 's' : '',
                    $raison,
                    $extrait,
                ),
                'count' => $coverRemovedBlocks,
            ];
        } elseif (! empty($coverPage['avertissement'])) {
            $warnings[] = [
                'code' => 'cover_page_skipped_too_large',
                'level' => 'warning',
                'title' => 'Page de garde conservée (> 2 pages)',
                'message' => (string) $coverPage['avertissement'],
                'count' => 1,
            ];
        }

        // 2. Titres ou blocs ambigus (« à valider »)
        $ambiguousBlocks = $renumberedDoc->ambiguous();
        if ($ambiguousBlocks !== []) {
            $countAmbiguous = count($ambiguousBlocks);
            $warnings[] = [
                'code' => 'ambiguous_blocks',
                'level' => 'warning',
                'title' => 'Passages à valider',
                'message' => sprintf(
                    '%d élément%s (dont %d titre%s proposé%s) présente%s une confiance inférieure à 85 %% et mérite%s votre validation.',
                    $countAmbiguous,
                    $countAmbiguous > 1 ? 's' : '',
                    count($headingsAmbiguous),
                    count($headingsAmbiguous) > 1 ? 's' : '',
                    count($headingsAmbiguous) > 1 ? 's' : '',
                    $countAmbiguous > 1 ? 'nt' : '',
                    $countAmbiguous > 1 ? 'nt' : '',
                ),
                'count' => $countAmbiguous,
            ];
        }

        // 3. Figures sans légende ou légendes orphelines
        $uncaptionedCount = count($figurePlan['uncaptioned_figures'] ?? []);
        if ($uncaptionedCount > 0) {
            $warnings[] = [
                'code' => 'uncaptioned_figures',
                'level' => 'warning',
                'title' => 'Figures sans légende',
                'message' => sprintf(
                    '%d figure%s ou image%s ne possède%s pas de légende rattachée — à compléter si elles doivent figurer dans la liste des figures.',
                    $uncaptionedCount,
                    $uncaptionedCount > 1 ? 's' : '',
                    $uncaptionedCount > 1 ? 's' : '',
                    $uncaptionedCount > 1 ? 'nt' : '',
                ),
                'count' => $uncaptionedCount,
            ];
        }

        // 4. Renvois croisés incertains ou non résolus
        $numberingReport = is_array($numberingResult['report'] ?? null) ? $numberingResult['report'] : [];
        if (($numberingReport['has_warnings'] ?? false) && ! empty($numberingReport['message'])) {
            $unresolvedCount = (int) ($numberingReport['unresolved'] ?? 0) + (int) ($numberingReport['count'] ?? 0);
            $warnings[] = [
                'code' => 'cross_references',
                'level' => 'warning',
                'title' => 'Renvois croisés à vérifier',
                'message' => (string) $numberingReport['message'],
                'count' => max(1, $unresolvedCount),
            ];
        }

        // 5. Formes / diagrammes vectoriels laissés intacts
        if ($shapesPreserved > 0) {
            $warnings[] = [
                'code' => 'shapes_preserved',
                'level' => 'info',
                'title' => 'Diagrammes en formes conservés',
                'message' => sprintf(
                    '%d bloc%s de formes ou zones de texte Word %s été conservé%s tel%s quel%s sans modification.',
                    $shapesPreserved,
                    $shapesPreserved > 1 ? 's' : '',
                    $shapesPreserved > 1 ? 'ont' : 'a',
                    $shapesPreserved > 1 ? 's' : '',
                    $shapesPreserved > 1 ? 's' : '',
                    $shapesPreserved > 1 ? 's' : '',
                ),
                'count' => $shapesPreserved,
            ];
        }

        return [
            'document_id' => (string) $document->hash_id,
            'filename' => (string) $document->filename,
            'pipeline' => 'native',
            'generated_at' => now()->toIso8601String(),
            'original_url' => $originalUrl,
            'report_download_url' => $reportDownloadUrl,
            'corrections' => $corrections,
            'corrections_totals' => $totals,
            'warnings' => $warnings,
            'has_warnings' => $warnings !== [],
            'compliance' => (new FormattingComplianceService)->evaluate($document),
        ];
    }

    /**
     * Construit le rapport depuis l'ancien format (`structure`).
     *
     * @param  array<string, mixed>  $structure
     * @param  array<int, array<string, mixed>>  $ambiguities
     * @return array<string, mixed>
     */
    private function buildFromLegacy(
        Document $document,
        array $structure,
        array $ambiguities,
        string $originalUrl,
        string $reportDownloadUrl,
    ): array {
        $titresCount = count($structure['titres'] ?? []) + count($structure['sous_titres'] ?? []);
        $tablesCount = count($structure['tableaux'] ?? []);
        $figuresCount = count($structure['images'] ?? []);
        $captionsCount = count($structure['legends'] ?? []);

        $totals = [
            'headings_formatted' => $titresCount,
            'headings_confirmed' => $titresCount,
            'headings_to_validate' => count($ambiguities),
            'tables_formatted' => $tablesCount,
            'figures_and_images' => $figuresCount,
            'captions_detected' => $captionsCount,
            'captions_renumbered' => 0,
            'cross_refs_resolved' => 0,
            'toc_entries_removed' => 0,
            'empty_paragraphs_normalized' => 0,
            'cover_page_removed_blocks' => 0,
            'shapes_preserved' => 0,
        ];

        $corrections = [
            [
                'key' => 'headings_formatted',
                'label' => 'Titres mis en forme et hiérarchisés',
                'count' => $titresCount,
                'applied' => $titresCount > 0,
            ],
            [
                'key' => 'tables_formatted',
                'label' => 'Tableaux restylés (contenu intact)',
                'count' => $tablesCount,
                'applied' => $tablesCount > 0,
            ],
            [
                'key' => 'figures_and_images',
                'label' => 'Figures et images mises en flux',
                'count' => $figuresCount,
                'applied' => $figuresCount > 0,
            ],
            [
                'key' => 'captions_detected',
                'label' => 'Légendes détectées et numérotées',
                'count' => $captionsCount,
                'applied' => $captionsCount > 0,
            ],
        ];

        $warnings = [];

        if ($ambiguities !== []) {
            $count = count($ambiguities);
            $warnings[] = [
                'code' => 'ambiguous_blocks',
                'level' => 'warning',
                'title' => 'Passages à valider',
                'message' => sprintf(
                    '%d titre%s présente%s une numérotation ambiguë et mérite%s votre validation.',
                    $count,
                    $count > 1 ? 's' : '',
                    $count > 1 ? 'nt' : '',
                    $count > 1 ? 'nt' : '',
                ),
                'count' => $count,
            ];
        }

        return [
            'document_id' => (string) $document->hash_id,
            'filename' => (string) $document->filename,
            'pipeline' => 'legacy',
            'generated_at' => now()->toIso8601String(),
            'original_url' => $originalUrl,
            'report_download_url' => $reportDownloadUrl,
            'corrections' => $corrections,
            'corrections_totals' => $totals,
            'warnings' => $warnings,
            'has_warnings' => $warnings !== [],
            'compliance' => (new FormattingComplianceService)->evaluate($document),
        ];
    }
}
