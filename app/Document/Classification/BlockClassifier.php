<?php

declare(strict_types=1);

namespace App\Document\Classification;

use App\Document\Structure\Block;
use App\Document\Structure\BlockCategory;
use App\Document\Structure\StructuralDocument;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Orchestrateur de la classification d'un document structurel.
 *
 * Enchaîne les trois étapes dans l'ordre imposé par la refonte :
 *
 * ```
 * 1. SignalAggregator  (déterministe, gratuit)  → 68 % des blocs classés
 * 2. DetectBlocksTool  (LLM, payant)            → 32 % des blocs restants
 * 3. ClassificationPolicy (règles de décision)  → qui a besoin de quoi
 * ```
 *
 * **Garantie absolue** : la classification ne perd JAMAIS de contenu. Quelle que
 * soit la défaillance (clé absente, API en panne, réponse illisible), le
 * document conserve sa structure déterministe — dégradée mais utilisable.
 */
final class BlockClassifier
{
    public function __construct(
        private readonly SignalAggregator $signals,
        private readonly DetectBlocksTool $detectBlocks,
        private readonly ClassificationPolicy $policy,
    ) {}

    /**
     * Classifie tous les blocs d'un document.
     *
     * @param  StructuralDocument  $document  Document issu du parseur
     * @param  string  $plan  Plan de l'utilisateur (détermine le modèle IA)
     * @param  bool  $aiEnabled  L'utilisateur a-t-il demandé l'assistance IA ?
     * @return array{
     *     document: StructuralDocument,
     *     report: array{
     *         total: int,
     *         auto_applied: int,
     *         ai_consulted: int,
     *         ai_applied: int,
     *         clarification_needed: array<int, string>,
     *         ai_cost_usd: float,
     *         ai_cost_credits: int,
     *         ai_skipped_reason: null|string,
     *         free_ratio: float
     *     }
     * } Le rapport sert à l'interface (blocs à clarifier) et à la facturation.
     */
    public function classify(StructuralDocument $document, string $plan = 'default', bool $aiEnabled = false): array
    {
        $blocks = $document->blocks;

        if ($blocks === []) {
            return [
                'document' => $document,
                'report' => $this->emptyReport(),
            ];
        }

        // --- 1. Évaluation déterministe de TOUS les blocs ---
        $assessments = [];
        $ambiguousBlocks = [];

        foreach ($blocks as $block) {
            $assessment = $this->signals->assess($block);
            $assessments[$block->blockId] = $assessment;

            if ($assessment['needs_ai']) {
                $ambiguousBlocks[] = $block;
            }
        }

        $aiConsulted = 0;
        $aiApplied = 0;
        $aiCostUsd = 0.0;
        $aiCostCredits = 0;
        $aiSkippedReason = null;

        // --- 2. Consultation du modèle, uniquement si nécessaire et autorisé ---
        if ($ambiguousBlocks !== []) {
            if (! $aiEnabled) {
                $aiSkippedReason = 'Assistance IA non demandée par l\'utilisateur.';
            } elseif (! $this->policy->shouldConsultAi(0.0, $aiEnabled)) {
                $aiSkippedReason = 'Plafond budgétaire atteint.';
            } else {
                $aiResult = $this->consultAi($ambiguousBlocks, $plan);

                $aiCostUsd = $aiResult['cost_usd'];
                $aiCostCredits = $aiResult['cost_credits'];
                $aiConsulted = count($ambiguousBlocks);

                foreach ($aiResult['results'] as $blockId => $aiAssessment) {
                    if (! isset($assessments[$blockId])) {
                        continue;
                    }

                    // On ne retient l'avis du modèle que s'il fait MIEUX que le
                    // déterministe : un avis moins confiant n'apporte rien et
                    // ne doit pas dégrader la structure.
                    if (! $this->policy->preferAiOverDeterministic(
                        $aiAssessment['confidence'],
                        $assessments[$blockId]['confidence']
                    )) {
                        continue;
                    }

                    $assessments[$blockId]['type'] = $aiAssessment['type'];
                    $assessments[$blockId]['confidence'] = $aiAssessment['confidence'];
                    $assessments[$blockId]['signals_used'][] = 'ai:'.$aiAssessment['signal'];
                    $assessments[$blockId]['needs_ai'] = false;
                    $assessments[$blockId]['reason'] = 'Classifié par le modèle de langage.';

                    if ($aiAssessment['heading_level'] !== null) {
                        $assessments[$blockId]['heading_level'] = $aiAssessment['heading_level'];
                    }
                    if ($aiAssessment['category'] !== null) {
                        $assessments[$blockId]['category'] = $aiAssessment['category'];
                    }

                    $aiApplied++;
                }
            }
        }

        // --- 3. Application au document + collecte des clarifications ---
        $classifiedBlocks = [];
        $clarifications = [];
        $autoApplied = 0;

        foreach ($blocks as $block) {
            $assessment = $assessments[$block->blockId];
            $classified = $this->applyAssessment($block, $assessment);
            $classifiedBlocks[] = $classified;

            if ($this->policy->canApplyAutomatically($assessment['confidence'])) {
                $autoApplied++;
            } else {
                $clarifications[] = $block->blockId;
            }
        }

        $classifier = new StructuralDocument(
            documentId: $document->documentId,
            sourceType: $document->sourceType,
            blocks: $classifiedBlocks,
            meta: $document->meta,
        );

        $total = count($blocks);

        return [
            'document' => $classifier,
            'report' => [
                'total' => $total,
                'auto_applied' => $autoApplied,
                'ai_consulted' => $aiConsulted,
                'ai_applied' => $aiApplied,
                'clarification_needed' => $clarifications,
                'ai_cost_usd' => round($aiCostUsd, 6),
                'ai_cost_credits' => $aiCostCredits,
                'ai_skipped_reason' => $aiSkippedReason,
                'free_ratio' => $total === 0 ? 0.0 : round($autoApplied / $total, 3),
            ],
        ];
    }

