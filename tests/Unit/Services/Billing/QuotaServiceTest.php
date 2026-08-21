<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Billing;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\QuotaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests du service de quotas mensuels par plan (exigence D).
 *
 * Barème :
 *   - Gratuit (default) : 5 docs déterministes / 0 IA
 *   - Standard          : 10 docs déterministes / 5 IA
 *   - Premium           : 30 docs déterministes / 15 IA
 *   - Pro               : illimité (null) / 90 IA
 *   - Entreprises       : illimité / illimité
 *
 * Règles :
 *   - quota null = illimité
 *   - reset mensuel automatique au premier usage du nouveau mois
 *   - l'IA est optionnelle : déterministe toujours disponible
 */
class QuotaServiceTest extends TestCase
{
    use RefreshDatabase;

    private QuotaService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new QuotaService();
    }

    private function makeUser(int $usedDet = 0, int $usedAi = 0, ?string $month = null): User
    {
        return User::factory()->create([
            'usage_deterministic_month' => $usedDet,
            'usage_ai_month' => $usedAi,
            'usage_month' => $month ?? now()->format('Y-m'),
        ]);
    }

    /**
     * Barème des quotas par slug (miroir de PlanSeeder).
     */
    private const QUOTAS_BY_SLUG = [
        'standard' => ['det' => 10, 'ai' => 5],
        'premium' => ['det' => 30, 'ai' => 15],
        'pro' => ['det' => null, 'ai' => 90],
        'enterprise' => ['det' => null, 'ai' => null],
    ];

    private function subscribe(User $user, string $slug): Plan
    {
        $quotas = self::QUOTAS_BY_SLUG[$slug] ?? ['det' => 5, 'ai' => 0];

        $plan = Plan::factory()->create([
            'slug' => $slug,
            'is_active' => true,
            'quota_deterministic' => $quotas['det'],
            'quota_ai' => $quotas['ai'],
        ]);

        Subscription::factory()->create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => 'active',
        ]);

        return $plan;
    }

    /*
    |--------------------------------------------------------------------------
    | Plan gratuit (default) — pas d'abonnement
    |--------------------------------------------------------------------------
    */

    public function test_gratuit_5_deterministes_0_ia(): void
    {
        $user = $this->makeUser();

        $this->assertTrue($this->service->canUse($user, 'deterministic'));
        $this->assertFalse($this->service->canUse($user, 'ai'));
    }

    public function test_gratuit_quota_deterministe_epuise_apres_5(): void
    {
        $user = $this->makeUser(usedDet: 5);

        $this->assertFalse($this->service->canUse($user, 'deterministic'));

        $result = $this->service->consume($user, 'deterministic');
        $this->assertFalse($result['ok']);
        $this->assertSame('quota_exhausted', $result['reason']);
        $this->assertSame(5, $result['used']);
        $this->assertSame(5, $result['quota']);
    }

    public function test_gratuit_consomme_deterministe(): void
    {
        $user = $this->makeUser();

        $result = $this->service->consume($user, 'deterministic');

        $this->assertTrue($result['ok']);
        $this->assertSame(1, $result['used']);
        $this->assertSame(5, $result['quota']);
        $this->assertSame(1, $user->fresh()->usage_deterministic_month);
    }

    /*
    |--------------------------------------------------------------------------
    | Plans avec abonnement
    |--------------------------------------------------------------------------
    */

    public function test_standard_quotas_10_5(): void
    {
        $user = $this->makeUser();
        $this->subscribe($user, 'standard');

        $this->assertTrue($this->service->canUse($user, 'deterministic'));
        $this->assertTrue($this->service->canUse($user, 'ai'));
    }

    public function test_standard_ia_epuise_apres_5(): void
    {
        $user = $this->makeUser(usedAi: 5);
        $this->subscribe($user, 'standard');

        $this->assertFalse($this->service->canUse($user, 'ai'));

        $result = $this->service->consume($user, 'ai');
        $this->assertFalse($result['ok']);
        $this->assertSame('quota_exhausted', $result['reason']);
    }

    public function test_premium_quotas_30_15(): void
    {
        $user = $this->makeUser();
        $this->subscribe($user, 'premium');

        $this->assertSame(30, $this->service->status($user)['deterministic']['quota']);
        $this->assertSame(15, $this->service->status($user)['ai']['quota']);
    }

    public function test_pro_deterministe_illimite_ia_90(): void
    {
        $user = $this->makeUser(usedDet: 999);
        $this->subscribe($user, 'pro');

        // Illimité côté déterministe
        $this->assertTrue($this->service->canUse($user, 'deterministic'));
        $status = $this->service->status($user);
        $this->assertTrue($status['deterministic']['unlimited']);
        $this->assertNull($status['deterministic']['quota']);

        // IA bornée à 90
        $this->assertSame(90, $status['ai']['quota']);
    }

    public function test_entreprise_illimite_partout(): void
    {
        $user = $this->makeUser(usedDet: 100, usedAi: 100);
        $this->subscribe($user, 'enterprise');

        $this->assertTrue($this->service->canUse($user, 'deterministic'));
        $this->assertTrue($this->service->canUse($user, 'ai'));
    }

    /*
    |--------------------------------------------------------------------------
    | Reset mensuel
    |--------------------------------------------------------------------------
    */

    public function test_reset_mensuel_reinitialise_les_compteurs(): void
    {
        // Usage du mois précédent (compteurs pleins)
        $user = $this->makeUser(usedDet: 5, usedAi: 2, month: now()->subMonth()->format('Y-m'));

        // Le premier usage du nouveau mois doit réinitialiser
        $this->assertTrue($this->service->canUse($user, 'deterministic'));

        $fresh = $user->fresh();
        $this->assertSame(now()->format('Y-m'), $fresh->usage_month);
        $this->assertSame(0, $fresh->usage_deterministic_month);
        $this->assertSame(0, $fresh->usage_ai_month);
    }

    public function test_reset_mensuel_ne_touche_pas_le_mois_courant(): void
    {
        $user = $this->makeUser(usedDet: 2, usedAi: 1);

        $this->service->canUse($user, 'deterministic');

        $fresh = $user->fresh();
        $this->assertSame(2, $fresh->usage_deterministic_month);
        $this->assertSame(1, $fresh->usage_ai_month);
    }

    /*
    |--------------------------------------------------------------------------
    | Consommation & remboursement
    |--------------------------------------------------------------------------
    */

    public function test_consume_ia_verrouille_et_incremente(): void
    {
        $user = $this->makeUser();
        $this->subscribe($user, 'standard');

        $result = $this->service->consume($user, 'ai');

        $this->assertTrue($result['ok']);
        $this->assertSame(1, $result['used']);
        $this->assertSame(1, $user->fresh()->usage_ai_month);
    }

    public function test_refund_decremente_sans_passer_en_negatif(): void
    {
        $user = $this->makeUser(usedDet: 1, usedAi: 0);

        $this->service->refund($user, 'deterministic');
        $this->assertSame(0, $user->fresh()->usage_deterministic_month);

        // Rembourser à zéro ne doit pas descendre en négatif
        $this->service->refund($user, 'deterministic');
        $this->assertSame(0, $user->fresh()->usage_deterministic_month);
    }

    /*
    |--------------------------------------------------------------------------
    | Statut (affichage compte)
    |--------------------------------------------------------------------------
    */

    public function test_status_affiche_used_remaining_et_unlimited(): void
    {
        $user = $this->makeUser(usedDet: 2, usedAi: 1);
        $this->subscribe($user, 'standard');

        $status = $this->service->status($user);

        $this->assertSame(now()->format('Y-m'), $status['month']);
        $this->assertSame(2, $status['deterministic']['used']);
        $this->assertSame(10, $status['deterministic']['quota']);
        $this->assertSame(8, $status['deterministic']['remaining']);
        $this->assertFalse($status['deterministic']['unlimited']);
        $this->assertSame(1, $status['ai']['used']);
        $this->assertSame(4, $status['ai']['remaining']);
    }

    public function test_status_gratuit_ia_epuise(): void
    {
        $user = $this->makeUser();

        $status = $this->service->status($user);

        $this->assertSame(0, $status['ai']['quota']);
        $this->assertSame(0, $status['ai']['remaining']);
        $this->assertFalse($status['ai']['unlimited']);
    }

    /*
    |--------------------------------------------------------------------------
    | IA optionnelle — le déterministe reste dispo même IA épuisée
    |--------------------------------------------------------------------------
    */

    public function test_deterministe_disponible_quand_ia_epuisee(): void
    {
        $user = $this->makeUser(usedAi: 5);
        $this->subscribe($user, 'standard');

        $this->assertFalse($this->service->canUse($user, 'ai'));
        $this->assertTrue($this->service->canUse($user, 'deterministic'));
    }
}
