<?php

namespace App\Mail;

use App\Models\Document;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Notification « document prêt » envoyée quand un traitement IA long se termine
 * (audit copywriting §6.2.3).
 */
class DocumentReadyMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public User $user,
        public Document $document,
        public ?string $templateName = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Ton document est prêt — FORMADOC',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.document-ready',
            with: [
                'user' => $this->user,
                'document' => $this->document,
                'templateName' => $this->templateName,
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
