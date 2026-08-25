<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Billing;

use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Tests du service de facturation (Phase 9 — Q3a).
 *
 * Couverture :
 * - nextNumber() : séquence INV-AAAA-XXXXXX
 * - createForSubscription() : facture d'abonnement (période, plan)
 * - createForCreditPurchase() : facture d'achat de crédits
 * - generatePdf() : stockage PDF + metadata pdf_path (fallback Word si dompdf absent)
 * - formattedAmount() : FCFA entier, EUR/USD décimal
 */
class InvoiceServiceTest extends TestCase
{
    use RefreshDatabase;

    private InvoiceService $service;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'billing.invoice_storage_disk' => 'local',
        ]);

        $this->service = new InvoiceService();
    }

    /* ------------------------------------------------------------------
     |  nextNumber()
     | ------------------------------------------------------------------ */

    public function test_next_number_format_inv_annee_6_chiffres(): void
    {
        $number = $this->service->nextNumber();

        $this->assertMatchesRegularExpression('/^INV-'.now()->year.'-\d{6}$/', $number);
    }

    public function test_next_number_sequence_croissante(): void
    {
        $user = User::factory()->create();

        // Première facture via le service (consomme le numéro 000001) —
        // le flux réel passe par la séquence (P2-5).
        $this->service->createForCreditPurchase(
            user: $user,
            amount: 3000,
            currency: 'XAF',
            reference: 'pay_1',
        );

        $number = $this->service->nextNumber();

        $this->assertSame('INV-'.now()->year.'-000002', $number);
    }

    public function test_next_number_est_atomique_meme_sans_creation_de_facture(): void
    {
        // P2-5 : deux appels successifs SANS créer de facture entre eux
        // doivent produire des numéros distincts (avant : count()+1 donnait
        // toujours le même numéro tant qu'aucune facture n'était créée).
        $a = $this->service->nextNumber();
        $b = $this->service->nextNumber();

        $this->assertNotSame($a, $b, 'Deux appels successifs doivent produire des numéros distincts.');
        $this->assertSame('INV-'.now()->year.'-000001', $a);
        $this->assertSame('INV-'.now()->year.'-000002', $b);
    }

    public function test_next_number_continue_apres_les_factures_existantes(): void
    {
        // Le backfill (migration) initialise la séquence au max existant :
        // nextNumber() ne doit JAMAIS réutiliser un numéro déjà émis.
        $user = User::factory()->create();

        Invoice::factory()->create([
            'user_id' => $user->id,
            'number' => 'INV-'.now()->year.'-000042',
        ]);

        // Simule la séquence déjà backfillée à 42
        DB::table('invoice_sequences')->updateOrInsert(
            ['year' => (int) now()->format('Y')],
            ['last_number' => 42, 'updated_at' => now()]
        );

        $this->assertSame('INV-'.now()->year.'-000043', $this->service->nextNumber());
    }

    public function test_next_number_se_quence_separement_par_annee(): void
    {
        // Chaque année a sa propre séquence (INV-2025-XXXXXX et INV-2026-XXXXXX
        // repartent de 1) — pas de collision entre années.
        $number2025 = $this->service->nextNumber(new \DateTimeImmutable('2025-06-01'));
        $this->assertSame('INV-2025-000001', $number2025);

        $number2026a = $this->service->nextNumber(new \DateTimeImmutable('2026-06-01'));
        $number2026b = $this->service->nextNumber(new \DateTimeImmutable('2026-06-02'));
        $this->assertSame('INV-2026-000001', $number2026a);
        $this->assertSame('INV-2026-000002', $number2026b);
    }

    /* ------------------------------------------------------------------
     |  createForSubscription()
     | ------------------------------------------------------------------ */

    public function test_create_for_subscription(): void
    {
        $user = User::factory()->create();
        $plan = Plan::factory()->create(['price_fcfa' => 5000, 'name' => 'Standard']);
        $subscription = Subscription::factory()->create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'payment_method' => 'card',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
        ]);

        $invoice = $this->service->createForSubscription(
            $user,
            $subscription,
            amount: 5000,
            currency: 'XAF',
            reference: 'pay_123',
        );

        $this->assertInstanceOf(Invoice::class, $invoice);
        $this->assertSame('subscription', $invoice->type);
        $this->assertSame(5000, $invoice->amount);
        $this->assertSame('XAF', $invoice->currency);
        $this->assertSame('paid', $invoice->status);
        $this->assertSame('card', $invoice->payment_method);
        $this->assertSame('pay_123', $invoice->reference);
        $this->assertSame($plan->id, $invoice->plan_id);
        $this->assertSame($subscription->id, $invoice->subscription_id);
        $this->assertNotNull($invoice->paid_at);
        $this->assertNotNull($invoice->period_start);
        $this->assertNotNull($invoice->period_end);
        $this->assertStringStartsWith('INV-', $invoice->number);
    }

    /* ------------------------------------------------------------------
     |  createForCreditPurchase()
     | ------------------------------------------------------------------ */

    public function test_create_for_credit_purchase(): void
    {
        $user = User::factory()->create();

        $invoice = $this->service->createForCreditPurchase(
            $user,
            amount: 2000,
            currency: 'XAF',
            reference: 'CREDIT-abc',
        );

        $this->assertInstanceOf(Invoice::class, $invoice);
        $this->assertSame('credit_purchase', $invoice->type);
        $this->assertSame(2000, $invoice->amount);
        $this->assertNull($invoice->plan_id);
        $this->assertNull($invoice->subscription_id);
        $this->assertSame('kpay', $invoice->payment_method);
    }

    /* ------------------------------------------------------------------
     |  generatePdf()
     | ------------------------------------------------------------------ */

    public function test_generate_pdf_stocke_le_fichier(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $plan = Plan::factory()->create(['name' => 'Standard']);
        $invoice = Invoice::factory()->create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'type' => 'subscription',
            'amount' => 5000,
            'currency' => 'XAF',
            'number' => 'INV-'.now()->year.'-000042',
            'metadata' => [],
        ]);

        $path = $this->service->generatePdf($invoice, $user, $plan);

        $this->assertStringStartsWith('invoices/', $path);
        Storage::disk('local')->assertExists($path);

        $invoice->refresh();
        $this->assertSame($path, $invoice->metadata['pdf_path'] ?? null);
    }

    /* ------------------------------------------------------------------
     |  formattedAmount() (Q5b — affichage devises)
     | ------------------------------------------------------------------ */

    public function test_formatted_amount_fcfa_entier(): void
    {
        $invoice = Invoice::factory()->create([
            'amount' => 5000,
            'currency' => 'XAF',
        ]);

        $this->assertSame('5 000 FCFA', $invoice->formattedAmount());
    }

    public function test_formatted_amount_eur_decimal(): void
    {
        $invoice = Invoice::factory()->create([
            'amount' => 762,
            'currency' => 'EUR',
        ]);

        $this->assertSame('7,62 €', $invoice->formattedAmount());
    }

    public function test_formatted_amount_usd_decimal(): void
    {
        $invoice = Invoice::factory()->create([
            'amount' => 806,
            'currency' => 'USD',
        ]);

        $this->assertSame('8,06 $', $invoice->formattedAmount());
    }
}
