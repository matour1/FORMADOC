<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Billing;

use App\Models\User;
use App\Services\Billing\CreditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests du service de crédits (Phase 2 — SaaS).
 *
 * - débit : solde mis à jour + transaction journalisée
 * - débit insuffisant : refusé, aucun solde négatif
 * - crédit : solde augmenté + transaction journalisée
 * - montant invalide (0 / négatif) : refusé
 */
class CreditServiceTest extends TestCase
{
    use RefreshDatabase;

    private CreditService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new CreditService;
    }

    private function makeUser(int $credits = 0): User
    {
        return User::factory()->create(['credits_balance' => $credits]);
    }

    public function test_credit_augmente_le_solde_et_journalise(): void
    {
        $user = $this->makeUser(100);

        $result = $this->service->credit($user, 500, 'purchase', 'REF-1', 'Achat test');

        $this->assertTrue($result['ok']);
        $this->assertSame(600, $result['balance']);
        $this->assertDatabaseHas('credit_transactions', [
            'user_id' => $user->id,
            'type' => 'purchase',
            'amount' => 500,
            'balance_after' => 600,
            'reference' => 'REF-1',
        ]);
    }

    public function test_debit_diminue_le_solde_et_journalise(): void
    {
        $user = $this->makeUser(1000);

        $result = $this->service->debit($user, 300, 'usage', 'CHAT-1', 'Usage chat');

        $this->assertTrue($result['ok']);
        $this->assertSame(700, $result['balance']);
        $this->assertDatabaseHas('credit_transactions', [
            'user_id' => $user->id,
            'type' => 'usage',
            'amount' => -300,
            'balance_after' => 700,
            'reference' => 'CHAT-1',
        ]);
    }

    public function test_debit_insuffisant_refuse_sans_solde_negatif(): void
    {
        $user = $this->makeUser(10);

        $result = $this->service->debit($user, 50, 'usage');

        $this->assertFalse($result['ok']);
        $this->assertSame('insufficient_balance', $result['reason']);
        $this->assertSame(10, $user->fresh()->credits_balance);
        $this->assertDatabaseCount('credit_transactions', 0);
    }

    public function test_debit_montant_zero_ou_negatif_refuse(): void
    {
        $user = $this->makeUser(100);

        $this->assertFalse($this->service->debit($user, 0)['ok']);
        $this->assertFalse($this->service->debit($user, -10)['ok']);
        $this->assertSame(100, $user->fresh()->credits_balance);
    }

    public function test_balance_reflete_le_solde_en_base(): void
    {
        $user = $this->makeUser(250);

        $this->assertSame(250, $this->service->balance($user));
        $this->assertTrue($this->service->hasEnough($user, 250));
        $this->assertFalse($this->service->hasEnough($user, 251));
    }
}
