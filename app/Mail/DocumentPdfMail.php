<?php

namespace App\Mail;

use App\Models\Document;
use App\Support\BrandedEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Build-plan 12 follow-up (2026-09-07) — the one email in this codebase sent
 * via Laravel Mail instead of Smove. Every other business email goes through
 * Smove (see Lead::sendLandingPageConfirmation()'s docblock and
 * SmoveClient's class docblock), but Smove's real API has no
 * attachment-upload endpoint at all — the business owner explicitly wants
 * this one send ("קבלת המסמך כ-PDF" button on a digital-form document send,
 * routes.documents.email-pdf) to carry a real attached PDF file rather than
 * a link, which Smove is technically unable to do. Sent via
 * config('mail.default') (the 'log' driver until real SMTP creds are set —
 * same "not configured yet" pattern as every other integration in this app;
 * see docs/build-plan/12-external-integrations.md).
 */
class DocumentPdfMail extends Mailable
{
    use Queueable;

    public function __construct(
        private readonly Document $document,
        private readonly string $recipientName,
        private readonly string $pdfBinary,
        private readonly string $fileName,
    ) {}

    public function envelope(): Envelope
    {
        $typeLabel = Document::TYPE_LABELS[$this->document->document_type] ?? $this->document->document_type;

        return new Envelope(subject: "{$typeLabel} — קובץ PDF");
    }

    public function content(): Content
    {
        $typeLabel = Document::TYPE_LABELS[$this->document->document_type] ?? $this->document->document_type;

        $body = '<p>שלום '.e($this->recipientName).',</p>'
            .'<p>מצורף בקובץ PDF "'.e($typeLabel).'" שביקשת.</p>'
            .'<p>בברכה, כפיים</p>';

        return new Content(htmlString: BrandedEmail::wrap($body));
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        return [
            Attachment::fromData(fn () => $this->pdfBinary, $this->fileName)->withMime('application/pdf'),
        ];
    }
}
