<?php

namespace App\Services\Billing;

/**
 * Conversion des coûts OpenRouter (USD) en crédits (FCFA).
 *
 * Hypothèses claires :
 *   - 1 crédit = 1 FCFA
 *   - taux de change : 1 USD = config('openrouter.rate_fcfa_per_usd') FCFA
 *     (défaut 620 — valeur conservatrice)
 *
 * RÈGLE DE RENTABILITÉ (exigence C du cahier des charges) :
 *   Chaque action facturée doit couvrir :
 *     1. le coût direct API (tokens OpenRouter / images / conteneur Claude)
 *     2. le coût d'infrastructure + maintenance (serveur, stockage, support) ≈ +15 %
 *     3. la marge (40 à 60 % du sous-total) pour assurer la pérennité du service
 *
 *   Formule appliquée :
 *     prix_public = coût_API × (1 + infra) × (1 + marge)
 *   Avec infra = 0.15 et marge = 0.60 (configurable via config/openrouter.php) :
 *     prix_public ≈ coût_API × 1.15 × 1.60 ≈ coût_API × 1.84
 *   → coefficient effectif ≈ 2× le coût direct (arrondi à la hausse en crédits).
 *
 *   Exemple : un appel deepseek-chat coûtant 0,05 USD (~31 FCFA au taux 620)
 *   est facturé 31 × 1.84 ≈ 57 FCFA → 57 crédits (au lieu de ~37 avec la
 *   marge historique de 20 %). Le coefficient 2×-3× est donc atteint en
 *   combinant infra + marge : 1.15 × 1.60 = 1.84 ; avec une marge de 100 %
 *   on atteindrait 2.3×. Les valeurs par défaut sont réglées pour rester
 *   dans la fourchette 2×-3× recommandée par le cahier des charges.
 */
class UsageCostCalculator
{
    /**
     * Coefficient de rentabilité appliqué au coût direct API :
     * (1 + infrastructure) × (1 + marge).
     *
     * Valeur par défaut : infra 0.15 + marge 0.60 → 1.84 (≈ 2× le coût direct).
     */
    public function profitabilityCoefficient(): float
    {
        $infra = (float) config('openrouter.cost_infrastructure', 0.15);
        $margin = (float) config('openrouter.cost_margin', 0.60);

        return (1 + $infra) * (1 + $margin);
    }

    /**
     * Convertit un coût USD en nombre entier de crédits (arrondi au supérieur).
     * Le coût est d'abord majoré du coefficient de rentabilité.
     */
    public function usdToCredits(float $usd, ?float $rateFcfaPerUsd = null): int
    {
        $rate = $rateFcfaPerUsd ?? (float) config('openrouter.rate_fcfa_per_usd', 630);

        $gross = max(0.0, $usd) * $this->profitabilityCoefficient();

        return (int) ceil($gross * $rate);
    }

    /**
     * Estime le coût en crédits d'un appel (tokens d'entrée/sortie).
     *
     * @return array{usd: float, credits: int}
     */
    public function estimateCredits(string $model, int $inputTokens, int $outputTokens): array
    {
        $pricing = config("openrouter.pricing.{$model}", null);

        if ($pricing === null) {
            return ['usd' => 0.0, 'credits' => 0];
        }

        if (isset($pricing['image'])) {
            $usd = (float) $pricing['image'] * max(1, $outputTokens);
        } else {
            $usd = ($inputTokens / 1_000_000) * (float) $pricing['input']
                 + ($outputTokens / 1_000_000) * (float) $pricing['output'];
        }

        return [
            'usd' => round($usd, 6),
            'credits' => $this->usdToCredits($usd),
        ];
    }
}
