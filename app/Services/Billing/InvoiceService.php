<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Shared\Converter;

/**
 * Facturation (Phase 9) : création de factures + génération PDF.
 *
 * Règles :
 *   - Q3a : facture PDF générée à chaque paiement (souscription,
 *     renouvellement, achat de crédits)
 *   - numéro unique lisible INV-AAAA-XXXXXX
 *   - montant en plus petite unité (FCFA entier, EUR/USD centimes)
 *   - le PDF est stocké sur le disk 'local' (config billing.invoice_storage_disk)
 */
class InvoiceService
{
    /**
     * Génère le prochain numéro de facture.
     */
    public function nextNumber(\DateTimeInterface $at = null): string
    {
        $at ??= now();
        $year = $at->format('Y');

        $count = Invoice::whereYear('created_at', $year)->count();

        return 'INV-'.$year.'-'.str_pad((string) ($count + 1), 6, '0', STR_PAD_LEFT);
    }

    /**
     * Crée une facture pour un paiement d'abonnement.
     */
    public function createForSubscription(
        User $user,
        Subscription $subscription,
        int $amount,
        string $currency = 'XAF',
        string $reference = '',
        string $status = 'paid',
    ): Invoice {
        return $this->create(
            user: $user,
            type: 'subscription',
            plan: $subscription->plan,
            subscription: $subscription,
            amount: $amount,
            currency: $currency,
            reference: $reference,
            status: $status,
            periodStart: $subscription->starts_at,
            periodEnd: $subscription->ends_at,
        );
    }

    /**
     * Crée une facture pour un achat de crédits.
     */
    public function createForCreditPurchase(
        User $user,
        int $amount,
        string $currency = 'XAF',
        string $reference = '',
        string $status = 'paid',
    ): Invoice {
        return $this->create(
            user: $user,
            type: 'credit_purchase',
            plan: null,
            subscription: null,
            amount: $amount,
            currency: $currency,
            reference: $reference,
            status: $status,
        );
    }

    /**
     * Crée une facture et génère son PDF.
     */
    public function create(
        User $user,
        string $type,
        ?Plan $plan,
        ?Subscription $subscription,
        int $amount,
        string $currency,
        string $reference,
        string $status = 'paid',
        ?\DateTimeInterface $periodStart = null,
        ?\DateTimeInterface $periodEnd = null,
        array $metadata = [],
    ): Invoice {
        $invoice = Invoice::create([
            'number' => $this->nextNumber(),
            'user_id' => $user->id,
            'plan_id' => $plan?->id,
            'subscription_id' => $subscription?->id,
            'type' => $type,
            'amount' => $amount,
            'currency' => $currency,
            'status' => $status,
            'payment_method' => $subscription?->payment_method ?? 'kpay',
            'reference' => $reference,
            'paid_at' => $status === 'paid' ? now() : null,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'metadata' => $metadata,
        ]);

        try {
            $this->generatePdf($invoice, $user, $plan);
        } catch (\Throwable $e) {
            Log::error('InvoiceService : échec génération PDF', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $invoice;
    }

    /**
     * Génère le PDF d'une facture via PhpWord (conversion PDF native).
     *
     * @return string Chemin du fichier stocké
     */
    public function generatePdf(Invoice $invoice, ?User $user = null, ?Plan $plan = null): string
    {
        $user ??= $invoice->user;
        $plan ??= $invoice->plan;

        $phpWord = new PhpWord();
        $section = $phpWord->addSection([
            'marginTop' => Converter::cmToTwip(2),
            'marginBottom' => Converter::cmToTwip(2),
            'marginLeft' => Converter::cmToTwip(2),
            'marginRight' => Converter::cmToTwip(2),
        ]);

        // En-tête
        $section->addText('FORMADOC', ['bold' => true, 'size' => 20, 'color' => '2B3F66']);
        $section->addText('Mise en forme automatique de documents', ['size' => 10, 'color' => '5B6478']);
        $section->addTextBreak(1);

        // Titre
        $section->addText('FACTURE '.$invoice->number, ['bold' => true, 'size' => 16]);
        $section->addText(
            'Date : '.($invoice->paid_at ?? $invoice->created_at)?->format('d/m/Y'),
            ['size' => 10]
        );
        $section->addTextBreak(1);

        // Client
        $section->addText('Facturé à :', ['bold' => true, 'size' => 11]);
        $section->addText($user->name, ['size' => 10]);
        $section->addText($user->email, ['size' => 10]);
        $section->addTextBreak(1);

        // Détails
        $section->addText('Détail de la prestation :', ['bold' => true, 'size' => 11]);
        $description = $invoice->type === 'subscription'
            ? 'Abonnement '.($plan?->name ?? '')
            : 'Achat de crédits';
        $section->addText($description, ['size' => 10]);

        if ($invoice->period_start && $invoice->period_end) {
            $section->addText(
                'Période : du '.$invoice->period_start->format('d/m/Y').' au '.$invoice->period_end->format('d/m/Y'),
                ['size' => 10]
            );
        }
        $section->addTextBreak(1);

        // Total
        $section->addText('Total TTC : '.$invoice->formattedAmount(), ['bold' => true, 'size' => 13, 'color' => '2B3F66']);
        $section->addTextBreak(1);

        // Pied de page
        $section->addText('Merci de votre confiance. — support@formadoc.dev', ['size' => 9, 'color' => '7E8698']);

        // Sauvegarde en PDF (writer 'PDF' nécessite dompdf ; sinon fallback Word)
        $filename = 'invoices/'.$invoice->number.'.pdf';
        $disk = (string) config('billing.invoice_storage_disk', 'local');
        $tmpDir = storage_path('app/tmp');
        if (! is_dir($tmpDir)) {
            mkdir($tmpDir, 0755, true);
        }
        $tmpPath = $tmpDir.DIRECTORY_SEPARATOR.$invoice->number.'.pdf';

        try {
            $writer = IOFactory::createWriter($phpWord, 'PDF');
            $writer->save($tmpPath);
        } catch (\Throwable $e) {
            Log::warning('InvoiceService : writer PDF indisponible, fallback Word', [
                'invoice_id' => $invoice->id,
                'error' => $e->getMessage(),
            ]);
            // Fallback : générer un .docx (toujours disponible via PhpWord)
            $filename = 'invoices/'.$invoice->number.'.docx';
            $tmpPath = $tmpDir.DIRECTORY_SEPARATOR.$invoice->number.'.docx';
            $writer = IOFactory::createWriter($phpWord, 'Word2007');
            $writer->save($tmpPath);
        }

        // Stockage
        Storage::disk($disk)->put($filename, file_get_contents($tmpPath));

        @unlink($tmpPath);

        // Journalisation du chemin dans metadata
        $invoice->update([
            'metadata' => array_merge($invoice->metadata ?? [], ['pdf_path' => $filename]),
        ]);

        return $filename;
    }

    /**
     * Chemin de téléchargement d'une facture.
     */
    public function downloadPath(Invoice $invoice): ?string
    {
        $path = $invoice->metadata['pdf_path'] ?? null;

        if (! $path || ! Storage::disk((string) config('billing.invoice_storage_disk', 'local'))->exists($path)) {
            // Régénérer si absent
            try {
                return $this->generatePdf($invoice);
            } catch (\Throwable $e) {
                return null;
            }
        }

        return $path;
    }
}
