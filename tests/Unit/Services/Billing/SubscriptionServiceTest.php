<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Billing;

use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\CreditService;
use App\Services\Billing\InvoiceService;
use App\Services\Billing\KPayService;
use App\Services\Billing\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Tests du service d'abonnements récurrents (Phase 9).
 *
 * Couverture :
 * - status() : actif / inactif
 * - priceInCurrency() / formatPrice() : FCFA, EUR, USD (Q5b)
 * - initiateSubscription() : plan payant → init KPay ; plan gratuit → direct
 * - activateFromWebhook() : création abonnement + facture (Q3a), idempotence
 * - cancelCurrent() : annulation sans prorata / avec prorata (Q2a)
 * - renew() : garde-fous + init KPay (Q1a)
 * - expire() : passage en expired
 * - subscriptionsDueForRenewal() : sélection des abonnements à renouveler
 */
class SubscriptionServiceTest extends TestCase
{
    use RefreshDatabase;

    private SubscriptionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'billing.auto_renew' => true,
            'billing.renew_days_before' => 3,
            'billing.grace_days' => 5,
            'billing.prorata' => true,
            'billing.currencies' => [
                'XAF' => ['symbol' => 'FCFA', 'rate_fcfa' => 1.0, 'decimals' => 0],
                'EUR' => ['symbol' => '€', 'rate_fcfa' => 655.957, 'decimals' => 2],
                'USD' => ['symbol' => '$', 'rate_fcfa' => 620, 'decimals' => 2],
            ],
            'billing.default_currency' => 'XAF',
            'billing.invoice_storage_disk' => 'local',
            'kpay.api_key' => 'kpay_test_key',
            'kpay.secret_key' => 'sk_test_secret',
            'kpay.webhook_secret' => 'whsec_test',
            'kpay.base_url' => 'https://admin.kpay.site/api/v1',
        ]);

        $this->service = new SubscriptionService(
            new KPayService,
            new CreditService,
            new InvoiceService,
        );
    }

    /* ------------------------------------------------------------------
     |  status()
     | ------------------------------------------------------------------ */

    public function test_status_sans_abonnement_inactif(): void
    {
        $user = User::factory()->create();

        $status = $this->service->status($user);

        $this->assertFalse($status['active']);
        $this->assertSame('default', $status['plan']);
    }

    public function test_status_avec_abonnement_actif(): void
    {
        $user = $this->makeUserWithPlan('standard', price: 3000);

        $status = $this->service->status($user);

        $this->assertTrue($status['active']);
        $this->assertSame('standard', $status['plan']);
        $this->assertNotEmpty($status['renew_at']);
        $this->assertInstanceOf(Subscription::class, $status['subscription']);
    }

    /* ------------------------------------------------------------------
     |  priceInCurrency() / formatPrice() (Q5b)
     | ------------------------------------------------------------------ */

    public function test_price_in_currency_fcfa(): void
    {
        $plan = Plan::factory()->create(['price_fcfa' => 5000]);

        $price = $this->service->priceInCurrency($plan, 'XAF');

        $this->assertSame(5000, $price['amount']);
        $this->assertSame('FCFA', $price['symbol']);
        $this->assertSame(0, $price['decimals']);
    }

    public function test_price_in_currency_eur(): void
    {
        // 5000 FCFA / 655.957 = 7,62 € → 762 centimes
        $plan = Plan::factory()->create(['price_fcfa' => 5000]);

        $price = $this->service->priceInCurrency($plan, 'EUR');

        $this->assertSame(762, $price['amount']);
        $this->assertSame('€', $price['symbol']);
        $this->assertSame(2, $price['decimals']);
    }

    public function test_price_in_currency_usd(): void
    {
        // 5000 FCFA / 620 = 8,06 $ → 806 centimes
        $plan = Plan::factory()->create(['price_fcfa' => 5000]);

        $price = $this->service->priceInCurrency($plan, 'USD');

        $this->assertSame(806, $price['amount']);
        $this->assertSame('$', $price['symbol']);
        $this->assertSame(2, $price['decimals']);
    }

    public function test_format_price_fcfa(): void
    {
        $plan = Plan::factory()->create(['price_fcfa' => 13500]);

        $this->assertSame('13 500 FCFA', $this->service->formatPrice($plan, 'XAF'));
    }

    public function test_format_price_eur(): void
    {
        $plan = Plan::factory()->create(['price_fcfa' => 5000]);

        $this->assertSame('7,62 €', $this->service->formatPrice($plan, 'EUR'));
    }

    /* ------------------------------------------------------------------
     |  initiateSubscription()
     | ------------------------------------------------------------------ */

    public function test_initiate_subscription_plan_payant_init_kpay(): void
    {
        Http::fake([
            'https://admin.kpay.site/api/v1/payments/init' => Http::response([
                'gatewayUrl' => 'https://pay.kpay.site/checkout/abc',
                'externalId' => 'SUB-1-xxx',
                'paymentId' => 'pay_123',
            ], 201),
        ]);

        $user = User::factory()->create();
        $plan = Plan::factory()->create(['price_fcfa' => 5000, 'is_active' => true]);

        $result = $this->service->initiateSubscription($user, $plan, 'card', 'XAF');

        $this->assertTrue($result['ok']);
        $this->assertSame('https://pay.kpay.site/checkout/abc', $result['gatewayUrl']);
        $this->assertStringStartsWith('SUB-', $result['externalId']);

        Http::assertSent(function ($request) use ($plan) {
            return str_contains($request->url(), '/payments/init')
                && $request['amount'] === 5000
                && $request['currency'] === 'XAF'
                && $request['metadata']['purpose'] === 'subscription'
                && $request['metadata']['plan_slug'] === $plan->slug;
        });
    }

    public function test_initiate_subscription_plan_payant_kpay_erreur(): void
    {
        Http::fake([
            'https://admin.kpay.site/api/v1/payments/init' => Http::response(['message' => 'Solde insuffisant'], 400),
        ]);

        $user = User::factory()->create();
        $plan = Plan::factory()->create(['price_fcfa' => 5000, 'is_active' => true]);

        $result = $this->service->initiateSubscription($user, $plan, 'kpay', 'XAF');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Solde insuffisant', $result['message']);
    }

    public function test_initiate_subscription_plan_gratuit_activation_directe(): void
    {
        $user = User::factory()->create();
        $plan = Plan::factory()->create(['price_fcfa' => 0, 'slug' => 'gratuit', 'is_active' => true]);

        $result = $this->service->initiateSubscription($user, $plan, 'kpay', 'XAF');

        $this->assertTrue($result['ok']);
        $this->assertArrayHasKey('subscription', $result);
        $this->assertSame('active', $result['subscription']->status);
        $this->assertFalse($result['subscription']->auto_renew);
    }

    public function test_initiate_subscription_plan_inactif_erreur(): void
    {
        $user = User::factory()->create();
        $plan = Plan::factory()->create(['price_fcfa' => 5000, 'is_active' => false]);

        $result = $this->service->initiateSubscription($user, $plan);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('plus disponible', $result['message']);
    }

    /* ------------------------------------------------------------------
     |  activateFromWebhook() (Q3a : facture à la souscription)
     | ------------------------------------------------------------------ */

    public function test_activate_from_webhook_creer_abonnement_et_facture(): void
    {
        $user = User::factory()->create();
        $plan = Plan::factory()->create(['price_fcfa' => 5000, 'slug' => 'standard', 'is_active' => true]);

        $metadata = [
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'purpose' => 'subscription',
            'plan_slug' => 'standard',
            'amount_fcfa' => 5000,
            'payment_method' => 'card',
            'currency' => 'XAF',
            'external_id' => 'SUB-1-abc',
        ];

        $payload = ['paymentId' => 'pay_webhook_1', 'status' => 'completed'];

        $ok = $this->service->activateFromWebhook($payload, $metadata);

        $this->assertTrue($ok);

        $subscription = Subscription::where('user_id', $user->id)->first();
        $this->assertNotNull($subscription);
        $this->assertSame('active', $subscription->status);
        $this->assertSame('standard', $subscription->plan->slug);
        $this->assertSame('card', $subscription->payment_method);
        $this->assertSame('XAF', $subscription->currency);
        $this->assertTrue($subscription->auto_renew);
        $this->assertSame('SUB-1-abc', $subscription->external_id);

        // Facture générée (Q3a)
        $invoice = Invoice::where('user_id', $user->id)->first();
        $this->assertNotNull($invoice);
        $this->assertSame('subscription', $invoice->type);
        $this->assertSame(5000, $invoice->amount);
        $this->assertSame('paid', $invoice->status);
        $this->assertStringStartsWith('INV-', $invoice->number);
    }

    public function test_activate_from_webhook_idempotent(): void
    {
        $user = User::factory()->create();
        $plan = Plan::factory()->create(['price_fcfa' => 5000, 'slug' => 'standard', 'is_active' => true]);

        $metadata = [
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'purpose' => 'subscription',
            'amount_fcfa' => 5000,
            'payment_method' => 'card',
            'currency' => 'XAF',
            'external_id' => 'SUB-1-abc',
        ];

        $payload = ['paymentId' => 'pay_webhook_1', 'status' => 'completed'];

        $this->service->activateFromWebhook($payload, $metadata);
        $this->service->activateFromWebhook($payload, $metadata);

        $this->assertSame(1, Subscription::where('user_id', $user->id)->count());
        $this->assertSame(1, Invoice::where('user_id', $user->id)->count());
    }

    public function test_activate_from_webhook_utilisateur_inconnu_faux(): void
    {
        $plan = Plan::factory()->create(['price_fcfa' => 5000, 'is_active' => true]);

        $ok = $this->service->activateFromWebhook(
            ['paymentId' => 'x'],
            ['user_id' => 99999, 'plan_id' => $plan->id, 'amount_fcfa' => 5000, 'currency' => 'XAF'],
        );

        $this->assertFalse($ok);
    }

    /* ------------------------------------------------------------------
     |  cancelCurrent() (Q2a : prorata)
     | ------------------------------------------------------------------ */

    public function test_cancel_sans_prorata_ne_cree_pas_de_transaction(): void
    {
        $user = $this->makeUserWithPlan('standard', price: 3000);

        $this->service->cancelCurrent($user, refundProrata: false);

        $this->assertSame('cancelled', $user->activeSubscription()->first()->status ?? 'cancelled');
        $this->assertSame(0, $user->creditTransactions()->count());
    }

    public function test_cancel_avec_prorata_credite_les_jours_restants(): void
    {
        // Abonnement de 30 jours commencé il y a 10 jours → ~20 jours restants
        $user = User::factory()->create(['credits_balance' => 0]);
        $plan = Plan::factory()->create(['price_fcfa' => 3000, 'slug' => 'standard', 'is_active' => true]);
        $subscription = Subscription::factory()->create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now()->subDays(10),
            'ends_at' => now()->addDays(20),
        ]);

        $this->service->cancelCurrent($user, refundProrata: true);

        $user->refresh();
        // Prorata calculé par le service : 3000 × (jours restants / 30)
        $remainingDays = (int) now()->diffInDays($subscription->ends_at);
        $expected = (int) round(3000 * ($remainingDays / 30));
        $this->assertSame($expected, $user->credits_balance);
        $this->assertGreaterThan(0, $expected);

        $tx = $user->creditTransactions()->first();
        $this->assertSame('refund', $tx->type);
        $this->assertSame($expected, $tx->amount);
        $this->assertStringContainsString('PRORATA', $tx->reference);
    }

    public function test_cancel_sans_abonnement_retourne_null(): void
    {
        $user = User::factory()->create();

        $this->assertNull($this->service->cancelCurrent($user));
    }

    /* ------------------------------------------------------------------
     |  renew() (Q1a)
     | ------------------------------------------------------------------ */

    public function test_renew_abonnement_non_actif_erreur(): void
    {
        $subscription = Subscription::factory()->create(['status' => 'expired']);

        $result = $this->service->renew($subscription);

        $this->assertFalse($result['ok']);
        $this->assertSame('not_active', $result['reason']);
    }

    public function test_renew_sans_auto_renew_erreur(): void
    {
        $subscription = Subscription::factory()->create([
            'status' => 'active',
            'auto_renew' => false,
        ]);

        $result = $this->service->renew($subscription);

        $this->assertFalse($result['ok']);
        $this->assertSame('auto_renew_disabled', $result['reason']);
    }

    public function test_renew_plan_gratuit_erreur(): void
    {
        $plan = Plan::factory()->create(['price_fcfa' => 0, 'slug' => 'gratuit', 'is_active' => true]);
        $subscription = Subscription::factory()->create([
            'status' => 'active',
            'auto_renew' => true,
            'plan_id' => $plan->id,
        ]);

        $result = $this->service->renew($subscription);

        $this->assertFalse($result['ok']);
        $this->assertSame('free_plan', $result['reason']);
    }

    public function test_renew_initie_le_paiement_kpay(): void
    {
        Http::fake([
            'https://admin.kpay.site/api/v1/payments/init' => Http::response([
                'gatewayUrl' => 'https://pay.kpay.site/checkout/renew',
                'externalId' => 'RENEW-1-abc',
            ], 201),
        ]);

        $plan = Plan::factory()->create(['price_fcfa' => 5000, 'slug' => 'standard', 'is_active' => true]);
        $subscription = Subscription::factory()->create([
            'status' => 'active',
            'auto_renew' => true,
            'plan_id' => $plan->id,
            'ends_at' => now()->addDays(2),
        ]);

        $result = $this->service->renew($subscription);

        $this->assertTrue($result['ok']);
        $this->assertSame('kpay_initiated', $result['reason']);

        $subscription->refresh();
        $this->assertSame('pending', $subscription->last_payment_status);
        $this->assertStringStartsWith('RENEW-', $subscription->external_id);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/payments/init')
                && $request['metadata']['purpose'] === 'subscription_renewal';
        });
    }

    public function test_renew_echec_kpay_passe_en_failed(): void
    {
        Http::fake([
            'https://admin.kpay.site/api/v1/payments/init' => Http::response(['message' => 'KPay down'], 500),
        ]);

        $plan = Plan::factory()->create(['price_fcfa' => 5000, 'slug' => 'standard', 'is_active' => true]);
        $subscription = Subscription::factory()->create([
            'status' => 'active',
            'auto_renew' => true,
            'plan_id' => $plan->id,
        ]);

        $result = $this->service->renew($subscription);

        $this->assertFalse($result['ok']);
        $this->assertSame('kpay_init_failed', $result['reason']);

        $subscription->refresh();
        $this->assertSame('failed', $subscription->last_payment_status);
    }

    /* ------------------------------------------------------------------
     |  expire() / markFailedPayment()
     | ------------------------------------------------------------------ */

    public function test_expire_passe_abonnement_en_expired(): void
    {
        $subscription = Subscription::factory()->create(['status' => 'active', 'auto_renew' => true]);

        $this->service->expire($subscription);

        $subscription->refresh();
        $this->assertSame('expired', $subscription->status);
        $this->assertFalse($subscription->auto_renew);
    }

    public function test_mark_failed_payment_passe_en_past_due(): void
    {
        $subscription = Subscription::factory()->create(['status' => 'active', 'auto_renew' => true]);

        $this->service->markFailedPayment($subscription);

        $subscription->refresh();
        $this->assertSame('past_due', $subscription->status);
        $this->assertSame('failed', $subscription->last_payment_status);
    }

    /* ------------------------------------------------------------------
     |  subscriptionsDueForRenewal()
     | ------------------------------------------------------------------ */

    public function test_subscriptions_due_for_renewal_selectionne_les_bonnes(): void
    {
        $plan = Plan::factory()->create(['price_fcfa' => 5000, 'slug' => 'standard', 'is_active' => true]);

        // À renouveler (≤ 3 jours avant échéance)
        $due = Subscription::factory()->create([
            'status' => 'active',
            'auto_renew' => true,
            'plan_id' => $plan->id,
            'ends_at' => now()->addDays(2),
        ]);
        // À renouveler demain
        Subscription::factory()->create([
            'status' => 'active',
            'auto_renew' => true,
            'plan_id' => $plan->id,
            'ends_at' => now()->addDay(),
        ]);
        // Hors fenêtre (> 3 jours)
        Subscription::factory()->create([
            'status' => 'active',
            'auto_renew' => true,
            'plan_id' => $plan->id,
            'ends_at' => now()->addDays(10),
        ]);
        // Auto-renew désactivé → exclu
        Subscription::factory()->create([
            'status' => 'active',
            'auto_renew' => false,
            'plan_id' => $plan->id,
            'ends_at' => now()->addDay(),
        ]);
        // Non actif → exclu
        Subscription::factory()->create([
            'status' => 'cancelled',
            'auto_renew' => true,
            'plan_id' => $plan->id,
            'ends_at' => now()->addDay(),
        ]);

        $dueSubscriptions = $this->service->subscriptionsDueForRenewal();

        $this->assertCount(2, $dueSubscriptions);
        $this->assertTrue($dueSubscriptions->pluck('id')->contains($due->id));
    }

    /* ------------------------------------------------------------------
     |  Helpers
     | ------------------------------------------------------------------ */

    private function makeUserWithPlan(string $slug, int $price = 3000): User
    {
        $user = User::factory()->create();
        $plan = Plan::factory()->create(['slug' => $slug, 'price_fcfa' => $price, 'is_active' => true]);
        Subscription::factory()->create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'auto_renew' => true,
        ]);

        return $user;
    }
}
