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
     * text (the materials template or a general one, possibly edited for
     * this send) inside the branded shell — or, when $isHtml, her own HTML
     * used as is. Under it, always (owner's call, 2026-10-27): the links
     * sent with the files, if any, and two buttons — "להורדת החומרים"
     * (every uploaded file at once, $downloadUrl; none when only links were
     * sent) and "קיבלתי, תודה" (MaterialDelivery::acknowledge(), which also
     * stops the reminder) — in the HTML's own main color, or the brand's.
     * The old {{רשימת_החומרים}} / {{כפתור_אישור_קבלה}} tokens are dropped
     * from the wording: the buttons below replace them.
     *
     * @param  array<int, array{name: string, url: string}>  $links  links only (a video on YouTube…) — files go through $downloadUrl
     */
    public static function materials(string $subject, string $body, ?string $downloadUrl, array $links, string $acknowledgeUrl, bool $isHtml = false): self
    {
        $body = str_replace([EmailTemplate::MATERIALS_LIST_TOKEN, EmailTemplate::ACKNOWLEDGE_BUTTON_TOKEN], '', $body);
        $footer = ($links ? '<p style="font-weight:700; margin-bottom:4px;">קישורים:</p>'.BrandedEmail::linksList($links) : '')
            .BrandedEmail::materialsButtons($downloadUrl, $acknowledgeUrl, ($isHtml ? BrandedEmail::accentColor($body) : null) ?? BrandedEmail::BRAND_COLOR);

        return new self($subject, $isHtml
            // A key no wording contains — so the footer always goes before </body>.
            ? BrandedEmail::htmlWithBlocks($body, ["\0materials-footer" => $footer])
            : BrandedEmail::wrap(BrandedEmail::textWithBlocks(preg_replace("/\n{3,}/", "\n\n", $body), []).$footer));
    }

    /**
     * An email composed by hand (a mailing-list send, a general template):
     * the text inside the branded shell, or the owner's own HTML as is. The
     * attached materials replace {{רשימת_החומרים}}, or go at the end under
     * their own heading; nothing is added when nothing is attached.
     *
     * @param  array<int, array{name: string, url: string}>  $links
     */
    public static function composed(string $subject, string $body, bool $isHtml, array $links = []): self
    {
        $blocks = $links ? [EmailTemplate::MATERIALS_LIST_TOKEN => self::materialsList($body, $links)] : [];

        return new self($subject, $isHtml
            ? BrandedEmail::htmlWithBlocks($body, $blocks)
            : BrandedEmail::wrap(BrandedEmail::textWithBlocks($body, $blocks)));
    }

    /**
     * The attached materials as links — under a "חומרים מצורפים:" heading
     * when the body doesn't place {{רשימת_החומרים}} (they then go at the end).
     *
     * @param  array<int, array{name: string, url: string}>  $links
     */
    private static function materialsList(string $body, array $links): string
    {
        $heading = str_contains($body, EmailTemplate::MATERIALS_LIST_TOKEN) ? '' : '<p style="font-weight:700;">חומרים מצורפים:</p>';

        return $heading.BrandedEmail::linksList($links);
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
     * The morning email of a user's own reminders for the day — $body is the
     * rendered 'task_reminders' template; the list goes where
     * EmailTemplate::TASK_LIST_TOKEN sits, or at the end.
     *
     * @param  array<int, array{title: string, subtitle: string, description: ?string, url: string}>  $tasks
     */
    public static function taskReminders(string $subject, string $body, array $tasks): self
    {
        $items = collect($tasks)->map(fn (array $t) => '<li style="margin-bottom:14px;">'
            .'<a href="'.e($t['url']).'" style="color:#286E9A; font-weight:700;">'.e($t['title']).'</a>'
            .'<div style="font-size:14px; color:#555;">'.e($t['subtitle']).'</div>'
            .($t['description'] ? '<div style="font-size:14px;">'.nl2br(e($t['description'])).'</div>' : '')
            .'</li>')->implode('');

        return new self($subject, BrandedEmail::wrap(BrandedEmail::textWithBlocks($body, [
            EmailTemplate::TASK_LIST_TOKEN => '<ul style="padding-inline-start:20px;">'.$items.'</ul>',
        ])));
    }

    /**
     * A document send — $body is the rendered 'document_send' template; the
     * online-form button ($formUrl) goes where EmailTemplate::DOCUMENT_BUTTON_TOKEN
     * sits, or at the end. $requestPdfUrl: the deliberately de-emphasized
     * subtleButton after everything.
     *
     * @param  list<array{name: string, url: string}>  $attachments
     */
    public static function document(string $subject, string $body, ?string $formUrl, array $attachments = [], ?string $requestPdfUrl = null): self
    {
        $html = BrandedEmail::textWithBlocks($body, [
            EmailTemplate::DOCUMENT_BUTTON_TOKEN => $formUrl ? BrandedEmail::primaryButton($formUrl, 'לצפייה במסמך, מילוי פרטים ואישור מקוון') : '',
        ]);

        if ($requestPdfUrl) {
            $html .= BrandedEmail::subtleButton($requestPdfUrl, 'חסומה? קבלי את המסמך כקובץ PDF');
        }

        return new self($subject, BrandedEmail::wrap($html), $attachments);
    }

    /**
     * Several documents in one send (Document::sendCompanions() — the order
     * form with its contract) — $body is the rendered 'documents_send'
     * template: a button per document, each to its own online form, where
     * EmailTemplate::DOCUMENT_BUTTON_TOKEN sits; every PDF attached.
     *
     * @param  list<array{label: string, url: string}>  $forms
     * @param  list<array{name: string, url: string}>  $attachments
     */
    public static function documents(string $subject, string $body, array $forms, array $attachments = []): self
    {
        $buttons = collect($forms)->map(fn (array $form) => BrandedEmail::primaryButton($form['url'], "{$form['label']} — לצפייה, מילוי פרטים ואישור מקוון"))->implode('');

        return new self($subject, BrandedEmail::wrap(BrandedEmail::textWithBlocks($body, [EmailTemplate::DOCUMENT_BUTTON_TOKEN => $buttons])), $attachments);
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

    /** A SUMIT payment page link — $body is the rendered 'payment_link' / 'standing_order_link' template; the payment button goes where EmailTemplate::PAYMENT_BUTTON_TOKEN sits, or at the end. */
    public static function paymentLink(string $subject, string $body, string $paymentUrl): self
    {
        return new self($subject, BrandedEmail::wrap(BrandedEmail::textWithBlocks($body, [
            EmailTemplate::PAYMENT_BUTTON_TOKEN => BrandedEmail::primaryButton($paymentUrl, 'לתשלום מאובטח בכרטיס אשראי'),
        ])));
    }
}
