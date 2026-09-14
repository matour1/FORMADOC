<?php

declare(strict_types=1);

namespace App\Document\Classification;

use App\Document\Structure\Block;
use App\Document\Structure\Fidelity;

/**
 * Règles de décision de la classification (REFONTE_ARCHITECTURE.md §7).
 *
 * Point de passage unique qui traduit les seuils de la spécification en
 * décisions concrètes. Centraliser ces règles évite qu'elles se dispersent
 * dans le code avec des valeurs légèrement divergentes.
 */
final class ClassificationPolicy
{
    /**
     * La classification est-elle applicable sans consulter l'utilisateur ?
     *
     * `confidence ≥ 0,85` → appliquée automatiquement (§7).
     */
    public function canApplyAutomatically(float $confidence): bool
    {
        return $confidence >= (float) config('document.confidence_threshold', Block::AUTO_ACCEPT_THRESHOLD);
    }

    /**
     * Le bloc exige-t-il une clarification ciblée auprès de l'utilisateur ?
     *
     * `confidence < 0,85` → `ask_user_clarification`, **sur ce bloc précis**,
     * jamais sur tout le document (§7).
     */
    public function requiresClarification(float $confidence): bool
    {
        return ! $this->canApplyAutomatically($confidence);
    }

    /**
     * Le bloc exige-t-il un aperçu avant/après systématique ?
     *
     * Cas du PDF scanné : l'incertitude vient de la **source** (reconstruction
     * OCR), pas de la classification. La validation est donc exigée même quand
     * la confiance est élevée (§7).
     */
    public function requiresVisualReview(Fidelity $fidelity): bool
    {
        if (! config('document.require_visual_review_for_reconstructed', true)) {
            return false;
        }

        return $fidelity->requiresUserValidation();
    }

    /**
     * Le bloc doit-il être soumis au modèle de langage ?
     *
     * Réponse vraie seulement si la classification déterministe n'a pas suffi :
     * c'est le mécanisme qui garantit l'économie de tokens.
     *
     * @param  float  $deterministicConfidence  Confiance issue du SignalAggregator
     * @param  bool  $aiEnabled  L'utilisateur a-t-il activé l'assistance IA ?
     * @param  int  $alreadySpentCredits  Crédits déjà consommés pour ce document
     */
    public function shouldConsultAi(
        float $deterministicConfidence,
        bool $aiEnabled,
        int $alreadySpentCredits = 0,
    ): bool {
        // L'IA reste OPTIONNELLE : sans activation explicite, le pipeline est
        // 100 % déterministe et gratuit.
        if (! $aiEnabled) {
            return false;
        }

        if ($this->canApplyAutomatically($deterministicConfidence)) {
            return false;
        }

        // Plafond budgétaire par document (décision D4) : au-delà, on arrête
        // les appels et la structure déterministe est conservée telle quelle.
        $maxCredits = (int) config('document.classification_budget.max_credits_per_document', 5);

        return $alreadySpentCredits < $maxCredits;
    }

    /**
     * La classification du modèle est-elle retenue au détriment du déterministe ?
     *
     * On n'écrase le déterministe que si le modèle fait MIEUX : une réponse
     * moins confiante que ce qu'on savait déjà n'apporte rien et ne doit pas
     * dégrader la structure.
     */
    public function preferAiOverDeterministic(float $aiConfidence, float $deterministicConfidence): bool
    {
        return $aiConfidence > $deterministicConfidence;
    }

    /**
     * Combien de blocs peuvent encore être envoyés au modèle ?
     *
     * @param  int  $ambiguousCount  Nombre de blocs ambigus détectés
     * @param  int  $spentCredits  Crédits déjà consommés
     */
    public function remainingAiAllowance(int $ambiguousCount, int $spentCredits = 0): int
    {
        $maxCredits = (int) config('document.classification_budget.max_credits_per_document', 5);

        if ($spentCredits >= $maxCredits) {
            return 0;
        }

        return $ambiguousCount;
    }
}
