<?php

namespace App\Mail;

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Notification d'échec de paiement (abonnement ou crédits).
 * Envoyé quand le webhook KPay confirme un statut failed/cancelled
 * (audit copywriting §6.2.5).
 */
class PaymentFailedMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public User $user,
        public int $amount,
        public string $currency = 'XAF',
        public string $reference = '',
        public ?Subscription $subscription = null,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Ton paiement n\'a pas abouti — FORMADOC',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.payment-failed',
            with: [
                'user' => $this->user,
                'amount' => $this->amount,
                'currency' => $this->currency,
                'reference' => $this->reference,
                'subscription' => $this->subscription,
            ],
        );
    }

    /**
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
