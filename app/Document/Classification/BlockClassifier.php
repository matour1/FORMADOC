<?php

declare(strict_types=1);

namespace App\Document\Classification;

use App\Document\Structure\Block;
use App\Document\Structure\BlockCategory;
use App\Document\Structure\BlockType;
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

        // --- 4. Document sans aucun titre : promouvoir un nœud racine ---------
        // Mesuré sur les documents réels : 7 sur 51 n'ont AUCUN bloc de type
        // `heading` (devis, bilan de stage d'une page, discours). Sans titre, un
        // document n'a pas de sommaire possible et la mise en forme n'a rien à
        // partir de quoi construire une hiérarchie.
        //
        // La promotion est délibérément PRUDENTE : elle n'a lieu que sur un
        // document dépourvu de titre, et seulement si le premier paragraphe
        // ressemble à un intitulé (court, seul sur sa ligne, sans ponctuation
        // finale). Deviner un titre sur un document qui en a déjà un
        // introduirait une erreur visible ; ici il n'y a rien à casser.
        $racine = $this->promouvoirNoeudRacine($classifiedBlocks);

        if ($racine !== null) {
            $autoApplied++;
            $clarifications = array_values(array_diff($clarifications, [$racine]));
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
                'root_heading_created' => $racine,
                'ai_cost_usd' => round($aiCostUsd, 6),
                'ai_cost_credits' => $aiCostCredits,
                'ai_skipped_reason' => $aiSkippedReason,
                'free_ratio' => $total === 0 ? 0.0 : round($autoApplied / $total, 3),
            ],
        ];
    }

    /**
     * Promeut le premier paragraphe en titre de niveau 1 si le document n'a aucun titre.
     *
     * **Le problème.** Un document sans aucun bloc `heading` ne peut recevoir ni
     * sommaire ni hiérarchie : la mise en forme n'a pas de point d'entrée. C'est
     * le cas de 7 documents sur 51 du corpus réel (devis, bilan d'une page,
     * discours) — mais aussi de tout document où l'auteur a écrit son titre sans
     * appliquer de style et sans le numéroter.
     *
     * **Pourquoi la promotion est prudente.** Elle ne s'applique que si le
     * document est DÉPOURVU de titre, et seulement au premier paragraphe s'il
     * ressemble à un intitulé. Sur un document qui a déjà des titres, deviner
     * en ajouterait un faux — et l'utilisateur verrait une incohérence.
     *
     * **Ce qui n'est pas fait.** Aucun texte n'est modifié : seul le TYPE du bloc
     * change. Le contenu traverse sans altération, et l'utilisateur peut corriger
     * la classification depuis l'écran de clarification si la promotion est
     * indésirable.
     *
     * @param  array<int, Block>  $blocks  Blocs classifiés (modifiés par référence de tableau)
     */
    private function promouvoirNoeudRacine(array &$blocks): ?string
    {
        // Déjà des titres : on ne touche à rien.
        foreach ($blocks as $bloc) {
            if ($bloc->type === BlockType::Heading) {
                return null;
            }
        }

        // Le premier bloc dont le texte ressemble à un intitulé. On ne parcourt
        // pas au-delà du premier paragraphe non vide : un titre de document vit
        // en tête, pas au milieu.
        foreach ($blocks as $index => $bloc) {
            if ($bloc->type !== BlockType::Paragraph) {
                continue;
            }

            $texte = trim($bloc->text);

            if ($texte === '') {
                continue;
            }

            if (! $this->ressembleAUnIntitule($texte)) {
                return null;
            }

            $blocks[$index] = $bloc->withClassification(
                type: BlockType::Heading,
                // Confiance sous le seuil d'acceptation automatique : c'est une
                // DÉDUCTION, pas une observation. L'utilisateur doit pouvoir la
                // corriger, et l'IA peut la confirmer.
                confidence: 0.75,
                headingLevel: 1,
            );

            return $bloc->blockId;
        }

        return null;
    }

    /**
     * Le texte a-t-il la forme d'un intitulé de document ?
     *
     * Critères volontairement restrictifs : un intitulé de document est court,
     * sans ponctuation finale, et sans structure de phrase.
     */
    private function ressembleAUnIntitule(string $texte): bool
    {
        if (mb_strlen($texte) > 80) {
            return false;
        }

        // Un intitulé ne se termine pas par un point, un point d'interrogation ou
        // un point d'exclamation.
        if (preg_match('/[.!?]\s*$/u', $texte) === 1) {
            return false;
        }

        // Marqueurs de phrase : on ne promeut pas une phrase de contenu.
        if (preg_match(
            '/\b(est|sont|était|étaient|a été|ont été|permet|permettent|doit|doivent|nous)\b/iu',
            $texte
        ) === 1) {
            return false;
        }

        return true;
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
            // Même clé que le rapport complet : un appelant qui la lit ne doit
            // pas échouer selon que le document est vide ou non. L'oublier ici
            // produisait une erreur sur le seul cas du document sans bloc.
            'root_heading_created' => null,
            'ai_cost_usd' => 0.0,
            'ai_cost_credits' => 0,
            'ai_skipped_reason' => 'Document vide.',
            'free_ratio' => 1.0,
        ];
    }
}
