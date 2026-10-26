<?php

namespace App\Services\Integrations;

use App\Models\EmailTemplate;
use App\Models\ExternalIntegrationSetting;
use App\Support\EmailMessage;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Build-plan 12 — real HTTP wiring to Smove's actual public REST API
 * (rest.smoove.io/v1, per the Swagger contract the business owner supplied
 * 2026-09-02), gated entirely on ExternalIntegrationSetting('smove'). Every
 * call is expected to run inside ExternalOperationRunner::run() so a thrown
 * RuntimeException here (not-configured, or Smove itself failing) is always
 * caught, recorded, and never propagated into the local business action that
 * triggered it (FR-8.16-FR-8.18).
 *
 * Smove DOES support real file attachments on a campaign — `POST /Campaigns`
 * takes a `campaignAttachments` field (an array of URLs Smove's own servers
 * fetch and attach), just entirely undocumented in the Swagger description
 * text; this was missed in the original build-plan-12 wiring and is used
 * starting 2026-09-07 (see sendCampaign()'s docblock for the exact URL shape
 * Smove requires). sendMaterialsEmail() still embeds links in the body
 * rather than attaching (build-plan 12: MaterialDelivery::sendFor() signs a
 * download URL per attachment — see MaterialDeliveryAttachment::
 * downloadUrl()) purely because a material send can carry several files of
 * arbitrary type/size to several recipients at once — attaching them all is
 * unnecessary weight when a link works fine there; a single document's PDF
 * (sendDocumentEmail()/sendDocumentAttachmentEmail() below) is exactly the
 * case where a real attachment is worth it.
 *
 * Auth: Smove's Swagger types its "Token" field as an apiKey-in-header named
 * Authorization — a real "Bearer <key>" value 401'd against production
 * (confirmed 2026-09-02), so this sends the raw key with no scheme prefix
 * (see http() below), matching OpenAPI's apiKey semantics literally.
 */
class SmoveClient
{
    /** Fixed vendor address — Smove has one production API server, not a business-owner setting. */
    private const BASE_URL = 'https://rest.smoove.io/v1';

    private ?ExternalIntegrationSetting $config = null;

    public function isConfigured(): bool
    {
        return $this->config()->is_active && filled($this->setting('api_key'));
    }

    /** The one Smove list every lead/customer/supplier joins (settings → אינטגרציות → Smove). */
    public function listId(): ?int
    {
        $id = $this->setting('list_id');

        return filled($id) ? (int) $id : null;
    }

    /**
     * Whether Smove already shows this email as having confirmed consent
     * (`canReceiveEmails: true`). Callers use this to decide whether it's
     * safe to call syncMailingListMembership('join', ...) at all — see that
     * method's docblock for why re-sending `canReceiveEmails`/
     * `lists_ToSubscribe` for an already-confirmed contact is destructive.
     * Returns false (not just "unknown") for a contact Smove has never seen,
     * or when Smove itself can't be reached — either way, the safe default
     * is "proceed with the normal join call".
     */
    public function hasConfirmedConsent(string $email): bool
    {
        $this->assertConfigured();

        $response = $this->http()->get('/Contacts', ['q.email.like' => $email, 'fields' => 'email,canReceiveEmails']);

        if ($response->failed()) {
            return false;
        }

        $match = collect($response->json() ?? [])->first(
            fn ($c) => is_array($c) && strcasecmp($c['email'] ?? '', $email) === 0,
        );

        return (bool) ($match['canReceiveEmails'] ?? false);
    }

