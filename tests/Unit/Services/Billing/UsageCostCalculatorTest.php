<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Billing;

use App\Services\Billing\UsageCostCalculator;
use Tests\TestCase;

/**
 * Tests du calculateur de coût (Phase 2 — SaaS).
 *
 * Hypothèses :
 *   - 1 crédit = 1 FCFA
 *   - taux 620 FCFA/USD (config openrouter.rate_fcfa_per_usd)
 *   - marge 20 % (config openrouter.cost_margin)
 */
class UsageCostCalculatorTest extends TestCase
{
    private UsageCostCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculator = new UsageCostCalculator();
    }

    public function test_usd_to_credits_arrondit_au_superieur(): void
    {
        // 0,5 USD × 620 = 310 → 310 crédits
        $this->assertSame(310, $this->calculator->usdToCredits(0.5));

        // 0,001 USD × 620 = 0,62 → 1 crédit (minimum)
        $this->assertSame(1, $this->calculator->usdToCredits(0.001));

        // 1,0001 USD × 620 = 620,06 → 621 crédits
        $this->assertSame(621, $this->calculator->usdToCredits(1.0001));
    }

    public function test_estimate_credits_deepseek_chat(): void
    {
        // deepseek/deepseek-chat : input 0,2574 $/M, output 1,029 $/M
        // 10 000 in + 2 000 out :
        //   brut = 10 000/1M × 0,2574 + 2 000/1M × 1,029 = 0,002574 + 0,002058 = 0,004632
        //   marge 20 % → 0,0055584 USD → × 620 = 3,446 → ceil = 4 crédits
        $cost = $this->calculator->estimateCredits('deepseek/deepseek-chat', 10_000, 2_000);

        $this->assertSame(4, $cost['credits']);
        $this->assertGreaterThan(0, $cost['usd']);
    }

    public function test_estimate_credits_image(): void
    {
        // gpt-image-1-mini : 0,035 $/image, marge 20 % → 0,042 → × 620 = 26,04 → 27 crédits
        $cost = $this->calculator->estimateCredits('openai/gpt-image-1-mini', 0, 1);

        $this->assertSame(27, $cost['credits']);
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
}
