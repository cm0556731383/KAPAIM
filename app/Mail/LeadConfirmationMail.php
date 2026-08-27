<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Build-plan 12 — FR-1.18's "מייל אישור אוטומטי" half, sent from
 * Lead::createFromLandingPage() via App\Models\EmailTemplate::render()'s
 * output. A real Mailable (rather than Mail::raw(), which Mail::fake()
 * cannot record at all — MailFake::raw() is a literal no-op) so the send is
 * actually assertable in tests.
 */
class LeadConfirmationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $renderedSubject, public string $renderedContent) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->renderedSubject);
    }

    public function content(): Content
    {
        return new Content(text: 'emails.plain', with: ['content' => $this->renderedContent]);
    }
}
