<?php

namespace App\Services\Integrations;

use App\Models\BusinessEntity;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\Document;
use App\Models\ExternalIntegrationSetting;
use App\Models\ExternalOperation;
use App\Models\Receipt;
use App\Models\Subscription;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Real HTTP wiring to SUMIT (formerly OfficeGuy) — invoices, receipts,
 * credit notes, credit-card charges, standing orders — per SUMIT's own
 * OpenAPI contract (app.sumit.co.il/swagger/v1/swagger.json, wired
 * 2026-10-07). Gated on ExternalIntegrationSetting('summit') being active
 * (plus its global test_mode); the credentials themselves are per business
 * entity — each עוסק is its own SUMIT company (BusinessEntity::
 * sumit_company_id/sumit_api_key), and every call goes to the company the
 * document was issued from (entityFor()). Every
 * call is expected to run inside ExternalOperationRunner::run() — see that
 * class's docblock for why a failure here never blocks/rolls back the local
 * record it accompanies (FR-8.16-FR-8.18).
 *
 * SUMIT's API shape: one fixed host, every call a POST with a JSON body
 * carrying `Credentials: {CompanyID, APIKey}` (no auth header), and every
 * response wrapped as `{Status, UserErrorMessage, TechnicalErrorDetails,
 * Data}` where Status 0 = success — an HTTP 200 alone means nothing.
 * Enum fields (document type, customer search mode, payment type) are sent
 * as their integer values.
 *
 * The SUMIT customer is matched by ExternalIdentifier ("kapaim-customer-
 * {id}") so every document for the same customer lands on one SUMIT card.
 *
 * Test mode (settings.test_mode): documents are created as SUMIT drafts and
 * card/recurring charges are authorisation-only, so the business owner can
 * try the connection against the real account without issuing real tax
 * documents or moving money.
 */
class SummitClient
{
    private const BASE_URL = 'https://api.sumit.co.il';

    // Accounting_Typed_DocumentType
    private const DOCUMENT_INVOICE = 0;
    private const DOCUMENT_RECEIPT = 2;
    private const DOCUMENT_PROFORMA_INVOICE = 3;
    private const DOCUMENT_CREDIT_INVOICE = 5;
    private const DOCUMENT_CREDIT_RECEIPT = 7;

    /** SUMIT refuses a tax invoice (and its credit) from an exempt dealer. */
    private const EXEMPT_CLASSIFICATION = 'עוסק פטור';

    // Accounting_Typed_CustomerSearchMode
    private const SEARCH_BY_EXTERNAL_IDENTIFIER = 2;

    // Accounting_Typed_DocumentPaymentType => the Details_* object SUMIT expects with it
    private const PAYMENT_TYPES = [
        'card' => [5, 'Details_CreditCard'],
        'bank_transfer' => [3, 'Details_BankTransfer'],
        'check' => [4, 'Details_Cheque'],
        'cash' => [2, 'Details_Cash'],
    ];
    private const PAYMENT_GENERAL = [1, 'Details_General'];

    private ?ExternalIntegrationSetting $config = null;

    public function isActive(): bool
    {
        return $this->config()->is_active;
    }

    /** Defaults to ON until the business owner saves the settings with it turned off (same default as the settings screen). */
    public function isTestMode(): bool
    {
        return (bool) ($this->setting('test_mode') ?? true);
    }

    /**
     * Read-only credentials check for the settings screen — asks SUMIT for
     * today's VAT rate, which needs valid credentials but changes nothing.
     */
    public function verifyCredentials(BusinessEntity $entity): void
    {
        if (! $entity->hasSumitCredentials()) {
            throw new RuntimeException("יש להזין מספר חברה ומפתח API בחיבור SUMIT \"{$entity->sumitConnectionLabel()}\".");
        }

        $this->dataOrFail($this->post($entity, '/accounting/general/getvatrate/', []));
    }

    /**
     * Called from Document::sendTo() when an invoice is sent (FR-4.15/
     * FR-4.16). SUMIT emails the issued invoice itself to $email.
     */
    public function issueInvoice(Document $document, ?string $email = null): string
    {
        $entity = $this->entityFor($document->businessEntity, $document->deal);
        $document->loadMissing('lines', 'deal.customer.school', 'deal.customer.contacts');

        // An exempt dealer (עוסק פטור) can't issue a tax invoice — its "invoice"
        // goes out as a חשבון עסקה, and the receipt is issued later as usual.
        $type = $entity->classification === self::EXEMPT_CLASSIFICATION ? self::DOCUMENT_PROFORMA_INVOICE : self::DOCUMENT_INVOICE;

        $data = $this->dataOrFail($this->post($entity, '/accounting/documents/create/', [
            'Details' => $this->documentDetails($type, $document->deal, $email),
            'Items' => $this->documentItems($document),
            'VATIncluded' => true,
        ]));
        $document->deal->lockToBusinessEntity($entity);

        return (string) $data['DocumentID'];
    }

    /** Called from Document::sendTo() for a sent credit note (FR-4.5/FR-8.12), linked to the deal's SUMIT invoice. */
    public function issueCreditNote(Document $document, ?string $email = null): string
    {
        $entity = $this->entityFor($document->businessEntity, $document->deal);
        $document->loadMissing('lines', 'deal.customer.school', 'deal.customer.contacts');

        // For an exempt dealer the invoice was a חשבון עסקה — not an accounting
        // document, so there's nothing to credit against it; a קבלה זיכוי is.
        // Like any receipt it records a payment (the refund, sent as a positive
        // amount — SUMIT stores it negated), not items: SUMIT rejects it with
        // "Missing payments" otherwise (confirmed against SUMIT, 2026-10-13).
        $exempt = $entity->classification === self::EXEMPT_CLASSIFICATION;
        $document->deal->loadMissing('paymentMethod');
        [$paymentType, $detailsKey] = self::PAYMENT_TYPES[$document->deal->paymentMethod?->type] ?? self::PAYMENT_GENERAL;

        $data = $this->dataOrFail($this->post($entity, '/accounting/documents/create/', array_filter([
            'Details' => $this->documentDetails($exempt ? self::DOCUMENT_CREDIT_RECEIPT : self::DOCUMENT_CREDIT_INVOICE, $document->deal, $email),
            'Items' => $exempt ? null : $this->documentItems($document),
            'Payments' => $exempt ? [['Amount' => $document->totalAmount(), 'Type' => $paymentType, $detailsKey => (object) []]] : null,
            'VATIncluded' => true,
            'OriginalDocumentID' => $exempt ? null : $this->sumitDocumentId($document->deal->issuedInvoice()),
        ], fn ($v) => $v !== null)));
        $document->deal->lockToBusinessEntity($entity);

        return (string) $data['DocumentID'];
    }

    /**
     * FR-4.23-FR-4.29: called from Receipt::issueFor(). A receipt for a real
     * payment records that payment's amount and method; a "receipt before
     * payment" (no payment yet) is issued for the invoice total.
     */
    public function issueReceipt(Receipt $receipt): string
    {
        $receipt->loadMissing('payment.paymentMethod', 'document.businessEntity', 'document.lines', 'document.deal.paymentMethod', 'document.deal.customer.school', 'document.deal.customer.contacts');

        $invoice = $receipt->document;
        $entity = $this->entityFor($invoice->businessEntity, $invoice->deal);
        $amount = $receipt->payment ? (float) $receipt->payment->amount : $invoice->totalAmount();
        // A receipt before payment has no payment of its own — it goes by the
        // deal's payment method (SUMIT rejects a "general" payment without a
        // payer: "יש להזין מוטב/מחויב").
        $methodType = $receipt->payment?->paymentMethod?->type ?? $invoice->deal->paymentMethod?->type;
        [$paymentType, $detailsKey] = self::PAYMENT_TYPES[$methodType] ?? self::PAYMENT_GENERAL;

        // SUMIT emails the receipt to the customer's billing address — the same
        // one on the SUMIT customer card (accounting contact, else primary, else school).
        $email = $this->customer($invoice->deal)['EmailAddress'] ?? null;

        $data = $this->dataOrFail($this->post($entity, '/accounting/documents/create/', array_filter([
            'Details' => $this->documentDetails(self::DOCUMENT_RECEIPT, $invoice->deal, $email),
            'Payments' => [[
                'Amount' => $amount,
                'Type' => $paymentType,
                $detailsKey => (object) [],
            ]],
            'OriginalDocumentID' => $this->sumitDocumentId($invoice),
        ], fn ($v) => $v !== null)));
        $invoice->deal->lockToBusinessEntity($entity);

        return (string) $data['DocumentID'];
    }

    /**
     * Explicit user-triggered action — Deal::chargeCardViaSummit(). Charges
     * the card already saved on the customer's SUMIT card (this app never
     * handles card numbers). SUMIT's automatic invoice/receipt is suppressed:
     * this app issues those itself through its own invoice/receipt flow.
     */
    public function chargeCard(Deal $deal, float $amount): string
    {
        $entity = $this->entityForDeal($deal);
        $deal->loadMissing('customer.school', 'customer.contacts');

        $data = $this->dataOrFail($this->post($entity, '/billing/payments/charge/', [
            'Customer' => $this->customer($deal),
            'Items' => [[
                'Item' => ['Name' => $this->itemName($deal)],
                'Quantity' => 1,
                'UnitPrice' => $amount,
            ]],
            'VATIncluded' => true,
            'PreventDocumentCreation' => true,
            'AuthoriseOnly' => $this->isTestMode() ?: null,
        ]));

        $payment = $data['Payment'] ?? [];

        if (! ($payment['ValidPayment'] ?? false)) {
            throw new RuntimeException('החיוב נדחה: '.($payment['StatusDescription'] ?? 'ללא פירוט'));
        }

        $deal->lockToBusinessEntity($entity);

        return (string) ($payment['ID'] ?? '');
    }

    /**
     * Explicit user-triggered action — Deal::registerStandingOrderWithSummit().
     * Sets up a monthly recurring charge on the customer's saved SUMIT payment
     * method: the subscription's monthly payment, for its number of
     * deliveries (or the deal amount once, monthly, with no subscription).
     */
    public function registerStandingOrder(Deal $deal): string
    {
        $entity = $this->entityForDeal($deal);
        $deal->loadMissing('customer.school', 'customer.contacts', 'subscription');

        $subscription = $deal->subscription;

        $data = $this->dataOrFail($this->post($entity, '/billing/recurring/charge/', [
            'Customer' => $this->customer($deal),
            'Items' => [[
                'Item' => ['Name' => $this->itemName($deal)],
                'Quantity' => 1,
                'UnitPrice' => $subscription ? $subscription->monthlyPayment() : (float) $deal->agreed_amount,
                'Duration_Months' => 1,
                'Recurrence' => $subscription ? Subscription::TOTAL_DELIVERIES : 1,
                'Description' => "עסקה #{$deal->id}",
            ]],
            'VATIncluded' => true,
            'AuthoriseOnly' => $this->isTestMode() ?: null,
        ]));
        $deal->lockToBusinessEntity($entity);

        return implode(',', (array) ($data['RecurringCustomerItemIDs'] ?? []));
    }

    private function documentDetails(int $type, Deal $deal, ?string $email): array
    {
        return array_filter([
            'Type' => $type,
            'Customer' => $this->customer($deal),
            'Description' => $this->itemName($deal),
            'IsDraft' => $this->isTestMode() ?: null,
            'SendByEmail' => $email ? ['EmailAddress' => $email, 'Original' => true] : null,
        ], fn ($v) => $v !== null);
    }

    private function documentItems(Document $document): array
    {
        return $document->lines->map(fn ($line) => [
            'Item' => ['Name' => $line->description],
            'Quantity' => (float) $line->quantity,
            'UnitPrice' => (float) $line->unit_price,
            'TotalPrice' => (float) $line->amount,
        ])->values()->all();
    }

    /** The SUMIT customer for $deal — the customer card's billing details, matched by this app's own id. */
    private function customer(Deal $deal): array
    {
        $customer = $deal->customer;
        $school = $customer?->school;
        $contacts = $customer?->contacts ?? collect();
        $contact = $contacts->first(fn (Contact $c) => $c->is_accounting_contact && filled($c->email))
            ?? $contacts->first(fn (Contact $c) => $c->is_primary && filled($c->email));

        return array_filter([
            'ExternalIdentifier' => 'kapaim-customer-'.$customer?->id,
            'SearchMode' => self::SEARCH_BY_EXTERNAL_IDENTIFIER,
            'Name' => $school?->invoice_name ?: ($school?->name ?? 'לקוחה #'.$customer?->id),
            'CompanyNumber' => $school?->business_number,
            'EmailAddress' => $contact?->email ?? $school?->email,
            'Phone' => $school?->phone ?? $contact?->phone,
            'City' => $school?->city,
            'Address' => $school?->address,
        ], fn ($v) => filled($v));
    }

    private function itemName(Deal $deal): string
    {
        return $deal->program_name_snapshot ?? $deal->bundle_name_snapshot ?? "עסקה #{$deal->id}";
    }

    /** SUMIT's DocumentID for a document this app already issued there (the issue_invoice operation's reference). */
    private function sumitDocumentId(?Document $document): ?int
    {
        if (! $document) {
            return null;
        }

        $reference = ExternalOperation::where('document_id', $document->id)
            ->where('system', 'summit')
            ->where('operation_type', 'issue_invoice')
            ->where('status', ExternalOperation::STATUS_SUCCESS)
            ->latest('id')
            ->value('external_reference');

        return ctype_digit((string) $reference) ? (int) $reference : null;
    }

    /**
     * The SUMIT company a document is issued in — its business entity, which
     * must have credentials and, once $deal is locked to an entity
     * (Deal::lockToBusinessEntity()), must be that same one.
     */
    private function entityFor(?BusinessEntity $entity, ?Deal $deal = null): BusinessEntity
    {
        if (! $this->isActive()) {
            throw new RuntimeException('החיבור ל-SUMIT מושבת — יש להפעיל אותו בהגדרות המערכת.');
        }

        // A document without its own entity (e.g. an older credit note) goes to the deal's locked one.
        $entity ??= $deal?->businessEntity;

        if (! $entity) {
            throw new RuntimeException('למסמך לא נבחר עוסק, ולכן לא ידוע לאיזו חברה ב-SUMIT לשלוח אותו.');
        }

        if ($deal?->business_entity_id && $deal->business_entity_id !== $entity->id) {
            throw new RuntimeException("לעסקה #{$deal->id} כבר הופקו מסמכים ב-SUMIT מהעוסק \"{$deal->businessEntity?->name}\" — כל מסמכי העסקה מופקים מאותו עוסק, ולא מ\"{$entity->name}\".");
        }

        if (! $entity->hasSumitCredentials()) {
            throw new RuntimeException("לחיבור SUMIT \"{$entity->sumitConnectionLabel()}\" (עוסק \"{$entity->name}\") לא הוגדרו מספר חברה ומפתח API — יש להזין אותם בהגדרות המערכת.");
        }

        return $entity;
    }

    /**
     * A charge/standing order has no document of its own: it goes to the
     * entity the deal is locked to, else the company of the deal's invoice
     * (issued, else any), or — when there's
     * no invoice yet — to the only entity with SUMIT credentials, if there
     * is exactly one.
     */
    private function entityForDeal(Deal $deal): BusinessEntity
    {
        if ($deal->business_entity_id) {
            return $this->entityFor($deal->businessEntity, $deal);
        }

        $invoice = $deal->issuedInvoice() ?? $deal->documents()->where('document_type', 'invoice')->latest('id')->first();

        if ($invoice) {
            return $this->entityFor($invoice->businessEntity, $deal);
        }

        $configured = BusinessEntity::where('is_active', true)->get()->filter->hasSumitCredentials();

        if ($configured->count() !== 1) {
            throw new RuntimeException('לעסקה אין עדיין חשבונית, ולכן לא ידוע מאיזו חברה ב-SUMIT לחייב — יש להפיק חשבונית תחילה.');
        }

        return $this->entityFor($configured->first());
    }

    private function post(BusinessEntity $entity, string $path, array $body): Response
    {
        return $this->http()->post($path, array_merge($body, [
            'Credentials' => [
                'CompanyID' => (int) $entity->sumit_company_id,
                'APIKey' => (string) $entity->sumit_api_key,
            ],
        ]));
    }

    /** Unwraps SUMIT's {Status, UserErrorMessage, TechnicalErrorDetails, Data} envelope — Status 0 is the only success. */
    private function dataOrFail(Response $response): array
    {
        if ($response->failed()) {
            throw new RuntimeException('SUMIT החזירה שגיאה ('.$response->status().'): '.(trim($response->body()) ?: 'ללא פירוט'));
        }

        $status = $response->json('Status');

        if ($status !== 0 && $status !== '0' && ! str_starts_with((string) $status, 'Success')) {
            $message = $response->json('UserErrorMessage') ?: $response->json('TechnicalErrorDetails') ?: 'שגיאה לא מפורטת';

            throw new RuntimeException('SUMIT: '.$message);
        }

        return (array) ($response->json('Data') ?? []);
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl(self::BASE_URL)
            ->acceptJson()
            ->asJson()
            ->timeout(30);
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
