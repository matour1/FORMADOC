<?php

namespace App\Mail;

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Rappel de renouvellement automatique envoyé J-3 avant la fin de période
 * (audit copywriting §6.2.6).
 */
class RenewalReminderMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public User $user,
        public Subscription $subscription,
        public string $renewDate,
        public string $planName,
        public int $price,
        public string $currency = 'XAF',
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Ton abonnement se renouvelle bientôt — FORMADOC',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.renewal-reminder',
            with: [
                'user' => $this->user,
                'subscription' => $this->subscription,
                'renewDate' => $this->renewDate,
                'planName' => $this->planName,
                'price' => $this->price,
                'currency' => $this->currency,
            ],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
