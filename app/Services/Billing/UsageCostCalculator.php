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
     * Grille tarifaire d'un modèle, ou null s'il est inconnu.
     *
     * **Défaut corrigé ici, et il vidait la facturation.** Le prix était lu avec
     * la notation pointée de Laravel :
     *
     *     config("openrouter.pricing.{$model}")   // ← faux
     *
     * Or Laravel interprète les points comme un CHEMIN IMBRIQUÉ. Les noms de
     * modèles en contiennent (`anthropic/claude-3.5-sonnet`), donc la clé
     * `3.5-sonnet` était cherchée à l'intérieur d'une clé `anthropic/claude-3`,
     * qui n'existe pas. Résultat : `null`.
     *
     * Conséquence mesurée : sur 9 modèles de la grille, 3 renvoyaient `null` —
     * dont `anthropic/claude-3.5-sonnet`, utilisé par les plans standard,
     * premium, pro et enterprise. Le coût de ces appels était calculé à 0, donc
     * enregistré à 0 dans le registre d'usage et facturé 0 à l'utilisateur.
     *
     * Le défaut ne concerne PAS que l'affichage : c'est le coût RÉEL qui était
     * faussé (`OpenRouterService::parseResponse`), et le chat rembourse l'écart
     * entre l'estimation et ce coût réel — donc un coût nul remboursait
     * intégralement le message. Les plans supérieurs étaient gratuits.
     *
     * On lit donc la grille comme un TABLEAU, où le nom du modèle est une clé
     * entière et non un chemin.
     *
     * @return null|array<string, float> null si le modèle est inconnu
     */
    public function pricingFor(string $model): ?array
    {
        $grid = (array) config('openrouter.pricing', []);

        $pricing = $grid[$model] ?? null;

        return is_array($pricing) ? $pricing : null;
    }

    /**
     * Coût en USD d'un appel, ou null si le modèle est inconnu.
     *
     * **`null` et non `0.0` : la distinction est essentielle.** Un modèle inconnu
     * facturé 0 est indiscernable d'un appel gratuit, et le défaut de prix
     * passait justement pour de la gratuité. Les appelants peuvent donc
     * SIGNALER un prix manquant au lieu de le subir silencieusement.
     *
     * @return null|float null si le prix du modèle n'est pas connu
     */
    public function costUsdFor(string $model, int $inputTokens, int $outputTokens): ?float
    {
        $pricing = $this->pricingFor($model);

        if ($pricing === null) {
            return null;
        }

        // Génération d'image : le prix est par image, pas par token.
        if (isset($pricing['image'])) {
            return (float) $pricing['image'] * max(1, $outputTokens);
        }

        if (! isset($pricing['input'], $pricing['output'])) {
            return null;
        }

        return ($inputTokens / 1_000_000) * (float) $pricing['input']
             + ($outputTokens / 1_000_000) * (float) $pricing['output'];
    }

    /**
     * Estime le coût en crédits d'un appel (tokens d'entrée/sortie).
     *
     * @return array{usd: float, credits: int}
     */
    public function estimateCredits(string $model, int $inputTokens, int $outputTokens): array
    {
        $usd = $this->costUsdFor($model, $inputTokens, $outputTokens);

        if ($usd === null) {
            return ['usd' => 0.0, 'credits' => 0];
        }

        return [
            'usd' => round($usd, 6),
            'credits' => $this->usdToCredits($usd),
        ];
    }
}
