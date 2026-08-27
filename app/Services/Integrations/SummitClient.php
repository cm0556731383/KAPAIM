<?php

namespace App\Services\Integrations;

use App\Models\Deal;
use App\Models\Document;
use App\Models\ExternalIntegrationSetting;
use App\Models\Receipt;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Build-plan 12 — real HTTP wiring to Summit (invoices, receipts, credit
 * notes, credit-card charges, standing orders), gated entirely on
 * ExternalIntegrationSetting('summit'). Every call is expected to run inside
 * ExternalOperationRunner::run() — see that class's docblock for why a
 * failure here never blocks/rolls back the local record it accompanies
 * (FR-8.16-FR-8.18).
 *
 * Endpoint paths/payload shape below are a placeholder — no real Summit API
 * contract was available while building this stage (docs/prd.md §1.4 lists
 * only *what* Summit is used for, not its API). Once the business owner has
 * Summit's real API doc, only the methods below need updating; settings
 * storage, the "not configured yet" gate, and every call site stay the same.
 */
class SummitClient
{
    private ?ExternalIntegrationSetting $config = null;

    public function isConfigured(): bool
    {
        return $this->config()->is_active
            && filled($this->setting('base_url'))
            && filled($this->setting('api_key'));
    }

    /** Called from Document::sendTo() the moment an invoice is sent (FR-4.15/FR-4.16). */
    public function issueInvoice(Document $document): string
    {
        $this->assertConfigured();

        $response = $this->http()->post('/invoices', [
            'reference' => "deal-{$document->deal_id}-document-{$document->id}",
            'business_entity_id' => $document->business_entity_id,
            'lines' => $document->lines->map(fn ($line) => [
                'description' => $line->description,
                'quantity' => (float) $line->quantity,
                'unit_price' => (float) $line->unit_price,
                'amount' => (float) $line->amount,
            ])->all(),
        ]);

        return $this->referenceOrFail($response);
    }

    /** Called from Document::sendTo() for a sent credit note (FR-4.5/FR-8.12). */
    public function issueCreditNote(Document $document): string
    {
        $this->assertConfigured();

        $response = $this->http()->post('/credit-notes', [
            'reference' => "deal-{$document->deal_id}-document-{$document->id}",
            'business_entity_id' => $document->business_entity_id,
            'amount' => $document->totalAmount(),
        ]);

        return $this->referenceOrFail($response);
    }

    /** FR-4.23-FR-4.29: called from Receipt::issueFor(). */
    public function issueReceipt(Receipt $receipt): string
    {
        $this->assertConfigured();

        $response = $this->http()->post('/receipts', [
            'reference' => "receipt-{$receipt->id}-invoice-{$receipt->document_id}",
            'payment_id' => $receipt->payment_id,
            'issued_before_payment' => $receipt->issued_before_payment,
        ]);

        return $this->referenceOrFail($response);
    }

    /** Explicit user-triggered action — Deal::chargeCardViaSummit(). */
    public function chargeCard(Deal $deal, float $amount): string
    {
        $this->assertConfigured();

        $response = $this->http()->post('/charges', [
            'reference' => "deal-{$deal->id}",
            'amount' => $amount,
        ]);

        return $this->referenceOrFail($response);
    }

    /**
     * Explicit user-triggered action — Deal::registerStandingOrderWithSummit().
     * $deal->id is sent as Summit's own merchant reference so the later
     * standing-order-collected webhook can echo it straight back to us — see
     * that webhook route's docblock in routes/web.php for this assumption.
     */
    public function registerStandingOrder(Deal $deal): string
    {
        $this->assertConfigured();

        $response = $this->http()->post('/standing-orders', [
            'merchant_reference' => (string) $deal->id,
            'monthly_amount' => $deal->subscription?->monthlyPayment() ?? (float) $deal->agreed_amount,
        ]);

        return $this->referenceOrFail($response);
    }

    private function referenceOrFail(Response $response): string
    {
        if ($response->failed()) {
            throw new RuntimeException('Summit החזירה שגיאה: '.($response->json('message') ?? $response->status()));
        }

        return (string) ($response->json('id') ?? $response->json('reference') ?? (string) now()->timestamp);
    }

    private function assertConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Summit אינה מוגדרת עדיין — יש להזין כתובת שרת ומפתח API בהגדרות המערכת ולהפעיל את האינטגרציה.');
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
            ['system' => 'summit'],
            ['is_active' => false, 'settings' => []],
        );
    }
}
