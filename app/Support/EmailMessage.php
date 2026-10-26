<?php

namespace App\Support;

use App\Models\EmailTemplate;

/**
 * One outgoing email exactly as it goes out: subject, the full HTML, and
 * the files Smove attaches (by URL — see SmoveClient::sendCampaign()).
 * Each named constructor below is the one place that email's look is
 * built, used both by the real send (SmoveClient) and by the "תצוגה
 * מקדימה" window (App\Concerns\PreviewsEmails) — so a preview, and a test
 * send to oneself, are exactly what the recipient gets.
 */
final class EmailMessage
{
    /** @param  list<array{name: string, url: string}>  $attachments */
    public function __construct(
        public readonly string $subject,
        public readonly string $html,
        public readonly array $attachments = [],
    ) {}

    /** @return list<string> */
    public function attachmentUrls(): array
    {
        return array_column($this->attachments, 'url');
    }

    /** @return array{subject: string, html: string, attachments: list<array{name: string, url: string}>} */
    public function toArray(): array
    {
        return ['subject' => $this->subject, 'html' => $this->html, 'attachments' => $this->attachments];
    }

    public static function fromArray(array $data): self
    {
        return new self($data['subject'], $data['html'], $data['attachments'] ?? []);
    }

    /**
     * FR-5.7: the materials-delivery send. $body is the business owner's
     * text (the 'material_delivery' template, possibly edited for this send)
     * inside the branded shell — or, when $isHtml, her own uploaded HTML used
     * as is. Either way the links list and the "received" button
     * (MaterialDelivery::acknowledge(), which also stops the reminder)
     * replace their tokens, or are added at the end.
     *
     * @param  array<int, array{name: string, url: string}>  $links
     */
    public static function materials(string $subject, string $body, array $links, string $acknowledgeUrl, bool $isHtml = false): self
    {
        $blocks = [
            EmailTemplate::MATERIALS_LIST_TOKEN => BrandedEmail::linksList($links),
            EmailTemplate::ACKNOWLEDGE_BUTTON_TOKEN => BrandedEmail::primaryButton($acknowledgeUrl, 'קיבלתי את החומרים'),
        ];

        return new self($subject, $isHtml
            ? BrandedEmail::htmlWithBlocks($body, $blocks)
            : BrandedEmail::wrap(BrandedEmail::textWithBlocks($body, $blocks)));
    }

    /** The reminder for materials not yet confirmed — $body is the rendered 'material_reminder' template. */
    public static function materialReminder(string $subject, string $body, string $acknowledgeUrl): self
    {
        return new self($subject, BrandedEmail::wrap(BrandedEmail::textWithBlocks($body, [
            EmailTemplate::ACKNOWLEDGE_BUTTON_TOKEN => BrandedEmail::primaryButton($acknowledgeUrl, 'קיבלתי את החומרים'),
        ])));
    }

    /** Plain template text in the branded shell: lead confirmation (FR-1.18), general templates. */
    public static function transactional(string $subject, string $body): self
    {
        return new self($subject, BrandedEmail::wrap('<p>'.nl2br(e($body)).'</p>'));
    }

    /**
     * The morning email of a user's own reminders for the day.
     *
     * @param  array<int, array{title: string, subtitle: string, description: ?string, url: string}>  $tasks
     */
    public static function taskReminders(string $subject, string $intro, array $tasks): self
    {
        $items = collect($tasks)->map(fn (array $t) => '<li style="margin-bottom:14px;">'
            .'<a href="'.e($t['url']).'" style="color:#286E9A; font-weight:700;">'.e($t['title']).'</a>'
            .'<div style="font-size:14px; color:#555;">'.e($t['subtitle']).'</div>'
            .($t['description'] ? '<div style="font-size:14px;">'.nl2br(e($t['description'])).'</div>' : '')
            .'</li>')->implode('');

        return new self($subject, BrandedEmail::wrap('<p>'.e($intro).'</p><ul style="padding-inline-start:20px;">'.$items.'</ul>'));
    }

