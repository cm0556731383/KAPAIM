<?php

namespace App\Services\Integrations;

use App\Models\ExternalIntegrationSetting;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Build-plan 12 — real HTTP wiring to Smove (contacts/mailing lists,
 * transactional sends), gated entirely on ExternalIntegrationSetting('smove').
 * Every call is expected to run inside ExternalOperationRunner::run() so a
 * thrown RuntimeException here (not-configured, or Smove itself failing) is
 * always caught, recorded, and never propagated into the local business
 * action that triggered it (FR-8.16-FR-8.18).
 *
 * Endpoint paths/payload shape below are a placeholder — no real Smove API
 * contract was available while building this stage. Once the business owner
 * has one, only the three methods below need updating; settings storage,
 * the "not configured yet" gate, and every call site stay the same.
 */
class SmoveClient
{
    private ?ExternalIntegrationSetting $config = null;

    public function isConfigured(): bool
    {
        return $this->config()->is_active
            && filled($this->setting('base_url'))
            && filled($this->setting('api_key'));
    }

    /** FR-1.17/FR-5.21-FR-5.26: join/remove a lead/customer/supplier on a Smove mailing list. */
    public function syncMailingListMembership(string $listName, string $action, array $contact): string
    {
        $this->assertConfigured();

        $response = $this->http()->post('/lists/membership', [
            'list' => $listName,
            'action' => $action,
            'contact' => $contact,
        ]);

        return $this->referenceOrFail($response);
    }

    /** FR-5.7: the actual outbound send for a materials delivery. */
    public function sendMaterialsEmail(array $recipients, array $attachmentRefs, string $subject): string
    {
        $this->assertConfigured();

        $response = $this->http()->post('/mail/send', [
            'recipients' => $recipients,
            'attachments' => $attachmentRefs,
            'subject' => $subject,
        ]);

        return $this->referenceOrFail($response);
    }

    /** FR-5.16/FR-6.9: the material-delivery / missing-invoice reminder emails. */
    public function sendReminderEmail(string $toEmail, string $toName, string $subject, string $body): string
    {
        $this->assertConfigured();

        $response = $this->http()->post('/mail/send', [
            'to' => ['email' => $toEmail, 'name' => $toName],
            'subject' => $subject,
            'body' => $body,
        ]);

        return $this->referenceOrFail($response);
    }

    private function referenceOrFail(Response $response): string
    {
        if ($response->failed()) {
            throw new RuntimeException('Smove החזירה שגיאה: '.($response->json('message') ?? $response->status()));
        }

        return (string) ($response->json('id') ?? $response->json('reference') ?? (string) now()->timestamp);
    }

    private function assertConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Smove אינה מוגדרת עדיין — יש להזין כתובת שרת ומפתח API בהגדרות המערכת ולהפעיל את האינטגרציה.');
        }
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl((string) $this->setting('base_url'))
            ->withToken((string) $this->setting('api_key'))
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
