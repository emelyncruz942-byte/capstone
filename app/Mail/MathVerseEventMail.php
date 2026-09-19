<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class MathVerseEventMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param array<int, array{label: string, value: string}> $details
     */
    public function __construct(
        public string $subjectLine,
        public string $recipientName,
        public string $heading,
        public string $messageText,
        public ?string $actionLabel,
        public ?string $actionUrl,
        public string $eyebrow,
        public string $accentColor,
        public string $securityNote,
        public array $details = [],
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.mathverse-event',
            text: 'emails.mathverse-event-text',
        );
    }
}
