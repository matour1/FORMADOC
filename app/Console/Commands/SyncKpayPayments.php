<?php

namespace App\Console\Commands;

use App\Mail\PaymentReceipt;
use App\Models\CreditTransaction;
use App\Models\KpayPayment;
use App\Services\Billing\CreditService;
use App\Services\Billing\InvoiceService;
use App\Services\Billing\KPayService;
use App\Services\Billing\SubscriptionService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

#[Signature('kpay:sync {--force : Re-synchronise aussi les paiements déjà terminés}')]
#[Description('Synchronise les paiements KPay en attente via GET /api/v1/payments/:id (fallback si le webhook n\'a pas été délivré)')]
class SyncKpayPayments extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(KPayService $kpay, CreditService $credits, InvoiceService $invoiceService, SubscriptionService $subscriptions): int
    {
        $query = KpayPayment::pending();

        if (! $this->option('force')) {
            // Ne retenter que les paiements pas encore synchronisés ou en échec
            $query->where(function ($q) {
                $q->whereNull('last_synced_at')
                    ->orWhere('sync_attempts', '<', 5);
            });
        }

        $payments = $query->orderBy('id')->get();

        $this->info(sprintf('Paiements KPay à synchroniser : %d', $payments->count()));

        $processed = 0;
        $failed = 0;

        foreach ($payments as $payment) {
            $detail = $kpay->getPayment((string) $payment->payment_id);

            if ($detail === null) {
                $failed++;
                $payment->increment('sync_attempts');
                $payment->update(['last_synced_at' => now()]);
                $this->warn(sprintf('[ÉCHEC API] #%d %s — paiement introuvable', $payment->id, $payment->external_id));
                continue;
            }

            $status = strtoupper((string) ($detail['status'] ?? 'PENDING'));

            // Statut non terminal → pas de décision, on laisse au webhook
            if (in_array($status, ['PENDING', 'PROCESSING', ''], true)) {
                $payment->update([
                    'status' => $status ?: 'PENDING',
                    'sync_attempts' => $payment->sync_attempts + 1,
                    'last_synced_at' => now(),
                ]);
                continue;
            }

            // Idempotence : déjà traité par webhook ?
            $paymentId = (string) ($detail['id'] ?? $payment->payment_id ?? '');
            $alreadyProcessed = CreditTransaction::query()
                ->where('reference', $paymentId)
                ->where('type', 'purchase')
                ->exists();

            if ($alreadyProcessed) {
                $payment->update([
                    'status' => $status,
                    'paid_at' => now(),
                    'sync_attempts' => $payment->sync_attempts + 1,
                    'last_synced_at' => now(),
                ]);
                $this->line(sprintf('[DÉJÀ TRAITÉ] #%d %s — webhook déjà reçu', $payment->id, $payment->external_id));
                continue;
            }

            $user = $payment->user;
            if (! $user) {
                $failed++;
                $this->warn(sprintf('[UTILISATEUR INTROUVABLE] #%d %s', $payment->id, $payment->external_id));
                continue;
            }

            if ($status === 'COMPLETED') {
                $metadata = $payment->metadata ?? [];
                $purpose = $metadata['purpose'] ?? $payment->purpose;

                if (in_array($purpose, ['subscription', 'subscription_renewal'], true)) {
                    $subscriptions->activateFromWebhook($detail, $metadata);
                    $this->info(sprintf('[OK] #%d %s — abonnement activé (fallback)', $payment->id, $payment->external_id));
                } else {
                    // Achat de crédits — idempotent via creditIfNotProcessed()
                    $result = $credits->creditIfNotProcessed(
                        $user,
                        (int) ($metadata['credits'] ?? $payment->amount_fcfa),
                        reference: $paymentId,
                        description: 'Achat de crédits via KPay (synchronisation)',
                        metadata: [
                            'purpose' => 'credit_purchase',
                            'payment_status' => 'completed',
                            'payment_id' => $paymentId,
                            'external_id' => $payment->external_id,
                            'amount_fcfa' => $payment->amount_fcfa,
                            'sync_fallback' => true,
                        ],
                    );

                    if (! ($result['ok'] ?? false)) {
                        $failed++;
                        $this->warn(sprintf('[ÉCHEC CRÉDIT] #%d %s — %s', $payment->id, $payment->external_id, $result['reason'] ?? 'unknown'));
                        continue;
                    }

                    if (($result['already_processed'] ?? false)) {
                        $this->line(sprintf('[DÉJÀ TRAITÉ] #%d %s — crédit déjà appliqué', $payment->id, $payment->external_id));
                    } else {
                        // Facture + email (uniquement si on vient de créditer)
                        $invoice = $invoiceService->createForCreditPurchase(
                            $user,
                            amount: $payment->amount_fcfa,
                            currency: $payment->currency ?: 'XAF',
                            reference: $paymentId,
                            status: 'paid',
                        );

                        try {
                            $pdfStoragePath = $invoiceService->downloadPath($invoice);
                            $pdfPath = $pdfStoragePath
                                ? Storage::disk((string) config('billing.invoice_storage_disk', 'local'))->path($pdfStoragePath)
                                : null;

                            Mail::to($user->email)->send(new PaymentReceipt(
                                user: $user,
                                invoice: $invoice,
                                label: 'Achat de '.$payment->amount_fcfa.' crédits',
                                pdfPath: $pdfPath,
                            ));
                        } catch (\Throwable $e) {
                            Log::error('kpay:sync — échec email de reçu', [
                                'user_id' => $user->id,
                                'invoice_id' => $invoice->id,
                                'error' => $e->getMessage(),
                            ]);
                        }

                        $this->info(sprintf('[OK] #%d %s — %d crédits ajoutés (fallback)', $payment->id, $payment->external_id, $payment->amount_fcfa));
                    }
                }

                $payment->update([
                    'status' => 'COMPLETED',
                    'paid_at' => now(),
                    'last_synced_at' => now(),
                ]);
                $processed++;
            } else {
                // FAILED / CANCELLED
                $payment->update([
                    'status' => $status,
                    'last_synced_at' => now(),
                ]);
                $this->warn(sprintf('[%s] #%d %s', $status, $payment->id, $payment->external_id));
                $processed++;
            }
        }

        Log::info('kpay:sync exécuté', [
            'total' => $payments->count(),
            'processed' => $processed,
            'failed' => $failed,
        ]);

        $this->info(sprintf('Terminé : %d traités, %d échecs.', $processed, $failed));

        return self::SUCCESS;
    }
}