    /**
     * A document send — see SmoveClient::sendDocumentEmail() for what each
     * URL is. $formUrl is the main action (primaryButton); $requestPdfUrl
     * the deliberately de-emphasized subtleButton after the sign-off.
     *
     * @param  list<array{name: string, url: string}>  $attachments
     */
    public static function document(string $toName, string $subject, string $introText, ?string $formUrl, array $attachments = [], ?string $requestPdfUrl = null): self
    {
        $body = '<p>שלום '.e($toName).',</p><p>'.nl2br(e($introText)).'</p>';

        if ($formUrl) {
            $body .= BrandedEmail::primaryButton($formUrl, 'לצפייה במסמך, מילוי פרטים ואישור מקוון');
        }

        $body .= '<p>בברכה, כפיים</p>';

        if ($requestPdfUrl) {
            $body .= BrandedEmail::subtleButton($requestPdfUrl, 'חסומה? קבלי את המסמך כקובץ PDF');
        }

        return new self($subject, BrandedEmail::wrap($body), $attachments);
    }

    /** The "קבלת המסמך כ-PDF במייל" follow-up. */
    public static function documentAttachment(string $toName, string $typeLabel, string $introText, string $attachmentUrl): self
    {
        return new self(
            "{$typeLabel} — קובץ PDF",
            BrandedEmail::wrap('<p>שלום '.e($toName).',</p><p>'.nl2br(e($introText)).'</p><p>בברכה, כפיים</p>'),
            [['name' => "{$typeLabel}.pdf", 'url' => $attachmentUrl]],
        );
    }

    /** A supplier's missing-invoice request — the upload button replaces EmailTemplate::UPLOAD_BUTTON_TOKEN, or goes at the end. */
    public static function invoiceRequest(string $subject, string $body, string $uploadUrl): self
    {
        return new self($subject, BrandedEmail::wrap(BrandedEmail::textWithBlocks($body, [
            EmailTemplate::UPLOAD_BUTTON_TOKEN => BrandedEmail::primaryButton($uploadUrl, 'להעלאת החשבונית'),
        ])));
    }

    /**
     * One email asking a supplier for several missing invoices — a row per
     * expense, each with its own upload button, where
     * EmailTemplate::EXPENSE_LIST_TOKEN sits.
     *
     * @param  list<array{label: string, url: string}>  $expenses
     */
    public static function invoicesRequest(string $subject, string $body, array $expenses): self
    {
        $rows = collect($expenses)->map(fn (array $expense) => '<tr>'
            .'<td style="padding:12px 8px; border-bottom:1px solid #DAD7C7; font-family:Heebo, Arial, sans-serif; font-size:15px; color:#20241F;">'.e($expense['label']).'</td>'
            .'<td style="padding:12px 8px; border-bottom:1px solid #DAD7C7; text-align:left; white-space:nowrap;">'
            .'<a href="'.e($expense['url']).'" target="_blank" style="display:inline-block; padding:9px 20px; background-color:#286E9A; border-radius:24px; font-family:Heebo, Arial, sans-serif; font-size:14px; font-weight:700; color:#FFFFFF; text-decoration:none;">להעלאת החשבונית</a>'
            .'</td></tr>')->implode('');
        $list = '<table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="margin:20px 0; border-collapse:collapse;">'.$rows.'</table>';

        return new self($subject, BrandedEmail::wrap(BrandedEmail::textWithBlocks($body, [EmailTemplate::EXPENSE_LIST_TOKEN => $list])));
    }

    /** A SUMIT payment page link — the payment button is the email's one action. */
    public static function paymentLink(string $toName, string $subject, string $introText, string $paymentUrl, string $footerText): self
    {
        return new self($subject, BrandedEmail::wrap('<p>שלום '.e($toName).',</p><p>'.nl2br(e($introText)).'</p>'
            .BrandedEmail::primaryButton($paymentUrl, 'לתשלום מאובטח בכרטיס אשראי')
            .'<p>'.e($footerText).'</p><p>בברכה, כפיים</p>'));
    }
}
