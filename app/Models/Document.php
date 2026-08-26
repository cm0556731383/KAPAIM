<?php

namespace App\Models;

use App\Services\ActivityLogger;
use App\Services\DocumentLinkedFields;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
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
    'deal_id', 'document_template_id', 'business_entity_id', 'preceding_document_id',
    'document_type', 'status_id', 'format', 'file_reference', 'rendered_content',
    'field_values', 'sent_at', 'received_at', 'signed_at',
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
        ];
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
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
     * The only place a Document row is ever created. $businessEntityId is
     * required (and validated active) for an invoice only (FR-4.15/FR-4.16,
     * FR-8.9); every other document type ignores it.
     *
     * @throws RuntimeException on a business-rule violation.
     */
    public static function generateFor(
        Deal $deal,
        DocumentTemplate $template,
        string $format = 'digital',
        ?int $businessEntityId = null,
    ): self {
        self::assertCanGenerate($deal, $template->document_type);

        $businessEntity = null;

        if ($template->document_type === 'invoice') {
            if (! $businessEntityId) {
                throw new RuntimeException('יש לבחור עוסק פטור בעת הפקת חשבונית (FR-4.15/FR-8.9).');
            }

            $businessEntity = BusinessEntity::find($businessEntityId);

            if (! $businessEntity || ! $businessEntity->is_active) {
                throw new RuntimeException('העוסק שנבחר אינו פעיל — לא ניתן להפיק חשבונית ממנו.');
            }
        }

        $preceding = self::precedingFor($deal, $template->document_type);
        $fieldValues = self::buildInitialFieldValues($template, $deal, $preceding);

        $status = StatusDefinition::firstOrCreate(
            ['scope' => 'document', 'name' => self::DEFAULT_STATUS_NAME],
            ['is_active' => true, 'sort_order' => 1],
        );

        return self::create([
            'deal_id' => $deal->id,
            'document_template_id' => $template->id,
            'business_entity_id' => $businessEntity?->id,
            'preceding_document_id' => $preceding?->id,
            'document_type' => $template->document_type,
            'status_id' => $status->id,
            'format' => $format,
            'field_values' => $fieldValues,
            'rendered_content' => $template->renderContent(
                collect($fieldValues)->map(fn ($v) => $v['value'])->all()
            ),
        ]);
    }

    /**
     * @throws RuntimeException on a business-rule violation.
     */
    private static function assertCanGenerate(Deal $deal, string $documentType): void
    {
        if ($documentType === 'contract') {
            $hasReceivedOrderForm = self::where('deal_id', $deal->id)
                ->where('document_type', 'order_form')
                ->whereNotNull('received_at')
                ->exists();

            if (! $hasReceivedOrderForm) {
                throw new RuntimeException('לא ניתן להפיק חוזה לפני שהתקבל טופס הזמנה עבור עסקה זו (FR-4.3/FR-8.7).');
            }
        }

        if ($documentType === 'invoice') {
            $hasSignedContract = self::where('deal_id', $deal->id)
                ->where('document_type', 'contract')
                ->whereNotNull('signed_at')
                ->exists();

            if (! $hasSignedContract) {
                throw new RuntimeException('לא ניתן להפיק חשבונית לפני שהתקבל חוזה חתום עבור עסקה זו (FR-4.4/FR-8.8).');
            }

            $alreadyHasInvoice = self::where('deal_id', $deal->id)->where('document_type', 'invoice')->exists();

            if ($alreadyHasInvoice) {
                throw new RuntimeException('לעסקה זו כבר הופקה חשבונית — ניתן להפיק חשבונית אחת בלבד לכל עסקה (FR-4.21/FR-4.22).');
            }
        }

        if ($documentType === 'credit_note') {
            $hasInvoice = self::where('deal_id', $deal->id)->where('document_type', 'invoice')->exists();

            if (! $hasInvoice) {
                throw new RuntimeException('לא ניתן להפיק חשבונית זיכוי לעסקה שלא הופקה עבורה חשבונית (FR-4.5/FR-8.12).');
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

        $document->addLine('זיכוי בגין ביטול מנוי — עסקה #'.$deal->id, $creditAmount);

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
                throw new RuntimeException("השדה \"{$field->name}\" הוא שדה חובה (FR-4.11).");
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
            throw new RuntimeException('שורות פירוט קיימות רק עבור מסמכי חשבונית וחשבונית זיכוי (FR-4.17).');
        }

        if ($this->sent_at) {
            throw new RuntimeException('לא ניתן לערוך את פירוט החשבונית לאחר שנשלחה (FR-4.17).');
        }

        $description = trim($description);

        if ($description === '' || $amount <= 0) {
            throw new RuntimeException('כל שורת פירוט חייבת לכלול תיאור וסכום (FR-4.18/FR-8.10).');
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
            throw new RuntimeException('לא ניתן לערוך את פירוט החשבונית לאחר שנשלחה (FR-4.17).');
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
     * @param  array<int, array{contact_id: ?int, name: string, email: ?string}>  $recipients
     *
     * @throws RuntimeException when no recipient is supplied.
     */
    public function sendTo(array $recipients, string $format, ActivityLogger $logger): void
    {
        if (empty($recipients)) {
            throw new RuntimeException('יש לבחור לפחות נמען אחד לפני השליחה (FR-4.8/FR-4.9).');
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
    }
}
