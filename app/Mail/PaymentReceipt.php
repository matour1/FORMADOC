<?php

namespace App\Mail;

use App\Models\Invoice;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Reçu de paiement envoyé à l'utilisateur après un paiement KPay confirmé
 * (achat de crédits ou abonnement). Inclut la facture PDF en pièce jointe.
 */
class PaymentReceipt extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public User $user,
        public Invoice $invoice,
        public string $label = 'Paiement',
        public ?string $pdfPath = null,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Votre reçu FORMADOC — '.$this->invoice->number,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.payment-receipt',
            with: [
                'user' => $this->user,
                'invoice' => $this->invoice,
                'label' => $this->label,
            ],
        );
    }

    /**
     * Pièce jointe : le PDF de la facture (s'il a pu être généré).
     *
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        if ($this->pdfPath && file_exists($this->pdfPath)) {
            return [
                Attachment::fromPath($this->pdfPath)
                    ->as('facture-'.$this->invoice->number.'.pdf')
                    ->withMime('application/pdf'),
            ];
        }

        return [];
    }
}
