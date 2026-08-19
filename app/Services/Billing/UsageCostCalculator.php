<?php

namespace App\Services\Billing;

/**
 * Conversion des coûts OpenRouter (USD) en crédits (FCFA).
 *
 * Hypothèses claires :
 *   - 1 crédit = 1 FCFA
 *   - taux de change : 1 USD = config('openrouter.rate_fcfa_per_usd') FCFA
 *     (défaut 620 — valeur conservatrice)
 *   - marge de sécurité : config('openrouter.cost_margin') = +20 % sur le
 *     coût brut pour couvrir frais, échecs partiels et volatilité
 */
class UsageCostCalculator
{
    /**
     * Convertit un coût USD en nombre entier de crédits (arrondi au supérieur).
     */
    public function usdToCredits(float $usd, ?float $rateFcfaPerUsd = null): int
    {
        $rate = $rateFcfaPerUsd ?? (float) config('openrouter.rate_fcfa_per_usd', 620);

        return (int) ceil(max(0.0, $usd) * $rate);
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

        // Marge de sécurité
        $usd *= (1 + (float) config('openrouter.cost_margin', 0.20));

        return [
            'usd' => round($usd, 6),
            'credits' => $this->usdToCredits($usd),
        ];
    }
}