    /**
     * FR-1.17/FR-5.21-FR-5.26: join/remove a lead/customer/supplier on a
     * Smove mailing list. Smove has no lookup-by-name — $listId is the one
     * Smove list id set in the Smove integration setting (listId()); none set
     * fails loudly here rather than guessing.
     *
     * IMPORTANT — confirmed empirically against Smove's real API
     * (2026-09-06): posting `canReceiveEmails: true` together with
     * `lists_ToSubscribe` resets an ALREADY-CONFIRMED contact's consent back
     * to unconfirmed — even when the list being joined is one the contact
     * was never on before, and this holds across every documented Contacts
     * endpoint (plain POST/PUT by email or by numeric id, Resubscribe,
     * BulkImport) — there is no request shape that adds a list to an
     * already-confirmed contact and preserves that consent. What DOES
     * survive: every list included in the *same* request as the one that
     * earns the contact's first confirmation stays confirmed together —
     * one click covers the whole set (also confirmed empirically,
     * 2026-09-06). So a 'join' call must never be made at all once
     * hasConfirmedConsent() is true (see that method and its one caller,
     * MailingMembership::pushToSmove()) — and while it's still false, that
     * caller passes EVERY mailing list this app currently knows about here,
     * not just the one the caller actually cares about, so the contact's
     * eventual single confirmation pre-clears every future list join too.
     *
     * $contact['external_id'] (Smove's real `externalId` field, confirmed
     * against its Swagger) is optional and purely for traceability — set by
     * MailingMembership::syncContact() to "contact-{id}" (2026-09-08) so a
     * customer with several CONTACT rows gets several distinct Smove
     * contacts, each one clearly linked back to this app instead of getting
     * silently merged/overwritten under a single shared email.
     *
     * @param  int|array<int>|null  $listId  A single list (always the case
     *                                        for 'remove') or several (a
     *                                        'join' broadened by the caller).
     */
    public function syncMailingListMembership(int|array|null $listId, string $action, array $contact): string
    {
        $this->assertConfigured();

        $listIds = array_values(array_filter(is_array($listId) ? $listId : [$listId]));

        if (empty($listIds)) {
            throw new RuntimeException('לא הוגדר מזהה רשימה ב-Smove — יש להזין אותו בהגדרות מערכת ← אינטגרציות ← Smove.');
        }

        $response = $this->http()->post('/Contacts?updateIfExists=true&restoreIfDeleted=true&restoreIfUnsubscribed=true', array_filter([
            'email' => $this->requiredEmail($contact),
            'firstName' => $contact['name'] ?? null,
            'externalId' => $contact['external_id'] ?? null,
            'canReceiveEmails' => true,
            'lists_ToSubscribe' => $action === 'join' ? $listIds : null,
            'lists_ToUnsubscribe' => $action === 'remove' ? $listIds : null,
        ], fn ($v) => $v !== null));

        return $this->referenceOrFail($response);
    }

    /**
     * FR-5.7: the materials-delivery send. $attachmentLinks is a list of
     * ['name' => string, 'url' => string] — see class docblock for why this
     * is links, not attached files.
     *
     * $body is the business owner's text (the 'material_delivery' template,
     * possibly edited for this send) inside the branded shell — or, when
     * $isHtml, her own uploaded HTML used as is. Either way the links list
     * and the "received" button (MaterialDelivery::acknowledge(), which also
     * stops the reminder) replace their tokens, or are added at the end.
     */
    public function sendMaterialsEmail(array $recipients, array $attachmentLinks, string $subject, string $body, string $acknowledgeUrl, bool $isHtml = false): string
    {
        return $this->send($recipients, EmailMessage::materials($subject, $body, $attachmentLinks, $acknowledgeUrl, $isHtml));
    }

    /**
     * The reminder for materials not yet confirmed (materials:process-reminders),
     * to every recipient of the delivery. $body is the business owner's
     * rendered 'material_reminder' template; the "received" button replaces
     * its EmailTemplate::ACKNOWLEDGE_BUTTON_TOKEN, or is appended at the end.
     *
     * @param  array<int, array{email: string, name: ?string}>  $recipients
     */
    public function sendMaterialReminderEmail(array $recipients, string $subject, string $body, string $acknowledgeUrl): string
    {
        return $this->send($recipients, EmailMessage::materialReminder($subject, $body, $acknowledgeUrl));
    }

    /**
     * An email to a whole mailing list (⚡mailing-lists → App\Jobs\SendMailingListEmail):
     * $bodyHtml is either the branded shell around the owner's text, or the
     * owner's own uploaded HTML, used as is. Smove itself never delivers to a
     * contact who unsubscribed.
     *
     * @param  array<int, array{email: string, name: ?string}>  $recipients
     */
    public function sendListEmail(array $recipients, string $subject, string $bodyHtml): string
    {
        return $this->send($recipients, new EmailMessage($subject, $bodyHtml));
    }

    /** Any single-recipient plain-text send: expense reminders (FR-6.9), lead confirmation (FR-1.18) — all business email goes through Smove, never Laravel Mail. The text itself (EmailTemplate::render()'s output, editable by the business owner) is untouched; BrandedEmail::wrap() only adds the branded shell around it. */
    public function sendTransactionalEmail(string $toEmail, string $toName, string $subject, string $body): string
    {
        return $this->send([['email' => $toEmail, 'name' => $toName]], EmailMessage::transactional($subject, $body));
    }

    /**
     * The morning email of a user's own reminders for the day
     * (tasks:send-reminders) — an internal email to a staff member, so a
     * fixed wording rather than an editable template. $tasks: one row per
     * reminder, each with a link straight into its lead / deal.
     *
     * @param  array<int, array{title: string, subtitle: string, description: ?string, url: string}>  $tasks
     */
    public function sendTaskRemindersEmail(string $toEmail, string $toName, string $subject, string $intro, array $tasks): string
    {
        return $this->send([['email' => $toEmail, 'name' => $toName]], EmailMessage::taskReminders($subject, $intro, $tasks));
    }

