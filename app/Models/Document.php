<?php

namespace App\Models;

use App\Services\ActivityLogger;
use App\Services\DocumentLinkedFields;
use App\Services\Integrations\ExternalOperationRunner;
use App\Services\Integrations\SmoveClient;
use App\Services\Integrations\SummitClient;
use App\Support\EmailMessage;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
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
    'deal_id', 'expense_id', 'lead_id', 'lead_offer', 'document_template_id', 'business_entity_id', 'preceding_document_id',
    'document_type', 'status_id', 'format', 'file_reference', 'rendered_content',
    'field_values', 'sent_at', 'received_at', 'signed_at', 'confirmed_at', 'viewed_at', 'confirmation_noticed_at',
])]
class Document extends Model
{
    use HasFactory;

    public const DEFAULT_STATUS_NAME = 'טיוטה';

    /**
     * How every quote/order form/contract is sent (owner's call, 2026-10-27):
     * one email with the online form button and the PDF attached. 'digital'
     * and 'pdf' remain on documents sent before that.
     */
    public const FORMAT_FORM_AND_PDF = 'digital_pdf';

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
            'lead_offer' => 'array',
            'sent_at' => 'datetime',
            'received_at' => 'datetime',
            'signed_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'viewed_at' => 'datetime',
            'confirmation_noticed_at' => 'datetime',
        ];
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    /**
     * A quote sent from the lead card, before there is a customer or a deal
     * (generateForLead()). Once the recipient confirms it the lead converts
     * and the quote moves onto the new deal (deal_id); lead_id stays.
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /** A lead's quote not yet confirmed into a deal — the lead card is still where it lives. */
    public function isPendingLeadQuote(): bool
    {
        return $this->lead_id !== null && $this->deal_id === null;
    }

    /**
     * A lead's quote not yet confirmed lives on the lead card, so it follows
     * the leads page's permissions; every other document the customers page's.
     */
    public function isViewableBy(User $user): bool
    {
        return $this->isPendingLeadQuote()
            ? $user->can('leads.view') && $user->can('view', $this->lead)
            : $user->can('documents.view');
    }

    /** "עסקה #5" / "ליד #12" — what activity-log entries about this document refer to. */
    public function subjectLabel(): string
    {
        return $this->deal_id ? "עסקה #{$this->deal_id}" : ($this->lead_id ? "ליד #{$this->lead_id}" : "מסמך #{$this->id}");
    }

    /**
     * A document can be deleted only while it's a plain draft: never sent
     * (so never issued in SUMIT), received, signed or confirmed, with no
     * receipt and no later document in the chain built from it. Anything
     * past that is part of the deal's record (FR-3.5).
     */
    public function isDeletableDraft(): bool
    {
        return ! $this->sent_at && ! $this->received_at && ! $this->signed_at && ! $this->confirmed_at
            && $this->document_type !== 'expense_invoice'
            && ! $this->receipts()->exists()
            && ! self::where('preceding_document_id', $this->id)->exists();
    }

    /** @throws RuntimeException when this isn't a deletable draft (isDeletableDraft()). */
    public function deleteDraft(): void
    {
        if (! $this->isDeletableDraft()) {
            throw new RuntimeException('ניתן למחוק רק טיוטה שטרם נשלחה, נחתמה או שימשה בסיס למסמך הבא.');
        }

        $this->delete();
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

    public function receipts(): HasMany
    {
        return $this->hasMany(Receipt::class);
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
        DocumentTemplate|string $templateOrType,
        string $format = 'digital',
        ?int $businessEntityId = null,
    ): self {
        // A system type (DocumentTemplate::SYSTEM_TYPES) is generated by its
        // type alone — any template passed for one is ignored.
        $type = is_string($templateOrType) ? $templateOrType : $templateOrType->document_type;
        $template = $templateOrType instanceof DocumentTemplate && ! in_array($type, DocumentTemplate::SYSTEM_TYPES, true) ? $templateOrType : null;

        if (! $template && ! in_array($type, DocumentTemplate::SYSTEM_TYPES, true)) {
            throw new RuntimeException('לא נמצאה תבנית פעילה עבור סוג מסמך זה — יש להגדיר תבנית תחילה במסך תבניות מסמכים.');
        }

        self::assertCanGenerate($subject, $type);

        if ($subject instanceof Expense) {
            return self::createDocumentRow([
                'deal_id' => null,
                'expense_id' => $subject->id,
                'document_template_id' => null,
                'business_entity_id' => null,
                'preceding_document_id' => null,
                'document_type' => $type,
                'status_id' => self::defaultDraftStatus()->id,
                'format' => $format,
                'field_values' => [],
                'rendered_content' => '',
            ]);
        }

        $deal = $subject;
        $businessEntity = null;

        if ($type === 'invoice') {
            if (! $businessEntityId) {
                throw new RuntimeException('יש לבחור עוסק פטור בעת הפקת חשבונית.');
            }

            $businessEntity = BusinessEntity::find($businessEntityId);

            if ($deal->business_entity_id && $deal->business_entity_id !== $businessEntity?->id) {
                $locked = $deal->businessEntity;

                throw new RuntimeException("לעסקה זו כבר הופקו מסמכים ב-SUMIT מהעוסק \"{$locked?->name}\" — כל מסמכי העסקה מופקים מאותו עוסק.");
            }

            if (! $businessEntity || ! $businessEntity->is_active) {
                throw new RuntimeException('העוסק שנבחר אינו פעיל — לא ניתן להפיק חשבונית ממנו.');
            }
        }

        // A credit note is issued from the deal's entity — the one it's locked
        // to, else the one passed in (its invoice's, by generateCreditNoteFor()).
        if ($type === 'credit_note') {
            $businessEntity = BusinessEntity::find($deal->business_entity_id ?? $businessEntityId);
        }

        $preceding = self::precedingFor($deal, $type);
        $fieldValues = $template ? self::buildInitialFieldValues($template, $deal, $preceding) : [];

        $document = self::createDocumentRow([
            'deal_id' => $deal->id,
            'expense_id' => null,
            'document_template_id' => $template?->id,
            'business_entity_id' => $businessEntity?->id,
            'preceding_document_id' => $preceding?->id,
            'document_type' => $type,
            'status_id' => self::defaultDraftStatus()->id,
            'format' => $format,
            'field_values' => $fieldValues,
            'rendered_content' => $template?->renderContent(
                collect($fieldValues)->map(fn ($v) => $v['value'])->all()
            ) ?? '',
        ]);

        // A new invoice starts with one line for what was sold — "<program/
        // bundle> — <invoice name>" at the deal's agreed amount — editable
        // or removable on the invoice screen until it's sent (FR-4.20).
        if ($document->document_type === 'invoice' && (float) $deal->agreed_amount > 0) {
            $item = $deal->program_name_snapshot ?? $deal->bundle_name_snapshot ?? "עסקה #{$deal->id}";
            $invoiceName = DocumentLinkedFields::invoiceName($deal);

            $document->addLine($invoiceName ? "{$item} — {$invoiceName}" : $item, (float) $deal->agreed_amount);
        }

        return $document;
    }

    /**
     * A quote from the lead card (business owner's call, 2026-10-27): the
     * first purchase is offered before the lead is a customer. It belongs to
     * the lead, and what it offers — exactly one program or bundle, its
     * amount, the student count — is kept in lead_offer until the recipient
     * confirms it; confirm() then converts the lead and opens the deal from
     * it. An existing customer buying again gets her quote from the deal, as
     * before.
     *
     * @throws RuntimeException on a business-rule violation.
     */
    public static function generateForLead(
        Lead $lead,
        DocumentTemplate $template,
        ?Program $program,
        ?Bundle $bundle,
        ?float $agreedAmount = null,
        ?int $studentsCount = null,
    ): self {
        if ($template->document_type !== 'quote' || ! $template->is_active) {
            throw new RuntimeException('יש לבחור תבנית פעילה של הצעת מחיר.');
        }

        if ($lead->customer()->exists()) {
            throw new RuntimeException('ליד זה כבר הומר ללקוחה — הצעת מחיר לרכישה נוספת נשלחת מכרטיס העסקה בכרטיס הלקוחה.');
        }

        if (! $lead->school_id) {
            throw new RuntimeException('יש להשלים פרטי בית ספר עבור הליד לפני שליחת הצעת מחיר.');
        }

        if (($program === null) === ($bundle === null)) {
            throw new RuntimeException('יש לבחור תוכנית אחת או מארז אחד בלבד עבור הצעת המחיר — לא שניהם ולא אף אחד.');
        }

        if (($program && ! $program->is_active) || ($bundle && ! $bundle->is_active)) {
            throw new RuntimeException('לא ניתן לשלוח הצעת מחיר עבור תוכנית או מארז שהושבתו.');
        }

        $amount = $agreedAmount ?? (float) ($program->price ?? $bundle->price);

        if ($amount <= 0) {
            throw new RuntimeException('יש להזין סכום להצעת המחיר.');
        }

        $document = new self([
            'lead_id' => $lead->id,
            'lead_offer' => [
                'program_id' => $program?->id,
                'bundle_id' => $bundle?->id,
                'agreed_amount' => round($amount, 2),
                'students_count' => $studentsCount,
            ],
        ]);
        $document->setRelation('lead', $lead);
        $fieldValues = self::buildInitialFieldValues($template, $document->leadOfferDeal(), null);

        return self::createDocumentRow([
            'deal_id' => null,
            'expense_id' => null,
            'lead_id' => $lead->id,
            'lead_offer' => $document->lead_offer,
            'document_template_id' => $template->id,
            'business_entity_id' => null,
            'preceding_document_id' => null,
            'document_type' => 'quote',
            'status_id' => self::defaultDraftStatus()->id,
            'format' => 'digital',
            'field_values' => $fieldValues,
            'rendered_content' => $template->renderContent(collect($fieldValues)->map(fn ($v) => $v['value'])->all()),
        ]);
    }

    /**
     * The deal a lead's quote offers, as it would look once confirmed —
     * never saved. The linked template fields (DocumentLinkedFields, which
     * read a Deal) resolve against it: the lead's school and contacts, the
     * offered item and amount. With no contact on the school yet, the lead's
     * own email/phone stand in for the primary contact, as on conversion.
     */
    public function leadOfferDeal(): Deal
    {
        $lead = $this->lead;
        $offer = $this->lead_offer ?? [];
        $program = isset($offer['program_id']) ? Program::find($offer['program_id']) : null;
        $bundle = isset($offer['bundle_id']) ? Bundle::find($offer['bundle_id']) : null;

        $contacts = Contact::where('school_id', $lead->school_id)->get();

        if ($contacts->isEmpty()) {
            $contacts = new \Illuminate\Database\Eloquent\Collection([
                new Contact(['name' => '', 'email' => $lead->email, 'phone' => $lead->phone, 'is_primary' => true]),
            ]);
        }

        $customer = new Customer(['school_id' => $lead->school_id, 'lead_id' => $lead->id]);
        $customer->setRelation('school', $lead->school);
        $customer->setRelation('contacts', $contacts);
        $customer->setRelation('lead', $lead);
        $customer->setRelation('status', null);

        $deal = new Deal([
            'program_id' => $program?->id,
            'bundle_id' => $bundle?->id,
            'agreed_amount' => $offer['agreed_amount'] ?? 0,
            'students_count' => $offer['students_count'] ?? null,
            'program_price_snapshot' => $program?->price,
            'bundle_price_snapshot' => $bundle?->price,
            'program_name_snapshot' => $program?->name,
            'bundle_name_snapshot' => $bundle?->name,
        ]);

        foreach (['status', 'paymentMethod', 'subscription'] as $relation) {
            $deal->setRelation($relation, null);
        }

        return $deal->setRelation('customer', $customer);
    }

    /** The offered program/bundle's name — "מארז: …" for a bundle. */
    public function leadOfferItemName(): ?string
    {
        $offer = $this->lead_offer ?? [];

        if (isset($offer['program_id'])) {
            return Program::find($offer['program_id'])?->name;
        }

        return isset($offer['bundle_id']) && ($bundle = Bundle::find($offer['bundle_id'])) ? "מארז: {$bundle->name}" : null;
    }

    /** The deal the linked fields read from — the real one, or a pending lead quote's offer (leadOfferDeal()). */
    private function fieldsDeal(): ?Deal
    {
        return $this->deal ?? ($this->isPendingLeadQuote() ? $this->leadOfferDeal() : null);
    }

    /**
     * A lead's quote is confirmed: the lead converts (unless it already did)
     * and a deal opens from the offer, exactly as one opened on the customer
     * card would — the quote then belongs to that deal, first in its chain.
     *
     * @throws RuntimeException when the deal can't be opened (e.g. the
     *                          offered item was disabled since the quote went out).
     */
    private function openDealFromLeadQuote(ActivityLogger $logger): void
    {
        $lead = $this->lead;
        $customer = $lead->customer()->first() ?? $lead->convertToCustomer($logger);
        $offer = $this->lead_offer ?? [];

        try {
            $deal = Deal::createForCustomer(
                $customer,
                isset($offer['program_id']) ? Program::find($offer['program_id']) : null,
                isset($offer['bundle_id']) ? Bundle::find($offer['bundle_id']) : null,
                (float) ($offer['agreed_amount'] ?? 0) ?: null,
            );
        } catch (RuntimeException $e) {
            throw new RuntimeException('לא ניתן להשלים את אישור הצעת המחיר כרגע — נא ליצור איתנו קשר. ('.$e->getMessage().')');
        }

        if (isset($offer['students_count'])) {
            $deal->update(['students_count' => (int) $offer['students_count']]);
        }

        $this->update(['deal_id' => $deal->id]);
        $this->setRelation('deal', $deal);

        $logger->log('deal.created', "נוצרה עסקה #{$deal->id} מאישור הצעת המחיר שנשלחה לליד #{$lead->id}: ".($deal->program_name_snapshot ?? $deal->bundle_name_snapshot), [
            'deal_id' => $deal->id, 'customer_id' => $customer->id, 'lead_id' => $lead->id, 'document_id' => $this->id, 'user' => null,
        ]);
    }

    /** Set only inside generateOrderFormAndContract(): its contract needn't wait for the order form to be received. */
    private static bool $generatingWithOrderForm = false;

    /**
     * The order form and the contract, generated together to go out in one
     * email (business owner's call, 2026-10-27 — they used to be generated
     * and sent one after the other). The contract is chained to this order
     * form (preceding_document_id), which is what makes them a pair: sending
     * the order form sends the contract with it (sendCompanions()). The
     * "order form received first" gate (FR-4.3) is waived for such a contract
     * only — both reach the customer at once. With no template given, the
     * order form is the first active one (by name) and the contract is the
     * deal's program/bundle default (DocumentTemplate::defaultContractFor()),
     * as the automatic send after a lead's quote can't ask.
     *
     * @return array{order_form: self, contract: self}
     *
     * @throws RuntimeException before anything is created.
     */
    public static function generateOrderFormAndContract(Deal $deal, ?DocumentTemplate $orderFormTemplate = null, ?DocumentTemplate $contractTemplate = null): array
    {
        $first = fn (string $type) => DocumentTemplate::where('is_active', true)->where('document_type', $type)->orderBy('name')->orderBy('id')->first();
        $orderFormTemplate ??= $first('order_form');
        $contractTemplate ??= DocumentTemplate::defaultContractFor($deal);

        if (! $orderFormTemplate?->is_active || ! $contractTemplate?->is_active) {
            throw new RuntimeException('לא נמצאה תבנית פעילה של טופס הזמנה ושל חוזה — יש להגדיר אותן תחילה בהגדרות, בלשונית תבניות מסמכים.');
        }

        if (self::where('deal_id', $deal->id)->where('document_type', 'contract')->exists()) {
            throw new RuntimeException('לעסקה זו כבר הופק חוזה.');
        }

        return DB::transaction(function () use ($deal, $orderFormTemplate, $contractTemplate) {
            $orderForm = self::generateFor($deal, $orderFormTemplate);

            self::$generatingWithOrderForm = true;

            try {
                $contract = self::generateFor($deal, $contractTemplate);
            } finally {
                self::$generatingWithOrderForm = false;
            }

            return ['order_form' => $orderForm, 'contract' => $contract];
        });
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

        // A supplier's invoice belongs to an expense (Expense::attachInvoice()), never to a sale.
        if ($documentType === 'expense_invoice') {
            throw new RuntimeException('חשבונית הוצאה מצורפת להוצאה במסך הוצאות — לא לעסקה.');
        }

        $deal = $subject;

        if ($documentType === 'contract' && ! self::$generatingWithOrderForm) {
            $hasReceivedOrderForm = self::where('deal_id', $deal->id)
                ->where('document_type', 'order_form')
                ->whereNotNull('received_at')
                ->exists();

            if (! $hasReceivedOrderForm) {
                throw new RuntimeException('לא ניתן להפיק חוזה לפני שהתקבל טופס הזמנה עבור עסקה זו.');
            }
        }

        if ($documentType === 'invoice') {
            // The contract only has to exist, not be signed yet — the business
            // owner's 2026-10-23 call, so an invoice can go out before signing.
            $hasContract = self::where('deal_id', $deal->id)
                ->where('document_type', 'contract')
                ->exists();

            if (! $hasContract) {
                throw new RuntimeException('לא ניתן להפיק חשבונית לפני שהופק חוזה עבור עסקה זו.');
            }

            $alreadyHasInvoice = self::where('deal_id', $deal->id)->where('document_type', 'invoice')->exists();

            if ($alreadyHasInvoice) {
                throw new RuntimeException('לעסקה זו כבר הופקה חשבונית — ניתן להפיק חשבונית אחת בלבד לכל עסקה.');
            }
        }

        if ($documentType === 'credit_note') {
            if (! $deal->issuedInvoice()) {
                throw new RuntimeException('לא ניתן להפיק חשבונית זיכוי לעסקה שלא הופקה עבורה חשבונית.');
            }

            // One credit per deal (its subscription's cancellation credit) — a
            // second would credit the same amount twice in SUMIT. A draft whose
            // SUMIT issuance failed stays, to be sent again from its own screen.
            if (self::where('deal_id', $deal->id)->where('document_type', 'credit_note')->exists()) {
                throw new RuntimeException('לעסקה זו כבר הופקה חשבונית זיכוי — ניתן להפיק חשבונית זיכוי אחת בלבד לכל עסקה.');
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
    public static function generateCreditNoteFor(Deal $deal, float $creditAmount, string $description = 'זיכוי בגין ביטול מנוי'): self
    {
        $invoice = $deal->issuedInvoice();
        self::assertExemptCreditCovered($deal, $invoice, $creditAmount);

        $document = self::generateFor($deal, 'credit_note', 'digital', $invoice?->business_entity_id);

        $document->addLine($description, $creditAmount);

        return $document;
    }

    /**
     * An exempt dealer's "invoice" is a חשבון עסקה — not a tax document —
     * and its credit is a קבלה זיכוי: money handed back. So it can credit at
     * most what the customer actually paid; with nothing paid there's nothing
     * to credit (cancelling the deal is enough). A licensed dealer's tax
     * invoice is credited regardless of payment.
     *
     * @throws RuntimeException
     */
    private static function assertExemptCreditCovered(Deal $deal, ?self $invoice, float $creditAmount): void
    {
        if (! $invoice?->businessEntity?->isExempt()) {
            return;
        }

        $paid = $deal->totalPaid();

        if ($paid <= 0) {
            throw new RuntimeException('לעסקה של עוסק פטור הופק רק חשבון עסקה ועדיין לא התקבל עליה תשלום — אין מה לזכות. די לבטל את העסקה; חשבון עסקה אינו מסמך מס.');
        }

        if ($creditAmount > $paid + 0.005) {
            throw new RuntimeException('בעוסק פטור הזיכוי הוא קבלת זיכוי — החזר של כסף שהתקבל — ולכן הוא יכול להיות עד ₪'.number_format($paid, 2).', הסכום ששולם בעסקה.');
        }
    }

    /**
     * One click from the deal / customer card: create the credit note and
     * issue it in SUMIT (which emails it), to the customer's primary
     * contact(s). A SUMIT failure leaves an unsent draft to retry from its
     * own screen, as with any credit note.
     *
     * @return array{document: self, operations: array}
     *
     * @throws RuntimeException before anything is created (no recipient, a
     *                          rule) — never after.
     */
    public static function issueCreditNoteFor(Deal $deal, float $creditAmount, string $description, ActivityLogger $logger, ExternalOperationRunner $runner, SummitClient $summit, SmoveClient $smove): array
    {
        $recipients = Contact::where('customer_id', $deal->customer_id)->where('is_primary', true)->get()
            ->filter(fn (Contact $c) => filled($c->email) && filter_var($c->email, FILTER_VALIDATE_EMAIL))
            ->map(fn (Contact $c) => ['contact_id' => $c->id, 'name' => $c->name, 'email' => $c->email])
            ->values()->all();

        if (empty($recipients)) {
            throw new RuntimeException('ללקוחה אין איש קשר ראשי עם כתובת דוא"ל — יש להוסיף אחד בכרטיס הלקוח, ואז להפיק את הזיכוי.');
        }

        $document = self::generateCreditNoteFor($deal, $creditAmount, $description);

        return ['document' => $document, 'operations' => $document->sendTo($recipients, 'digital', $logger, $runner, $summit, $smove)];
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

        foreach ($template->fieldsInContent() as $field) {
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
        // What the customer confirmed/signed is final — editing it afterwards
        // would silently change the content of a signed document.
        if ($this->confirmed_at) {
            throw new RuntimeException('הלקוחה כבר אישרה את המסמך — לא ניתן לשנות את שדותיו.');
        }

        $stored = [];
        $deal = $this->fieldsDeal();

        foreach ($this->templateFields() as $field) {
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

            if ($field->field_type === 'linked' && $field->linked_field && $value !== '' && $deal) {
                DocumentLinkedFields::applyBack($field->linked_field, $deal, $value);

                // A pending lead quote's deal exists only as its offer, so a
                // student count given for it is kept there.
                if ($this->isPendingLeadQuote() && $field->linked_field === 'deal.students_count' && ctype_digit($value)) {
                    $this->lead_offer = [...$this->lead_offer, 'students_count' => (int) $value];
                }
            }
        }

        $this->update([
            'field_values' => $stored,
            'rendered_content' => $this->documentTemplate?->renderContent(
                collect($stored)->map(fn ($v) => $v['value'])->all()
            ) ?? (string) $this->rendered_content,
        ]);
    }

    /** The template's fields used in its content — none for a document with no template (DocumentTemplate::SYSTEM_TYPES). */
    public function templateFields(): Collection
    {
        return $this->documentTemplate?->fieldsInContent() ?? collect();
    }

    /** FR-4.3/FR-8.7's prerequisite: an order form is "received" once this is set. */
    public function markReceived(): void
    {
        $this->update(['received_at' => now()]);
    }

    /** A contract is "signed" once this is set. */
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
     * The "קבלת המסמך כ-PDF" button on a digital-form send made before
     * 2026-10-27 — kept so that button still works in emails already sent;
     * a new send attaches the PDF itself (emailMessage()). Hits documents.email-pdf,
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
        $this->loadMissing(['deal.customer.school', 'lead.school', 'businessEntity', 'lines']);

        $fileName = (self::TYPE_LABELS[$this->document_type] ?? $this->document_type).'-'.$this->id.'.pdf';

        $mpdf = new Mpdf(['default_font' => 'dejavusans', 'directionality' => 'rtl']);

        $previousErrorReporting = error_reporting(E_ERROR | E_PARSE | E_COMPILE_ERROR | E_CORE_ERROR);

        try {
            $mpdf->WriteHTML(view('documents.pdf', ['document' => $this])->render());
            $binary = $mpdf->Output($fileName, Destination::STRING_RETURN);
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

        $logger->log('document.pdf_emailed', "נשלח קובץ PDF של \"{$typeLabel}\" למייל {$email} עבור {$this->subjectLabel()}", [
            'document_id' => $this->id,
            'deal_id' => $this->deal_id,
            'lead_id' => $this->lead_id,
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
        return $this->documentTemplate?->renderContent($this->currentFieldValues(), printFriendly: true) ?? (string) $this->rendered_content;
    }

    /**
     * document_template_field_id => value: what was captured on this
     * document, falling back — for a linked field still empty here — to the
     * customer card's live value, so a detail added to the card after the
     * document was generated still fills its line instead of leaving it blank.
     */
    public function currentFieldValues(): array
    {
        $stored = collect($this->field_values ?? []);
        $values = [];
        $deal = null;

        foreach ($this->templateFields() as $field) {
            $value = trim((string) ($stored[$field->id]['value'] ?? ''));

            if ($value === '' && $field->field_type === 'linked' && $field->linked_field && ($deal ??= $this->fieldsDeal())) {
                $value = trim((string) (DocumentLinkedFields::resolve($field->linked_field, $deal) ?? ''));
            }

            $values[$field->id] = $value;
        }

        return $values;
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

        $openedDeal = false;

        // Row-locked: a double click on a lead's quote must open one deal.
        $confirmed = DB::transaction(function () use ($fieldValues, $logger, &$openedDeal) {
            $fresh = self::whereKey($this->id)->lockForUpdate()->first();

            if ($fresh->confirmed_at) {
                return false;
            }

            $this->setRawAttributes($fresh->getAttributes(), true);

            // A lead's quote: the lead becomes a customer with the offered
            // deal first, so the confirmed values are written to them.
            if ($this->isPendingLeadQuote()) {
                $this->openDealFromLeadQuote($logger);
                $openedDeal = true;
            }

            if ($this->templateFields()->isNotEmpty()) {
                $this->submitFieldValues($fieldValues);
            }

            $this->update(['confirmed_at' => now()]);

            return true;
        });

        if (! $confirmed) {
            $this->refresh();

            return;
        }

        if ($this->document_type === 'order_form' && ! $this->received_at) {
            $this->markReceived();
        }

        if ($this->document_type === 'contract' && ! $this->signed_at) {
            $this->markSigned();
        }

        $typeLabel = self::TYPE_LABELS[$this->document_type] ?? $this->document_type;

        $logger->log('document.confirmed', "הלקוחה אישרה את \"{$typeLabel}\" דרך הטופס המקוון עבור {$this->subjectLabel()}", [
            'document_id' => $this->id,
            'deal_id' => $this->deal_id,
            'lead_id' => $this->lead_id,
            'user' => null,
        ]);

        // Outside the transaction: the email is an external call, and the
        // confirmation stands whatever happens to it.
        if ($openedDeal) {
            $this->sendOrderFormAndContractAfterQuote($logger);
        }
    }

    /**
     * Right after a lead's quote is confirmed (business owner's call,
     * 2026-10-27): the new deal's order form and contract are generated and
     * sent together, in one email, to whoever the quote went to — the
     * customer's primary contacts if it went to no one with an email. Never
     * throws: a failure (no active template, no recipient) is logged, and the
     * home screen's confirmed-quote notice shows the two weren't sent, to be
     * sent from the deal card.
     */
    private function sendOrderFormAndContractAfterQuote(ActivityLogger $logger): void
    {
        $deal = $this->deal;

        $recipients = $this->recipients()->get()
            ->filter(fn (DocumentRecipient $r) => filled($r->recipient_email) && filter_var($r->recipient_email, FILTER_VALIDATE_EMAIL))
            ->map(fn (DocumentRecipient $r) => ['contact_id' => Contact::whereKey($r->contact_id)->exists() ? $r->contact_id : null, 'name' => $r->recipient_name, 'email' => $r->recipient_email])
            ->unique('email')->values()->all();

        if (empty($recipients)) {
            $recipients = Contact::where('customer_id', $deal->customer_id)->where('is_primary', true)->get()
                ->filter(fn (Contact $c) => filled($c->email))
                ->map(fn (Contact $c) => ['contact_id' => $c->id, 'name' => $c->name, 'email' => $c->email])
                ->values()->all();
        }

        try {
            if (empty($recipients)) {
                throw new RuntimeException('אין נמען עם כתובת דוא"ל.');
            }

            ['order_form' => $orderForm] = self::generateOrderFormAndContract($deal);
            $operations = $orderForm->sendTo($recipients, self::FORMAT_FORM_AND_PDF, $logger, app(ExternalOperationRunner::class), app(SummitClient::class), app(SmoveClient::class));

            if ($operations['smove']?->status === ExternalOperation::STATUS_FAILED) {
                throw new RuntimeException('שליחת המייל דרך Smove נכשלה: '.$operations['smove']->error_message);
            }

            $this->recordFollowUpSend(null);
        } catch (RuntimeException $e) {
            $this->recordFollowUpSend($e->getMessage());
            $logger->log('document.auto_send_failed', "טופס ההזמנה והחוזה לא נשלחו אוטומטית אחרי אישור הצעת המחיר בעסקה #{$deal->id}: {$e->getMessage()}", [
                'document_id' => $this->id, 'deal_id' => $deal->id, 'lead_id' => $this->lead_id, 'user' => null,
            ]);
        }
    }

    /** What happened to the automatic order form + contract send — kept with the offer, for the home screen's notice. */
    private function recordFollowUpSend(?string $error): void
    {
        $this->update(['lead_offer' => [...($this->lead_offer ?? []), 'follow_up_sent' => $error === null, 'follow_up_error' => $error]]);
    }

    /**
     * Confirmed lead quotes not yet marked handled — the home screen's notice
     * (business owner's call, 2026-10-27), newest first.
     */
    public static function unnoticedConfirmedLeadQuotes(): \Illuminate\Database\Eloquent\Collection
    {
        return self::whereNotNull('lead_id')->whereNotNull('deal_id')->where('document_type', 'quote')
            ->whereNotNull('confirmed_at')->whereNull('confirmation_noticed_at')
            ->with(['deal.customer.school'])->orderByDesc('confirmed_at')->get();
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
        // A lead's quote goes to the school's primary contacts — any contact
        // with an email when none is flagged, else the lead's own email.
        if ($this->isPendingLeadQuote()) {
            $contacts = Contact::where('school_id', $this->lead->school_id)->orderByDesc('is_primary')->orderBy('id')->get()
                ->filter(fn (Contact $c) => filled($c->email));
            $primary = $contacts->where('is_primary', true);

            return match (true) {
                $primary->isNotEmpty() => $primary->values(),
                $contacts->isNotEmpty() => $contacts->values(),
                filled($this->lead->email) => collect([new Contact(['name' => $this->lead->school?->name ?? $this->lead->email, 'email' => $this->lead->email])]),
                default => new Collection,
            };
        }

        // An expense invoice is incoming (from a supplier) — nobody to send it to.
        if (! $this->deal) {
            return new Collection;
        }

        return Contact::where('customer_id', $this->deal->customer_id)->where('is_primary', true)->get();
    }

    /** Who the document is with — the customer's school, or an expense invoice's supplier. */
    public function partyName(): ?string
    {
        return match (true) {
            (bool) $this->deal => $this->deal->customer?->school?->name,
            (bool) $this->lead => $this->lead->school?->name,
            default => $this->expense?->supplier?->name,
        };
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

        // A document is sent once. A second tab still showing the unsent
        // screen (or a double click) must not send it again — for an invoice
        // or credit note that would issue a second tax document in SUMIT.
        // The lock covers two sends racing each other; the fresh sent_at
        // read covers one that already finished.
        $lock = Cache::lock("document-send-{$this->id}", 120);

        if (! $lock->get()) {
            throw new RuntimeException('המסמך נמצא כרגע בתהליך שליחה — נא לרענן את הדף בעוד רגע.');
        }

        try {
            if (self::whereKey($this->id)->whereNotNull('sent_at')->exists()) {
                throw new RuntimeException('המסמך כבר נשלח — נא לרענן את הדף.');
            }

            if ($this->pairedOrderForm()) {
                throw new RuntimeException('החוזה נשלח יחד עם טופס ההזמנה, במייל אחד — שולחים אותו ממסך טופס ההזמנה.');
            }

            return $this->sendToRecipients($recipients, $format, $logger, $runner, $summit, $smove);
        } finally {
            $lock->release();
        }
    }

    private function sendToRecipients(array $recipients, string $format, ActivityLogger $logger, ExternalOperationRunner $runner, SummitClient $summit, SmoveClient $smove): array
    {
        $operations = ['smove' => null, 'summit' => null];

        // An invoice/credit note is delivered by Summit itself, so it only
        // counts as sent once Summit accepted it. On a failure the draft is
        // deleted outright (business owner's call — invoice 2026-10-12,
        // credit note 2026-10-14), so the deal is free to generate a fresh
        // one — $operations['deleted'] tells the caller the document is gone.
        if (in_array($this->document_type, ['invoice', 'credit_note'], true)) {
            $typeLabel = self::TYPE_LABELS[$this->document_type] ?? $this->document_type;
            // SUMIT emails the issued document itself, to the first recipient with a valid address.
            $summitEmail = collect($recipients)->pluck('email')->first(fn ($email) => filled($email) && filter_var($email, FILTER_VALIDATE_EMAIL));

            $operations['summit'] = $runner->run(
                'summit',
                $this->document_type === 'invoice' ? 'issue_invoice' : 'issue_credit_note',
                'app_action',
                fn () => $this->document_type === 'invoice' ? $summit->issueInvoice($this, $summitEmail) : $summit->issueCreditNote($this, $summitEmail),
                [
                    'document_id' => $this->id,
                    'deal_id' => $this->deal_id,
                    'description' => "הפקת {$typeLabel} מול Summit עבור עסקה #{$this->deal_id}",
                ],
            );

            if ($operations['summit']->status === ExternalOperation::STATUS_FAILED) {
                if (! $this->receipts()->exists()) {
                    $logger->log('document.deleted', "טיוטת ה{$typeLabel} עבור עסקה #{$this->deal_id} נמחקה כי ההפקה מול Summit נכשלה", [
                        'deal_id' => $this->deal_id,
                        'metadata' => ['document_id' => $this->id, 'error' => $operations['summit']->error_message],
                    ]);
                    $this->delete();
                    $operations['deleted'] = true;
                }

                return $operations;
            }
        }

        $now = now();
        // An order form generated with its contract goes out with it, in the
        // same email (generateOrderFormAndContract()) — both are recorded sent.
        $companions = $this->sendCompanions();

        foreach ([$this, ...$companions] as $document) {
            foreach ($recipients as $recipient) {
                $document->recipients()->create([
                    'contact_id' => $recipient['contact_id'] ?? null,
                    'recipient_name' => $recipient['name'],
                    'recipient_email' => $recipient['email'] ?? null,
                    'sent_at' => $now,
                ]);
            }

            $document->update(['sent_at' => $now, 'format' => $format]);

            $documentLabel = self::TYPE_LABELS[$document->document_type] ?? $document->document_type;

            $logger->log('document.sent', "נשלח מסמך \"{$documentLabel}\" עבור {$document->subjectLabel()}".($companions ? ' (טופס הזמנה וחוזה במייל אחד)' : ''), [
                'document_id' => $document->id,
                'deal_id' => $document->deal_id,
                'lead_id' => $document->lead_id,
                'metadata' => ['format' => $format, 'recipient_count' => count($recipients)],
            ]);
        }

        $typeLabel = $this->emailSubject($companions);

        if ($this->document_type === 'invoice' && $this->totalAmount() > 0) {
            $this->deal?->markInvoiceSent($logger);
        }

        $emailRecipients = array_values(array_filter(
            $recipients,
            fn (array $r) => ! empty($r['email']) && filter_var($r['email'], FILTER_VALIDATE_EMAIL),
        ));

        if (! empty($emailRecipients) && ! in_array($this->document_type, ['invoice', 'credit_note'], true)) {
            $operations['smove'] = $runner->run(
                'smove',
                'document_send',
                'app_action',
                function () use ($smove, $emailRecipients, $companions) {
                    $reference = '';

                    foreach ($emailRecipients as $recipient) {
                        $reference = $smove->send([['email' => $recipient['email'], 'name' => $recipient['name']]], $this->emailMessage($recipient['name'], companions: $companions));
                    }

                    return $reference;
                },
                [
                    'document_id' => $this->id,
                    'deal_id' => $this->deal_id,
                    'description' => "שליחת {$typeLabel} במייל עבור {$this->subjectLabel()}",
                ],
            );
        }

        return $operations;
    }

    /**
     * The email a recipient gets for a document send (not an invoice/credit
     * note — SUMIT emails those itself): the online form button, with the
     * PDF attached too (FORMAT_FORM_AND_PDF). $preview: the document-view
     * preview — its button leads nowhere, since a working form link would
     * let the test copy sign the real document; the PDF is still attached
     * for real.
     */
    public function emailMessage(string $toName, bool $preview = false, ?array $companions = null): EmailMessage
    {
        $companions ??= $this->sendCompanions();
        $typeLabel = self::TYPE_LABELS[$this->document_type] ?? $this->document_type;

        if ($companions) {
            $documents = [$this, ...$companions];
            $rendered = EmailTemplate::forType(EmailTemplate::DOCUMENTS_SEND)->render([
                'recipient_name' => $toName,
                'documents' => $this->emailSubject($companions),
                'school_name' => (string) $this->partyName(),
            ]);

            return EmailMessage::documents(
                $rendered['subject'],
                $rendered['content'],
                collect($documents)->map(fn (self $d) => ['label' => self::TYPE_LABELS[$d->document_type], 'url' => $preview ? '#' : $d->signUrl()])->all(),
                collect($documents)->map(fn (self $d) => ['name' => self::TYPE_LABELS[$d->document_type].'.pdf', 'url' => $d->smoveAttachmentUrl()])->all(),
            );
        }

        $rendered = EmailTemplate::forType(EmailTemplate::DOCUMENT_SEND)->render([
            'recipient_name' => $toName,
            'document_type' => $typeLabel,
            'school_name' => (string) $this->partyName(),
        ]);

        return EmailMessage::document(
            $rendered['subject'],
            $rendered['content'],
            $preview ? '#' : $this->signUrl(),
            [['name' => "{$typeLabel}.pdf", 'url' => $this->smoveAttachmentUrl()]],
        );
    }

    /** "טופס הזמנה וחוזה" — the subject of a send, with whatever goes out with it. */
    private function emailSubject(array $companions): string
    {
        return collect([$this, ...$companions])->map(fn (self $d) => self::TYPE_LABELS[$d->document_type] ?? $d->document_type)->implode(' ו');
    }

    /**
     * Documents that go out with this one, in its email: an unsent order
     * form's contract generated together with it (generateOrderFormAndContract()).
     *
     * @return list<self>
     */
    public function sendCompanions(): array
    {
        if ($this->document_type !== 'order_form' || $this->sent_at) {
            return [];
        }

        return self::where('preceding_document_id', $this->id)->where('document_type', 'contract')->whereNull('sent_at')->orderBy('id')->get()->all();
    }

    /** For a contract going out with its order form (sendCompanions()) — that order form. */
    public function pairedOrderForm(): ?self
    {
        if ($this->document_type !== 'contract' || $this->sent_at || ! $this->preceding_document_id) {
            return null;
        }

        $orderForm = $this->precedingDocument()->first();

        return $orderForm?->document_type === 'order_form' && ! $orderForm->sent_at ? $orderForm : null;
    }

    /** How a sent document went out — for the deal's documents table and the document screen. */
    public function sentFormatLabel(): string
    {
        return match ($this->format) {
            'pdf' => 'קובץ PDF',
            self::FORMAT_FORM_AND_PDF => 'טופס מקוון + PDF',
            default => 'טופס מקוון',
        };
    }

    /** Sent with a PDF the customer may sign on paper, so staff can mark it signed by hand. */
    public function canBeMarkedSignedByHand(): bool
    {
        return in_array($this->format, ['pdf', self::FORMAT_FORM_AND_PDF], true)
            && $this->sent_at && ! $this->confirmed_at
            && ! in_array($this->document_type, ['invoice', 'credit_note'], true);
    }
}
