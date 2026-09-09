<?php

namespace App\Models;

use App\Services\ActivityLogger;
use App\Services\DocumentLinkedFields;
use App\Services\Integrations\ExternalOperationRunner;
use App\Services\Integrations\SmoveClient;
use App\Services\Integrations\SummitClient;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;
use RuntimeException;

/**
 * Build-plan 07 — DOCUMENT. The only place a Document row is ever created is
 * generateFor() below, which enforces the mandatory business-sequencing gate
 * (FR-4.3/FR-4.4, FR-8.7/FR-8.8) the same way Deal::createForCustomer() and
 * Deal::updateStatusWithLock() enforce Deal's business rules — a plain
 * static factory that throws RuntimeException on a rule violation, caught by
 * the caller into a friendly error banner.
 *
 * A document is never deleted, even when superseded (matches every other
 * business-record convention in this codebase).
 */
#[Fillable([
    'deal_id', 'expense_id', 'document_template_id', 'business_entity_id', 'preceding_document_id',
    'document_type', 'status_id', 'format', 'file_reference', 'rendered_content',
    'field_values', 'sent_at', 'received_at', 'signed_at', 'confirmed_at', 'viewed_at',
])]
class Document extends Model
{
    use HasFactory;

    public const DEFAULT_STATUS_NAME = 'טיוטה';

    public const TYPE_LABELS = [
        'quote' => 'הצעת מחיר',
        'order_form' => 'טופס הזמנה',
        'contract' => 'חוזה',
        'invoice' => 'חשבונית',
        // Build-plan 09: generated on-demand at subscription cancellation
        // only (Document::generateCreditNoteFor()) — deliberately absent from
        // PRECEDING_TYPE below since it isn't part of the normal chain.
        'credit_note' => 'חשבונית זיכוי',
        // Build-plan 11: generated standalone whenever an expense needs its
        // invoice attached (Expense::attachInvoice()) — never part of the
        // sales chain, so also deliberately absent from PRECEDING_TYPE.
        'expense_invoice' => 'חשבונית הוצאה',
    ];

    /** Chain order: quote -> order_form -> contract -> invoice (FR-4.1). */
    private const PRECEDING_TYPE = [
        'order_form' => 'quote',
        'contract' => 'order_form',
        'invoice' => 'contract',
    ];