    /**
     * Build-plan 12 follow-up (FR-4.2/FR-4.7): a document send (quote/order
     * form/contract/invoice/credit note) — $formUrl links to the online
     * form (documents.sign, no login required — view the content, fill in
     * any template fields, click "אישור וחתימה") for a 'digital' send;
     * $attachmentUrl is the real PDF file (Document::smoveAttachmentUrl(),
     * fetched and attached by Smove itself — see sendCampaign()'s docblock)
     * for a 'pdf' send. Document::sendTo() passes exactly one of the two,
     * matching the digital/PDF choice already in the send UI. $requestPdfUrl
     * (digital sends only — see Document::sendTo()) is a separate,
     * additional button: "קבלת המסמך כ-PDF במייל" hits documents.email-pdf,
     * which triggers a follow-up sendDocumentAttachmentEmail() call.
     * $formUrl is the action the recipient actually needs to take, so it's
     * rendered as BrandedEmail::primaryButton() (large, prominent);
     * $requestPdfUrl is a minor convenience, rendered as the deliberately
     * de-emphasized BrandedEmail::subtleButton() below the sign-off rather
     * than competing with the main action (2026-09-08 request).
     */
    public function sendDocumentEmail(string $toEmail, string $toName, string $subject, string $introText, ?string $formUrl, ?string $attachmentUrl, ?string $requestPdfUrl = null): string
    {
        $attachments = $attachmentUrl ? [['name' => "{$subject}.pdf", 'url' => $attachmentUrl]] : [];

        return $this->send([['email' => $toEmail, 'name' => $toName]], EmailMessage::document($toName, $subject, $introText, $formUrl, $attachments, $requestPdfUrl));
    }

    /**
     * A supplier's missing-invoice request (Expense::requestInvoice()). $body
     * is the business owner's own rendered EmailTemplate text; the upload
     * button replaces its EmailTemplate::UPLOAD_BUTTON_TOKEN line, or is
     * appended at the end when the text doesn't place it.
     */
    public function sendInvoiceRequestEmail(string $toEmail, string $toName, string $subject, string $body, string $uploadUrl): string
    {
        return $this->send([['email' => $toEmail, 'name' => $toName]], EmailMessage::invoiceRequest($subject, $body, $uploadUrl));
    }

    /**
     * One email asking a supplier for several missing invoices
     * (Expense::requestAllMissingInvoices()) — a row per expense, each with
     * its own upload button, where EmailTemplate::EXPENSE_LIST_TOKEN sits.
     *
     * @param  list<array{label: string, url: string}>  $expenses
     */
    public function sendInvoicesRequestEmail(string $toEmail, string $toName, string $subject, string $body, array $expenses): string
    {
        return $this->send([['email' => $toEmail, 'name' => $toName]], EmailMessage::invoicesRequest($subject, $body, $expenses));
    }

    /** A SUMIT payment page link (PaymentLink::send()) — the payment button is the email's one action. */
    public function sendPaymentLinkEmail(string $toEmail, string $toName, string $subject, string $introText, string $paymentUrl, string $footerText): string
    {
        return $this->send([['email' => $toEmail, 'name' => $toName]], EmailMessage::paymentLink($toName, $subject, $introText, $paymentUrl, $footerText));
    }

    /**
     * The "קבלת המסמך כ-PDF במייל" button's click (documents.email-pdf,
     * Document::requestPdfBySmove()) — a standalone follow-up send, not part
     * of the original sendDocumentEmail() campaign, since the recipient
     * decides whether they want this copy only after already receiving that
     * first email.
     */
    public function sendDocumentAttachmentEmail(string $toEmail, string $toName, string $typeLabel, string $introText, string $attachmentUrl): string
    {
        return $this->send([['email' => $toEmail, 'name' => $toName]], EmailMessage::documentAttachment($toName, $typeLabel, $introText, $attachmentUrl));
    }

    /**
     * Sends a built EmailMessage (the same one a "תצוגה מקדימה" window shows)
     * — every send* method above is this plus its EmailMessage builder.
     *
     * @param  array<int, array{email: string, name: ?string}>  $recipients
     */
    public function send(array $recipients, EmailMessage $message): string
    {
        return $this->sendCampaign($recipients, $message->subject, $message->html, $message->attachmentUrls());
    }

