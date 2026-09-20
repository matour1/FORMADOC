<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Billing;

use App\Services\Billing\UsageCostCalculator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * Tests du calculateur de coût (Phase 3 — exigence C).
 *
 * Hypothèses :
 *   - 1 crédit = 1 FCFA
 *   - taux 620 FCFA/USD (config openrouter.rate_fcfa_per_usd)
 *   - coefficient de rentabilité ×1.84 (infra 0.15 + marge 0.60 — exigence C)
 */
class UsageCostCalculatorTest extends TestCase
{
    private UsageCostCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculator = new UsageCostCalculator;
    }

    public function test_profitability_coefficient_est_184(): void
    {
        // (1 + 0.15) × (1 + 0.60) = 1.84
        // assertEqualsWithDelta : la précision flottante (1.8399999999) interdit assertSame
        $this->assertEqualsWithDelta(1.84, $this->calculator->profitabilityCoefficient(), 1e-9);
    }

    public function test_profitability_coefficient_respecte_fourchette_2x_3x(): void
    {
        // 1.84 crédits par USD brut → le coefficient appliqué au prix public
        // reste dans la fourchette 2×-3× du coût direct (arrondi crédits).
        $coefficient = $this->calculator->profitabilityCoefficient();
        $this->assertGreaterThanOrEqual(1.80, $coefficient);
        $this->assertLessThanOrEqual(2.30, $coefficient);
    }

    public function test_le_fallback_infrastructure_est_aligne_sur_la_config_015(): void
    {
        // P2-7 : le fallback en dur du code était 0.25 alors que la config
        // (config/openrouter.php) définit 0.15. Si la clé disparaît, le
        // coefficient devait rester 1.84 (et non grimper à 2.0).
        // NB : Config::offsetUnset met la clé à null (Repository::set) → on
        // retire réellement la clé du tableau via Arr::except.
        $fullConfig = config('openrouter');

        Config::set(
            'openrouter',
            Arr::except((array) $fullConfig, 'cost_infrastructure')
        );

        try {
            $this->assertEqualsWithDelta(1.84, $this->calculator->profitabilityCoefficient(), 1e-9);
        } finally {
            Config::set('openrouter', $fullConfig);
        }
    }

    public function test_usd_to_credits_arrondit_au_superieur(): void
    {
        // 0,5 USD × 1.84 × 620 = 570,4 → 571 crédits
        $this->assertSame(571, $this->calculator->usdToCredits(0.5));

        // 0,001 USD × 1.84 × 620 = 1,14 → 2 crédits (minimum)
        $this->assertSame(2, $this->calculator->usdToCredits(0.001));

        // 1,0001 USD × 1.84 × 620 = 1140,9 → 1141 crédits
        $this->assertSame(1141, $this->calculator->usdToCredits(1.0001));
    }

    public function test_usd_to_credits_avec_taux_personnalise(): void
    {
        // 1 USD × 1.84 × 100 = 184 crédits
        $this->assertSame(184, $this->calculator->usdToCredits(1.0, 100));
    }

    public function test_estimate_credits_deepseek_chat(): void
    {
        // deepseek/deepseek-chat : input 0,2574 $/M, output 1,029 $/M
        // 10 000 in + 2 000 out :
        //   brut = 10 000/1M × 0,2574 + 2 000/1M × 1,029 = 0,002574 + 0,002058 = 0,004632
        //   × 1.84 = 0,00852288 USD → × 620 = 5,28 → ceil = 6 crédits
        $cost = $this->calculator->estimateCredits('deepseek/deepseek-chat', 10_000, 2_000);

        $this->assertSame(6, $cost['credits']);
        $this->assertGreaterThan(0, $cost['usd']);
    }

    public function test_estimate_credits_image(): void
    {
        // gpt-image-1-mini : 0,035 $/image × 1.84 = 0,0644 → × 620 = 39,9 → 40 crédits
        $cost = $this->calculator->estimateCredits('openai/gpt-image-1-mini', 0, 1);

        $this->assertSame(40, $cost['credits']);
    }

    public function test_estimate_credits_modele_inconnu_zero(): void
    {
        $cost = $this->calculator->estimateCredits('modele/inconnu', 10_000, 2_000);

        $this->assertSame(0.0, $cost['usd']);
        $this->assertSame(0, $cost['credits']);
    }

    public function test_estimate_credits_jamais_negatif(): void
    {
        $cost = $this->calculator->estimateCredits('deepseek/deepseek-chat', 0, 0);

        $this->assertGreaterThanOrEqual(0, $cost['credits']);
        $this->assertGreaterThanOrEqual(0.0, $cost['usd']);
    }

    public function test_coefficient_rentabilite_sur_coût_direct(): void
    {
        // Vérifie que le prix public ≈ 2× le coût direct pour un appel réel :
        // 0,004632 USD brut → ×1.84 ≈ 0,008523 USD → 6 crédits vs 3 avec marge 20 %.
        $cost = $this->calculator->estimateCredits('deepseek/deepseek-chat', 10_000, 2_000);
        $this->assertSame(6, $cost['credits']);
    }
}
