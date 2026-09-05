<?php

namespace App\Services\Integrations;

use App\Models\ExternalIntegrationSetting;
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
 * Smove has no "transactional single email with attachment" endpoint — the
 * closest real primitive is a one-off email campaign sent to a single
 * recipient (POST /Campaigns?sendnow=true). sendMaterialsEmail() therefore
 * takes ready-made links (build-plan 12: MaterialDelivery::sendFor() now
 * stores the uploaded file permanently and signs a download URL per
 * attachment — see MaterialDeliveryAttachment::downloadUrl()) rather than
 * raw file bytes, and embeds them in the email body instead of attaching
 * them.
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
     * Smove mailing list. Smove has no lookup-by-name — $listId(s) are the
     * target MailingList(s)' smove_list_id (set by the business owner in
     * ⚡mailing-lists.blade.php); none linked at all fails loudly here rather
     * than guessing.
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
     * @param  int|array<int>|null  $listId  A single list (always the case
     *                                        for 'remove') or several (a
     *                                        'join' broadened by the caller).
     */
    public function syncMailingListMembership(int|array|null $listId, string $action, array $contact): string
    {
        $this->assertConfigured();

        $listIds = array_values(array_filter(is_array($listId) ? $listId : [$listId]));

        if (empty($listIds)) {
            throw new RuntimeException('רשימת התפוצה הזו טרם קושרה למזהה רשימה ב-Smove — יש להזין אותו במסך "רשימות תפוצה".');
        }

        $response = $this->http()->post('/Contacts?updateIfExists=true&restoreIfDeleted=true&restoreIfUnsubscribed=true', array_filter([
            'email' => $this->requiredEmail($contact),
            'firstName' => $contact['name'] ?? null,
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
     */
    public function sendMaterialsEmail(array $recipients, array $attachmentLinks, string $subject): string
    {
        $linksHtml = collect($attachmentLinks)
            ->map(fn (array $a) => '<li><a href="'.e($a['url']).'">'.e($a['name']).'</a></li>')
            ->implode('');

        return $this->sendCampaign(
            $recipients,
            $subject,
            '<p>שלום,</p><p>מצורפים קישורים לחומרי הלימוד:</p><ul>'.$linksHtml.'</ul><p>בברכה, כפיים</p>',
        );
    }

    /** Any single-recipient plain-text send: material/expense reminders (FR-5.16/FR-6.9), lead confirmation (FR-1.18) — all business email goes through Smove, never Laravel Mail. */
    public function sendTransactionalEmail(string $toEmail, string $toName, string $subject, string $body): string
    {
        return $this->sendCampaign(
            [['email' => $toEmail, 'name' => $toName]],
            $subject,
            '<p>'.nl2br(e($body)).'</p>',
        );
    }

    /**
     * Every real single/few-recipient send goes through this — see class
     * docblock on why it's a "campaign". Smove silently drops a campaign
     * created with toMembersByEmail if that address isn't already a real,
     * opted-in contact (confirmed 2026-09-02: the campaign call returned
     * success with an id, but its own /Statistics showed 0 sent and
     * /Recipients came back empty) — so each recipient is first upserted as
     * a real contact (canReceiveEmails: true) and referenced by the
     * resulting numeric id via toMembersById instead.
     */
    private function sendCampaign(array $recipients, string $subject, string $bodyHtml): string
    {
        $this->assertConfigured();

        $memberIds = collect($recipients)
            ->filter(fn (array $r) => filled($r['email'] ?? null))
            ->map(fn (array $r) => $this->ensureContact($r))
            ->all();

        if (empty($memberIds)) {
            throw new RuntimeException('אין נמען עם כתובת דוא"ל תקינה לשליחה דרך Smove.');
        }

        $response = $this->http()->post('/Campaigns?sendnow=true', [
            'subject' => $subject,
            'body' => $bodyHtml,
            'toMembersById' => $memberIds,
        ]);

        return $this->referenceOrFail($response);
    }

    /** Upserts a contact (opted in to receive emails) and returns its Smove numeric id. */
    private function ensureContact(array $contact): int
    {
        $response = $this->http()->post('/Contacts?updateIfExists=true&restoreIfDeleted=true&restoreIfUnsubscribed=true', [
            'email' => $this->requiredEmail($contact),
            'firstName' => $contact['name'] ?? null,
            'canReceiveEmails' => true,
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Smove החזירה שגיאה בעדכון איש קשר ('.$response->status().'): '.($response->json('message') ?? trim($response->body())));
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