    /**
     * Every real single/few-recipient send goes through this — see class
     * docblock on why it's a "campaign". Smove silently drops a campaign
     * created with toMembersByEmail if that address isn't already a real,
     * opted-in contact (confirmed 2026-09-02: the campaign call returned
     * success with an id, but its own /Statistics showed 0 sent and
     * /Recipients came back empty) — so each recipient is first resolved to
     * a real contact and referenced by the resulting numeric id via
     * toMembersById instead.
     *
     * Build-plan 12 fix (confirmed 2026-09-06 by isolating the call:
     * canReceiveEmails came back false in the very same response, with no
     * campaign involved at all, for both a pre-existing and a freshly
     * created contact): posting to /Contacts with
     * updateIfExists=true&restoreIfDeleted=true&restoreIfUnsubscribed=true —
     * i.e. an API write that *asserts* consent rather than just reading a
     * contact — is what resets/withholds canReceiveEmails, every single
     * time, regardless of the boolean sent. ensureContact() below therefore
     * never writes to an existing contact anymore: it only looks one up by
     * email (read-only) and reuses its id untouched, so a contact whose
     * consent was genuinely granted (Smove's own opt-in confirmation, or set
     * directly in the Smove dashboard) is never overwritten by this send
     * path. A contact that doesn't exist yet in Smove is still created (a
     * plain create has nothing to preserve), but without claiming
     * canReceiveEmails — the flag has no real effect via the API anyway, and
     * a brand-new contact has no consent to assert.
     *
     * $attachmentUrls -> `campaignAttachments`: confirmed empirically against
     * Smove's real API (2026-09-07) that this field silently rejects
     * (`400 ErrAttachments`) any URL carrying a query string — even a
     * harmless `?x=1` — and requires the path itself to end in a real file
     * extension (`.pdf`). A URL with neither of those problems (any
     * reachable https URL, including a third-party domain) is fetched and
     * genuinely attached — confirmed by real test sends. See Document::
     * smoveAttachmentUrl() for the URL shape used to satisfy this.
     */
    private function sendCampaign(array $recipients, string $subject, string $bodyHtml, array $attachmentUrls = []): string
    {
        $this->assertConfigured();

        $memberIds = collect($recipients)
            ->filter(fn (array $r) => filled($r['email'] ?? null))
            ->map(fn (array $r) => $this->ensureContact($r))
            ->all();

        if (empty($memberIds)) {
            throw new RuntimeException('אין נמען עם כתובת דוא"ל תקינה לשליחה דרך Smove.');
        }

        $response = $this->http()->post('/Campaigns?sendnow=true', array_filter([
            'subject' => $subject,
            'body' => $bodyHtml,
            'toMembersById' => $memberIds,
            'campaignAttachments' => $attachmentUrls ?: null,
        ], fn ($v) => $v !== null));

        return $this->referenceOrFail($response);
    }

    /** Resolves a contact to its Smove numeric id — reused untouched if it already exists, created (never updated) otherwise. See sendCampaign()'s docblock for why this never writes to an existing contact. */
    private function ensureContact(array $contact): int
    {
        $email = $this->requiredEmail($contact);

        $lookup = $this->http()->get('/Contacts', ['q.email.like' => $email]);

        if ($lookup->successful()) {
            $existing = collect($lookup->json() ?? [])->first(
                fn (array $c) => strcasecmp($c['email'] ?? '', $email) === 0,
            );

            if ($existing) {
                return (int) $existing['id'];
            }
        }

        $response = $this->http()->post('/Contacts', [
            'email' => $email,
            'firstName' => $contact['name'] ?? null,
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Smove החזירה שגיאה ביצירת איש קשר ('.$response->status().'): '.($response->json('message') ?? trim($response->body())));
        }

        return (int) $response->json('id');
    }

    private function requiredEmail(array $contact): string
    {
        if (empty($contact['email'])) {
            throw new RuntimeException('לאיש הקשר אין כתובת דוא"ל — לא ניתן לסנכרן עם Smove.');
        }

        return $contact['email'];
    }

    private function referenceOrFail(Response $response): string
    {
        if ($response->failed()) {
            // Smove's error responses aren't always JSON (a 401 body is plain
            // text, per its own docs) — show the raw body when there's no
            // 'message' key, since the status code alone ("401") isn't
            // enough to tell an invalid key apart from a key whose role
            // lacks permission for this endpoint.
            $body = trim($response->body());
            $detail = $response->json('message') ?? ($body !== '' ? $body : (string) $response->status());

            throw new RuntimeException('Smove החזירה שגיאה ('.$response->status().'): '.$detail);
        }

        return (string) ($response->json('id') ?? $response->json('reference') ?? (string) now()->timestamp);
    }

    private function assertConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Smove אינה מוגדרת עדיין — יש להזין מפתח API בהגדרות המערכת ולהפעיל את האינטגרציה.');
        }
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl(self::BASE_URL)
            ->withHeaders(['Authorization' => (string) $this->setting('api_key')])
            ->acceptJson()
            ->timeout(15);
    }

    private function setting(string $key): mixed
    {
        return $this->config()->settings[$key] ?? null;
    }

    private function config(): ExternalIntegrationSetting
    {
        return $this->config ??= ExternalIntegrationSetting::firstOrCreate(
            ['system' => 'smove'],
            ['is_active' => false, 'settings' => []],
        );
    }
}
