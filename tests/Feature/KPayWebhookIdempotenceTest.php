<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\KpayPayment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * Tests P0-2 — Idempotence du webhook KPay.
 *
 * Un webhook payment.completed dupliqué (retry KPay, double envoi, etc.)
 * ne doit JAMAIS créditer deux fois le même paiement :
 *   - le premier appel crédite (réponse 'processed')
 *   - les appels suivants, même concurrents, sont détectés par
 *     CreditService::creditIfNotProcessed (vérification + crédit dans la
 *     même transaction, sérialisée par lockForUpdate) → 'already_processed'
 *   - le solde et le nombre de transactions restent identiques
 */
class KPayWebhookIdempotenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Signature HMAC déterministe pour les tests
        Config::set('kpay.webhook_secret', 'test-secret-kpay');
    }

    private function signPayload(string $payload): string
    {
        return hash_hmac('sha256', $payload, config('kpay.webhook_secret'));
    }

    private function webhookPayload(User $user, string $paymentId, int $amount = 1000): array
    {
        return [
            'paymentId' => $paymentId,
            'externalId' => 'CREDIT-'.$user->id.'-test-uuid',
            'status' => 'completed',
            'amount' => $amount,
            'currency' => 'XAF',
            'metadata' => [
                'user_id' => $user->id,
                'purpose' => 'credit_purchase',
                'credits' => $amount,
            ],
        ];
    }

    private function postWebhook(array $payload)
    {
        $raw = json_encode($payload);

        return $this->withHeaders([
            'X-KPAY-Signature' => $this->signPayload($raw),
            'X-KPAY-Event' => 'payment.completed',
        ])->postJson('/kpay/webhook', $payload);
    }

    public function test_webhook_duplique_ne_credite_qu_une_fois(): void
    {
        $user = User::factory()->create(['credits_balance' => 0]);

        $paymentId = 'KPAY-'.$this->makePaymentId();

        // Premier appel : crédit appliqué
        $this->postWebhook($this->webhookPayload($user, $paymentId))
            ->assertOk()
            ->assertJson(['status' => 'processed']);

        $user->refresh();
        $this->assertSame(1000, $user->credits_balance);

        // Deuxième appel (webhook dupliqué / retry KPay)
        $this->postWebhook($this->webhookPayload($user, $paymentId))
            ->assertOk()
            ->assertJson(['status' => 'already_processed']);

        // Troisième appel (triple envoi)
        $this->postWebhook($this->webhookPayload($user, $paymentId))
            ->assertOk()
            ->assertJson(['status' => 'already_processed']);

        $user->refresh();
        $this->assertSame(1000, $user->credits_balance, 'Le solde ne doit jamais doubler.');

        $this->assertSame(1, $user->creditTransactions()->count(), 'Une seule transaction de crédit attendue.');
        $this->assertSame(1, $user->invoices()->count(), 'Une seule facture attendue.');
    }

    public function test_signature_invalide_est_rejetee(): void
    {
        $user = User::factory()->create(['credits_balance' => 0]);

        $payload = $this->webhookPayload($user, 'KPAY-INVALID');

        $this->withHeaders([
            'X-KPAY-Signature' => 'signature-fausse',
            'X-KPAY-Event' => 'payment.completed',
        ])->postJson('/kpay/webhook', $payload)
            ->assertStatus(401);

        $user->refresh();
        $this->assertSame(0, $user->credits_balance, 'Aucun crédit sans signature valide.');
    }

    public function test_status_non_terminal_est_ignore(): void
    {
        $user = User::factory()->create(['credits_balance' => 0]);

        $payload = $this->webhookPayload($user, 'KPAY-PENDING');
        $payload['status'] = 'pending';

        $this->postWebhook($payload)
            ->assertOk()
            ->assertJson(['status' => 'ignored_non_terminal']);

        $user->refresh();
        $this->assertSame(0, $user->credits_balance, 'Un statut non terminal ne crédite pas.');
    }

    public function test_sync_kpay_payments_ne_credite_pas_deux_fois(): void
    {
        // Le fallback (commande artisan) passe aussi par creditIfNotProcessed :
        // on le vérifie ici en exécutant la commande après le webhook.
        $user = User::factory()->create(['credits_balance' => 0]);
        $paymentId = 'KPAY-SYNC-'.$this->makePaymentId();

        // Le registre local est créé par initPurchase (POST /credits/purchase) :
        // on le simule ici pour tester la mise à jour par le webhook.
        KpayPayment::create([
            'user_id' => $user->id,
            'payment_id' => $paymentId,
            'external_id' => 'CREDIT-'.$user->id.'-test-uuid',
            'status' => 'PENDING',
            'purpose' => 'credit_purchase',
            'amount_fcfa' => 1000,
            'currency' => 'XAF',
            'metadata' => [
                'user_id' => $user->id,
                'purpose' => 'credit_purchase',
                'credits' => 1000,
            ],
        ]);

        // 1. Webhook traité
        $this->postWebhook($this->webhookPayload($user, $paymentId))->assertOk();

        // 2. Le registre local a été mis à jour
        $this->assertDatabaseHas('kpay_payments', [
            'external_id' => 'CREDIT-'.$user->id.'-test-uuid',
            'status' => 'COMPLETED',
        ]);

        $user->refresh();
        $this->assertSame(1000, $user->credits_balance);

        // 3. Exécution du fallback de synchronisation — aucune double facture/email
        $this->artisan('kpay:sync')
            ->assertSuccessful();

        $user->refresh();
        $this->assertSame(1000, $user->credits_balance, 'Le fallback ne doit pas re-créditer.');
        $this->assertSame(1, $user->creditTransactions()->count());
        $this->assertSame(1, $user->invoices()->count());
    }

    private function makePaymentId(): string
    {
        return strtoupper(bin2hex(random_bytes(6)));
    }
}