    protected function casts(): array
    {
        return [
            'field_values' => 'array',
            'sent_at' => 'datetime',
            'received_at' => 'datetime',
            'signed_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'viewed_at' => 'datetime',
        ];
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    /** Build-plan 11 — set only for an 'expense_invoice' document (never alongside deal_id). */
    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    public function documentTemplate(): BelongsTo
    {
        return $this->belongsTo(DocumentTemplate::class);
    }

    public function businessEntity(): BelongsTo
    {
        return $this->belongsTo(BusinessEntity::class);
    }

    public function precedingDocument(): BelongsTo
    {
        return $this->belongsTo(self::class, 'preceding_document_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(StatusDefinition::class, 'status_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(DocumentLine::class)->orderBy('sort_order');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(DocumentRecipient::class);
    }

    /**
     * FR-4.3/FR-4.4/FR-4.21/FR-4.22 (FR-8.7/FR-8.8): true if $documentType
     * may currently be generated for $deal. UI code calls this to
     * enable/disable the "generate next document" buttons; generateFor()
     * below re-checks the same rule server-side via assertCanGenerate().
     */
    public static function canGenerate(Deal $deal, string $documentType): bool
    {
        try {
            self::assertCanGenerate($deal, $documentType);

            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    /**
     * The only place a Document row is ever created. $subject is a Deal for
     * every document type in the normal sales chain (quote/order_form/
     * contract/invoice/credit_note) — or, build-plan 11, an Expense for the
     * standalone 'expense_invoice' type (Expense::attachInvoice(), never
     * called anywhere else). An expense-invoice document never belongs to a
     * deal and a sales-chain document never belongs to an expense — see the
     * documents-table exactly-one-of-deal_id/expense_id CHECK constraint.
     *
     * $businessEntityId is required (and validated active) for a deal
     * invoice only (FR-4.15/FR-4.16, FR-8.9); it is meaningless for an
     * expense invoice (that represents an incoming document FROM the
     * supplier, not one of our own business entities issuing something) and
     * is always ignored when $subject is an Expense.
     *
     * @throws RuntimeException on a business-rule violation.
     */
    public static function generateFor(
        Deal|Expense $subject,
        DocumentTemplate $template,
        string $format = 'digital',
        ?int $businessEntityId = null,
    ): self {
        self::assertCanGenerate($subject, $template->document_type);

        if ($subject instanceof Expense) {
            return self::createDocumentRow([
                'deal_id' => null,
                'expense_id' => $subject->id,
                'document_template_id' => $template->id,
                'business_entity_id' => null,
                'preceding_document_id' => null,
                'document_type' => $template->document_type,
                'status_id' => self::defaultDraftStatus()->id,
                'format' => $format,
                'field_values' => [],
                'rendered_content' => $template->renderContent([]),
            ]);
        }

        $deal = $subject;
        $businessEntity = null;

        if ($template->document_type === 'invoice') {
            if (! $businessEntityId) {
                throw new RuntimeException('יש לבחור עוסק פטור בעת הפקת חשבונית.');
            }

            $businessEntity = BusinessEntity::find($businessEntityId);

            if (! $businessEntity || ! $businessEntity->is_active) {
                throw new RuntimeException('העוסק שנבחר אינו פעיל — לא ניתן להפיק חשבונית ממנו.');
            }
        }

        $preceding = self::precedingFor($deal, $template->document_type);
        $fieldValues = self::buildInitialFieldValues($template, $deal, $preceding);

        return self::createDocumentRow([
            'deal_id' => $deal->id,
            'expense_id' => null,
            'document_template_id' => $template->id,
            'business_entity_id' => $businessEntity?->id,
            'preceding_document_id' => $preceding?->id,
            'document_type' => $template->document_type,
            'status_id' => self::defaultDraftStatus()->id,
            'format' => $format,
            'field_values' => $fieldValues,
            'rendered_content' => $template->renderContent(
                collect($fieldValues)->map(fn ($v) => $v['value'])->all()
            ),
        ]);
    }

    /** Shared by every generateFor()/generateCreditNoteFor() branch above — the sole INSERT point for this table. */
    private static function createDocumentRow(array $attributes): self
    {
        return self::create($attributes);
    }

    private static function defaultDraftStatus(): StatusDefinition
    {
        return StatusDefinition::firstOrCreate(
            ['scope' => 'document', 'name' => self::DEFAULT_STATUS_NAME],
            ['is_active' => true, 'sort_order' => 1],
        );
    }

    /**
     * @throws RuntimeException on a business-rule violation.
     */
    private static function assertCanGenerate(Deal|Expense $subject, string $documentType): void
    {
        if ($subject instanceof Expense) {
            if ($documentType !== 'expense_invoice') {
                throw new RuntimeException('סוג מסמך זה אינו נתמך עבור הוצאה.');
            }

            if ($subject->document_id) {
                throw new RuntimeException('להוצאה זו כבר מצורפת חשבונית — לא ניתן לצרף יותר מחשבונית אחת לכל הוצאה.');
            }

            return;
        }

        $deal = $subject;

        if ($documentType === 'contract') {
            $hasReceivedOrderForm = self::where('deal_id', $deal->id)
                ->where('document_type', 'order_form')
                ->whereNotNull('received_at')
                ->exists();

            if (! $hasReceivedOrderForm) {
                throw new RuntimeException('לא ניתן להפיק חוזה לפני שהתקבל טופס הזמנה עבור עסקה זו.');
            }
        }

        if ($documentType === 'invoice') {
            $hasSignedContract = self::where('deal_id', $deal->id)
                ->where('document_type', 'contract')
                ->whereNotNull('signed_at')
                ->exists();

            if (! $hasSignedContract) {
                throw new RuntimeException('לא ניתן להפיק חשבונית לפני שהתקבל חוזה חתום עבור עסקה זו.');
            }

            $alreadyHasInvoice = self::where('deal_id', $deal->id)->where('document_type', 'invoice')->exists();

            if ($alreadyHasInvoice) {
                throw new RuntimeException('לעסקה זו כבר הופקה חשבונית — ניתן להפיק חשבונית אחת בלבד לכל עסקה.');
            }
        }

        if ($documentType === 'credit_note') {
            $hasInvoice = self::where('deal_id', $deal->id)->where('document_type', 'invoice')->exists();

            if (! $hasInvoice) {
                throw new RuntimeException('לא ניתן להפיק חשבונית זיכוי לעסקה שלא הופקה עבורה חשבונית.');
            }
        }
    }

    /**
     * Build-plan 09 — the only place a credit_note Document is ever created,
     * called from Subscription::generateCreditNote() at subscription
     * cancellation. Reuses generateFor()/assertCanGenerate() for the FR-4.5/
     * FR-8.12 "deal must already have an invoice" gate rather than a
     * parallel check, and inherits that invoice's business entity
     * automatically (no separate prompt needed — it's the same deal).
     *
     * @throws RuntimeException on a business-rule violation, or when no
     *                          active credit_note template exists yet.
     */
    public static function generateCreditNoteFor(Deal $deal, float $creditAmount): self
    {
        $template = DocumentTemplate::where('document_type', 'credit_note')->where('is_active', true)->orderBy('id')->first();

        if (! $template) {
            throw new RuntimeException('לא נמצאה תבנית פעילה עבור חשבונית זיכוי — יש להגדיר תבנית תחילה במסך תבניות מסמכים.');
        }

        $invoice = $deal->documents()->where('document_type', 'invoice')->latest('id')->first();

        $document = self::generateFor($deal, $template, 'digital', $invoice?->business_entity_id);

        $document->addLine('זיכוי בגין ביטול מנוי', $creditAmount);

        return $document;
    }

    private static function precedingFor(Deal $deal, string $documentType): ?self
    {
        $precedingType = self::PRECEDING_TYPE[$documentType] ?? null;

        if (! $precedingType) {
            return null;
        }

        return self::where('deal_id', $deal->id)->where('document_type', $precedingType)->latest('id')->first();
    }

    /**
     * FR-4.13: a contract auto-fills from the immediately preceding order
     * form's captured field values, matched by the same linked_field key.
     * Any field the new template doesn't get from the preceding document
     * falls back to a live DocumentLinkedFields::resolve() lookup (used for
     * the very first document in a chain, e.g. the order form itself).
     */
    private static function buildInitialFieldValues(DocumentTemplate $template, Deal $deal, ?self $preceding): array
    {
        $precedingByLinkedField = $preceding
            ? collect($preceding->field_values ?? [])
                ->filter(fn ($v) => ! empty($v['linked_field']))
                ->keyBy('linked_field')
            : collect();

        $values = [];

        foreach ($template->fields as $field) {
            $value = '';

            if ($field->field_type === 'linked' && $field->linked_field) {
                $value = $precedingByLinkedField->has($field->linked_field)
                    ? (string) $precedingByLinkedField[$field->linked_field]['value']
                    : (string) (DocumentLinkedFields::resolve($field->linked_field, $deal) ?? '');
            }

            $values[$field->id] = [
                'name' => $field->name,
                'field_type' => $field->field_type,
                'linked_field' => $field->linked_field,
                'value' => $value,
            ];
        }

        return $values;
    }

    /**
     * The internal "digital form fill" step (FR-4.10/FR-4.11): this MVP has
     * no external customer portal yet, so this records what an order
     * form/contract said on the customer's behalf, filled in by staff — see
     * build-plan 07's report for the stage-12 gap this leaves open.
     * FR-4.12: a linked field's submitted value updates the underlying
     * business record.
     *
     * @param  array<int, string>  $valuesByFieldId  document_template_field_id => value
     *
     * @throws RuntimeException when a required field is left empty.
     */
    public function submitFieldValues(array $valuesByFieldId): void
    {
        $stored = [];

        foreach ($this->documentTemplate->fields as $field) {
            $value = trim((string) ($valuesByFieldId[$field->id] ?? ($this->field_values[$field->id]['value'] ?? '')));

            if ($field->is_required && $value === '') {
                throw new RuntimeException("השדה \"{$field->name}\" הוא שדה חובה.");
            }

            $stored[$field->id] = [
                'name' => $field->name,
                'field_type' => $field->field_type,
                'linked_field' => $field->linked_field,
                'value' => $value,
            ];

            if ($field->field_type === 'linked' && $field->linked_field && $value !== '') {
                DocumentLinkedFields::applyBack($field->linked_field, $this->deal, $value);
            }
        }

        $this->update([
            'field_values' => $stored,
            'rendered_content' => $this->documentTemplate->renderContent(
                collect($stored)->map(fn ($v) => $v['value'])->all()
            ),
        ]);
    }

    /** FR-4.3/FR-8.7's prerequisite: an order form is "received" once this is set. */
    public function markReceived(): void
    {
        $this->update(['received_at' => now()]);
    }

    /** FR-4.4/FR-8.8's prerequisite: a contract is "signed" once this is set. */
    public function markSigned(): void
    {
        $this->update(['signed_at' => now()]);
    }

    /** The link embedded in a "digital form" send — a signed route, no login required (see routes/web.php). */
    public function signUrl(): string
    {
        return URL::signedRoute('documents.sign', ['document' => $this->id]);
    }

    /** The link embedded in a "PDF" send — a signed route that renders the document as a real PDF file (see routes/web.php). */
    public function pdfUrl(): string
    {
        return URL::signedRoute('documents.pdf', ['document' => $this->id]);
    }

    /**
     * The "קבלת המסמך כ-PDF" button on a digital-form send (SmoveClient::
     * sendDocumentEmail()'s $requestPdfUrl) — hits documents.email-pdf,
     * which sends $email a brand-new Smove campaign with the PDF actually
     * attached (see requestPdfBySmove() and smoveAttachmentUrl()). The
     * recipient isn't logged in when clicking this, so who to send to
     * travels signed in the URL itself, same as every other document/
     * materials link.
     */
    public function emailPdfUrl(string $email, string $name): string
    {
        return URL::signedRoute('documents.email-pdf', ['document' => $this->id, 'email' => $email, 'name' => $name]);
    }

    /**
     * The URL handed to Smove's `campaignAttachments` (SmoveClient::
     * sendCampaign()) — Smove's servers fetch this URL themselves and attach
     * the actual bytes, so it must be reachable with no auth. Confirmed
     * empirically against Smove's real API (2026-09-07): a query string
     * anywhere in the URL — even a harmless `?x=1` — makes the whole
     * campaign call fail with `ErrAttachments`, and the path must end in a
     * real file extension (`.pdf`); Laravel's normal `signed` middleware
     * (query-string based, used by pdfUrl()/signUrl() above) is therefore
     * unusable here. This carries its own HMAC+expiry baked into the path
     * itself instead (see verifySmoveAttachmentToken(), the documents.
     * pdf-file route in routes/web.php) — same security property as a
     * signed route, just shaped to satisfy Smove's requirement.
     */
    public function smoveAttachmentUrl(): string
    {
        $expires = now()->addDays(14)->timestamp;

        return route('documents.pdf-file', ['token' => "{$this->id}-{$expires}-".$this->smoveAttachmentHash($expires).'.pdf']);
    }

    /** documents.pdf-file's guard (routes/web.php) — returns the document if $token is a genuine, unexpired smoveAttachmentUrl() token, null otherwise. */
    public static function verifySmoveAttachmentToken(string $token): ?self
    {
        if (! preg_match('/^(\d+)-(\d+)-([a-f0-9]{32})\.pdf$/', $token, $m)) {
            return null;
        }

        [, $id, $expires, $hash] = $m;

        if ((int) $expires < now()->timestamp) {
            return null;
        }

        $document = self::find((int) $id);

        if (! $document || ! hash_equals($document->smoveAttachmentHash((int) $expires), $hash)) {
            return null;
        }

        return $document;
    }

    private function smoveAttachmentHash(int $expires): string
    {
        return substr(hash_hmac('sha256', "{$this->id}.{$expires}", config('app.key')), 0, 32);
    }

    /**
     * The actual PDF bytes for this document — shared by documents.pdf
     * (direct in-browser download) and documents.pdf-file (fetched
     * server-side by Smove for a real email attachment), so both stay in
     * sync with a single mpdf setup. See documents.pdf's own docblock
     * (routes/web.php) for why mpdf over dompdf (Hebrew bidi rendering).
     *
     * error_reporting() is lowered only around the two mpdf calls below,
     * always restored in `finally`: confirmed 2026-09-08 against a real
     * contract (rich HTML pasted from Word, several nested dir="LTR"/
     * dir="RTL" spans around a `<br>`) that mpdf's bidi-override bookkeeping
     * (Mpdf\Tag\Br) throws a plain PHP warning ("Undefined array key 0") on
     * that shape — mpdf's own long-standing behavior on older/looser PHP,
     * harmless there, but Laravel's HandleExceptions escalates it to a
     * fatal ErrorException in a real request (not in a CLI/tinker run,
     * which is why this went unnoticed until a real contract hit it in
     * production — a 500 instead of the attached PDF). The render itself is
     * unaffected either way — same correct, complete PDF bytes with or
     * without the warning surfacing — so suppressing mpdf's noise here is
     * the standard workaround for this library rather than chasing every
     * individual internal warning site.
     *
     * @return array{binary: string, fileName: string}
     */
    public function renderPdfBinary(): array
    {
        $this->loadMissing(['deal.customer.school', 'businessEntity', 'lines']);

        $fileName = (self::TYPE_LABELS[$this->document_type] ?? $this->document_type).'-'.$this->id.'.pdf';

        $mpdf = new \Mpdf\Mpdf(['default_font' => 'dejavusans', 'directionality' => 'rtl']);

        $previousErrorReporting = error_reporting(E_ERROR | E_PARSE | E_COMPILE_ERROR | E_CORE_ERROR);

        try {
            $mpdf->WriteHTML(view('documents.pdf', ['document' => $this])->render());
            $binary = $mpdf->Output($fileName, \Mpdf\Output\Destination::STRING_RETURN);
        } finally {
            error_reporting($previousErrorReporting);
        }

        return ['binary' => $binary, 'fileName' => $fileName];
    }

    /**
     * emailPdfUrl()'s click — sends $email a brand-new Smove campaign with
     * the real PDF file attached via smoveAttachmentUrl() (see that
     * method's docblock: Smove fetches the file itself, no Laravel Mail
     * involved — "כל פעולות הדיוור מתבצעות באמצעות Smove" applies here too,
     * per the business owner's 2026-09-07 instruction). Not idempotency-
     * guarded like confirm()/acknowledge() — re-clicking is a deliberate
     * "send it to me again", not a one-time action.
     */
    public function requestPdfBySmove(string $email, string $name, SmoveClient $smove, ActivityLogger $logger): void
    {
        $typeLabel = self::TYPE_LABELS[$this->document_type] ?? $this->document_type;

        $smove->sendDocumentAttachmentEmail(
            $email, $name, $typeLabel, "מצורף בזאת קובץ ה-PDF של \"{$typeLabel}\" שביקשת.", $this->smoveAttachmentUrl(),
        );

        $logger->log('document.pdf_emailed', "נשלח קובץ PDF של \"{$typeLabel}\" למייל {$email} עבור עסקה #{$this->deal_id}", [
            'document_id' => $this->id,
            'deal_id' => $this->deal_id,
            'user' => null,
        ]);
    }

    /**
     * documents.pdf's content (resources/views/documents/pdf.blade.php) —
     * same underlying values as rendered_content, just with a blank line to
     * write on by hand instead of a bracket for any field still empty (a
     * printed PDF has no interactive input the way the online form does).
     */
    public function printFriendlyContent(): string
    {
        return $this->documentTemplate->renderContent(
            collect($this->field_values ?? [])->map(fn ($v) => $v['value'])->all(),
            printFriendly: true,
        );
    }

    /**
     * The online form's "אישור וחתימה" click — a simple click-to-confirm,
     * not a drawn signature. Generic across every document_type (including
     * quote/invoice/credit_note, which have no dedicated status column of
     * their own), but for order_form/contract it also fires the existing,
     * unchanged FR-4.3/FR-4.4 gate methods above so nothing downstream
     * (Document::assertCanGenerate()) needs to know this route exists.
     * Idempotent: revisiting an already-confirmed link is a no-op rather
     * than an error, so the same emailed link can be opened more than once.
     *
     * @param  array<int, string>  $fieldValues  Keyed by document_template_field_id, same shape submitFieldValues() expects.
     */
    public function confirm(array $fieldValues, ActivityLogger $logger): void
    {
        if ($this->confirmed_at) {
            return;
        }

        if ($this->documentTemplate->fields->isNotEmpty()) {
            $this->submitFieldValues($fieldValues);
        }

        $this->update(['confirmed_at' => now()]);

        if ($this->document_type === 'order_form' && ! $this->received_at) {
            $this->markReceived();
        }

        if ($this->document_type === 'contract' && ! $this->signed_at) {
            $this->markSigned();
        }

        $typeLabel = self::TYPE_LABELS[$this->document_type] ?? $this->document_type;

        $logger->log('document.confirmed', "הלקוחה אישרה את \"{$typeLabel}\" דרך הטופס המקוון עבור עסקה #{$this->deal_id}", [
            'document_id' => $this->id,
            'deal_id' => $this->deal_id,
            'user' => null,
        ]);
    }

    /** Set the first time the public sign form is opened (⚡document-sign.blade.php's mount()) — idempotent, a second visit never overwrites it. */
    public function markViewed(): void
    {
        if (! $this->viewed_at) {
            $this->update(['viewed_at' => now()]);
        }
    }

    /**
     * The "סטטוס" badge (⚡deal-detail.blade.php/⚡document-view.blade.php) —
     * computed from real customer engagement (viewed_at/confirmed_at)
     * rather than status_id, which nothing in this codebase ever
     * transitions past its draft default. Empty until the document is
     * actually opened, so an unopened document shows nothing rather than a
     * misleadingly official-looking "טיוטה".
     */
    public function engagementStatusLabel(): string
    {
        return match (true) {
            (bool) $this->confirmed_at => 'נחתם',
            (bool) $this->viewed_at => 'פתיחת מסמך',
            default => '',
        };
    }

    /**
     * FR-4.17/FR-4.18/FR-8.10: description + a positive amount are always
     * required; lines only exist for invoices, and only while the invoice
     * hasn't been sent yet.
     *
     * @throws RuntimeException on a rule violation.
     */
    public function addLine(string $description, float $amount, float $quantity = 1, ?float $unitPrice = null): DocumentLine
    {
        if (! in_array($this->document_type, ['invoice', 'credit_note'], true)) {
            throw new RuntimeException('שורות פירוט קיימות רק עבור מסמכי חשבונית וחשבונית זיכוי.');
        }

        if ($this->sent_at) {
            throw new RuntimeException('לא ניתן לערוך את פירוט החשבונית לאחר שנשלחה.');
        }

        $description = trim($description);

        if ($description === '' || $amount <= 0) {
            throw new RuntimeException('כל שורת פירוט חייבת לכלול תיאור וסכום.');
        }

        $nextSort = (int) $this->lines()->max('sort_order') + 1;

        return $this->lines()->create([
            'description' => $description,
            'quantity' => $quantity,
            'unit_price' => $unitPrice ?? ($quantity > 0 ? $amount / $quantity : $amount),
            'amount' => $amount,
            'sort_order' => $nextSort,
        ]);
    }

    /**
     * @throws RuntimeException if the invoice has already been sent.
     */
    public function removeLine(int $lineId): void
    {
        if ($this->sent_at) {
            throw new RuntimeException('לא ניתן לערוך את פירוט החשבונית לאחר שנשלחה.');
        }

        $this->lines()->where('id', $lineId)->firstOrFail()->delete();
    }

    /** FR-4.19: always computed from line items — never a manually-set column. */
    public function totalAmount(): float
    {
        return (float) $this->lines()->sum('amount');
    }

    /**
     * FR-2.11/FR-4.8: the customer's primary contacts, loaded fresh every
     * time a send is being prepared — never persisted until sendTo() runs,
     * so per-send add/remove edits (FR-2.13/FR-4.9) never touch
     * contacts.is_primary.
     */
    public function defaultRecipients(): Collection
    {
        return Contact::where('customer_id', $this->deal->customer_id)->where('is_primary', true)->get();
    }

    /**
     * FR-4.7: every send is logged. FR-2.12: the DOCUMENT_RECIPIENT rows
     * created here are a permanent snapshot of who this specific send went
     * to — a later change to contacts.is_primary can never alter them.
     *
     * Build-plan 12: the recipient email is now actually dispatched via
     * Smove — "כל פעולות הדיוור מתבצעות באמצעות Smove" (docs/prd.md) applies
     * to a sent quote/order-form/contract exactly like it already did to
     * material deliveries and lead confirmations; this was previously the
     * one send path in the codebase that only wrote local bookkeeping and
     * never actually emailed anything.
     *
     * An invoice or credit note is a deliberate exception to all of that:
     * it's issued to Summit only, at the moment it's sent here — this
     * codebase's existing "sent_at is the final/official moment" convention
     * (addLine()/removeLine() already block editing invoice lines once
     * sent_at is set) made this the natural single wiring point, rather
     * than at generateFor() (still just a local draft) or as a separate
     * explicit action. It never goes through Smove at all: there is no
     * online sign form and no PDF for these two types (⚡document-view.blade.php
     * shows a single "הפקה מול Summit" action for them instead of the
     * recipients/digital-form/PDF choice every other type gets) — the
     * accounting document itself is Summit's own output, not something this
     * app emails. (expense_invoice is unrelated to any of this — a
     * standalone type generated only by Expense::attachInvoice(), never
     * reaching sendTo() at all.)
     *
     * Both external calls are non-blocking — see ExternalOperationRunner's
     * docblock: the document is sent locally either way; the caller can
     * inspect the returned ExternalOperations to decide whether to also
     * warn the user (FR-8.16).
     *
     * @param  array<int, array{contact_id: ?int, name: string, email: ?string}>  $recipients
     * @return array{smove: ?ExternalOperation, summit: ?ExternalOperation}
     *
     * @throws RuntimeException when no recipient is supplied.
     */
    public function sendTo(array $recipients, string $format, ActivityLogger $logger, ExternalOperationRunner $runner, SummitClient $summit, SmoveClient $smove): array
    {
        if (empty($recipients)) {
            throw new RuntimeException('יש לבחור לפחות נמען אחד לפני השליחה.');
        }

        $now = now();

        foreach ($recipients as $recipient) {
            $this->recipients()->create([
                'contact_id' => $recipient['contact_id'] ?? null,
                'recipient_name' => $recipient['name'],
                'recipient_email' => $recipient['email'] ?? null,
                'sent_at' => $now,
            ]);
        }

        $this->update(['sent_at' => $now, 'format' => $format]);

        $typeLabel = self::TYPE_LABELS[$this->document_type] ?? $this->document_type;

        $logger->log('document.sent', "נשלח מסמך \"{$typeLabel}\" עבור עסקה #{$this->deal_id}", [
            'document_id' => $this->id,
            'deal_id' => $this->deal_id,
            'metadata' => ['format' => $format, 'recipient_count' => count($recipients)],
        ]);

        $emailRecipients = array_values(array_filter(
            $recipients,
            fn (array $r) => ! empty($r['email']) && filter_var($r['email'], FILTER_VALIDATE_EMAIL),
        ));

        $operations = ['smove' => null, 'summit' => null];

        if (! empty($emailRecipients) && ! in_array($this->document_type, ['invoice', 'credit_note'], true)) {
            $intro = $format === 'pdf'
                ? "מצורף בזאת קובץ ה-PDF של \"{$typeLabel}\" שהוכן עבורך."
                : "מצורף בהמשך קישור ל\"{$typeLabel}\" שהוכן עבורך.";
            $formUrl = $format === 'digital' ? $this->signUrl() : null;
            $attachmentUrl = $format === 'pdf' ? $this->smoveAttachmentUrl() : null;

            $operations['smove'] = $runner->run(
                'smove',
                'document_send',
                'app_action',
                function () use ($smove, $emailRecipients, $typeLabel, $intro, $formUrl, $attachmentUrl, $format) {
                    $reference = '';

                    foreach ($emailRecipients as $recipient) {
                        $requestPdfUrl = $format === 'digital' ? $this->emailPdfUrl($recipient['email'], $recipient['name']) : null;
                        $reference = $smove->sendDocumentEmail($recipient['email'], $recipient['name'], $typeLabel, $intro, $formUrl, $attachmentUrl, $requestPdfUrl);
                    }

                    return $reference;
                },
                [
                    'document_id' => $this->id,
                    'deal_id' => $this->deal_id,
                    'description' => "שליחת {$typeLabel} במייל עבור עסקה #{$this->deal_id}",
                ],
            );
        }

        if (in_array($this->document_type, ['invoice', 'credit_note'], true)) {
            $operations['summit'] = $runner->run(
                'summit',
                $this->document_type === 'invoice' ? 'issue_invoice' : 'issue_credit_note',
                'app_action',
                fn () => $this->document_type === 'invoice' ? $summit->issueInvoice($this) : $summit->issueCreditNote($this),
                [
                    'document_id' => $this->id,
                    'deal_id' => $this->deal_id,
                    'description' => "הפקת {$typeLabel} מול Summit עבור עסקה #{$this->deal_id}",
                ],
            );
        }

        return $operations;
    }
}