    /**
     * Applique l'évaluation à un bloc, en préservant tout son contenu.
     *
     * @param  array<string, mixed>  $assessment
     */
    private function applyAssessment(Block $block, array $assessment): Block
    {
        $category = $assessment['category'] === null
            ? $block->category
            : BlockCategory::tryFrom((string) $assessment['category']);

        return $block
            ->withClassification(
                type: $assessment['type'],
                confidence: (float) $assessment['confidence'],
                headingLevel: $assessment['heading_level'] ?? $block->headingLevel,
            )
            ->withCategory($category);
    }

    /**
     * Interroge le modèle, en absorbant toute défaillance.
     *
     * @param  array<int, Block>  $blocks
     * @return array{results: array<string, array<string, mixed>>, cost_usd: float, cost_credits: int}
     */
    private function consultAi(array $blocks, string $plan): array
    {
        try {
            return $this->detectBlocks->classify($blocks, $plan);
        } catch (Throwable $e) {
            // Jamais bloquant : le mode déterministe est déjà appliqué, et
            // l'utilisateur ne doit pas voir d'erreur technique.
            Log::warning('Classification IA : échec, structure déterministe conservée', [
                'blocks' => count($blocks),
                'error' => $e->getMessage(),
            ]);

            return ['results' => [], 'cost_usd' => 0.0, 'cost_credits' => 0];
        }
    }

    /**
     * Rapport vide (document sans bloc).
     *
     * @return array<string, mixed>
     */
    private function emptyReport(): array
    {
        return [
            'total' => 0,
            'auto_applied' => 0,
            'ai_consulted' => 0,
            'ai_applied' => 0,
            'clarification_needed' => [],
            'ai_cost_usd' => 0.0,
            'ai_cost_credits' => 0,
            'ai_skipped_reason' => 'Document vide.',
            'free_ratio' => 1.0,
        ];
    }
}
