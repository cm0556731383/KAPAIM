<?php

use App\Concerns\Notifies;
use App\Models\BusinessEntity;
use App\Models\Deal;
use App\Models\Document;
use App\Models\DocumentTemplate;
use App\Models\Payment;
use App\Models\PaymentLink;
use App\Models\PaymentMethod;
use App\Models\ExternalOperation;
use App\Models\Receipt;
use App\Models\StatusDefinition;
use App\Services\ActivityLogger;
use App\Services\Integrations\ExternalOperationRunner;
use App\Services\Integrations\SmoveClient;
use App\Services\Integrations\SummitClient;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Build-plan 06 — מסך עסקה. A Deal only ever exists via
 * Deal::createForCustomer() (called from ⚡customer-detail.blade.php's
 * "עסקאות" tab) — this component never creates one, only displays it and
 * drives its status workflow (FR-8.19: optimistic locking on `version`).
 *
 * Build-plan 07 adds the "מסמכים" section below: the deal's document chain
 * (quote -> order_form -> contract -> invoice) and "generate next document"
 * buttons, gated by Document::canGenerate()/generateFor() (FR-4.3/FR-4.4,
 * FR-8.7/FR-8.8).
 *
 * Build-plan 08 adds the "תשלומים" section: payment history + outstanding
 * balance, a record-payment form (Deal::recordPayment(), FR-4.30-FR-4.34/
 * FR-8.19), "סימון כנפרע" for check payments (Payment::markCleared(),
 * FR-4.28), and receipt issuance (Receipt::issueFor(), FR-4.5/FR-4.23-FR-4.29).
 */
new
#[Layout('layouts.app', ['title' => 'כרטיס עסקה — כפיים'])]
class extends Component
{
    use Notifies;

    public Deal $deal;

    /** The `version` this component loaded the deal with — see Deal::updateStatusWithLock(). */
    public int $loadedVersion;

    public string $selectedStatusId = '';

    public string $agreedAmount = '';

    public string $specialRequest = '';

    public string $studentsCount = '';

    public string $paymentMethodId = '';

    /** FR-8.19 conflict error — a friendly banner, not a silent overwrite. */
    public ?string $statusError = null;

    /** FR-4.30/FR-4.31 error from Deal::updatePaymentMethod() — a friendly banner, not a silent block. */
    public ?string $detailsError = null;

    // ===== מסמכים (DOCUMENT) =====
    public string $invoiceBusinessEntityId = '';

    /** Business-rule error from Document::generateFor() (FR-4.3/FR-4.4/FR-4.21/FR-8.7/FR-8.8/FR-8.9). */
    public ?string $documentError = null;

    // ===== תשלומים וקבלות (PAYMENT/RECEIPT) =====
    public string $paymentAmount = '';

    public string $paymentDate = '';

    /** Business-rule error from Deal::recordPayment()/Payment::markCleared()/Receipt::issueFor(). */
    public ?string $paymentError = null;

    /** Payment being corrected inline — only a Payment::isEditable() one. */
    public ?int $editingPaymentId = null;
    public string $editPaymentAmount = '';
    public string $editPaymentDate = '';
    public string $editCheckNumber = '';
    public string $editCheckDueDate = '';
    public string $editCheckBank = '';
    public string $editCheckBranch = '';
    public string $editCheckAccount = '';

    /** The payments box's chosen type for this payment — independent of the deal's planned method. */
    public string $payMethodId = '';

    /** Checks being recorded together: each {number, due_date, amount}. */
    public array $checkRows = [];

    // ===== קישור לתשלום (PaymentLink) =====
    public string $linkAmount = '';

    public string $linkEmail = '';

    public function mount(Deal $deal): void
    {
        abort_unless(auth()->user()->can('deals.manage'), 403);

        $this->deal = $deal->load(['customer.school', 'program', 'bundle', 'status', 'paymentMethod']);
        $this->syncFields();
        // Locked once SUMIT issued anything for the deal (Deal::lockToBusinessEntity()).
        $this->invoiceBusinessEntityId = (string) ($this->deal->business_entity_id ?? '');
        // Set by ⚡document-view when a failed Summit issuance deleted the invoice draft.
        $this->documentError = session('documentError');
        $this->payMethodId = (string) ($this->deal->payment_method_id ?? '');
        $this->resetLinkForm();
        $this->addCheckRow();
    }

    /** The type ('card', 'recurring', 'check', ...) chosen in the payments box. */
    public function payType(): ?string
    {
        return $this->activePaymentMethods->firstWhere('id', (int) $this->payMethodId)?->type;
    }

    /** Defaults: the outstanding balance, to the billing contact (else the primary one). */
    private function resetLinkForm(): void
    {
        $balance = $this->deal->outstandingBalance();
        // A standing-order deal pays its first month by link; a card deal its balance.
        $amount = $this->payType() === 'recurring' ? $this->deal->standingOrderPlan()['monthly'] : $balance;
        $this->linkAmount = $balance > 0 ? (string) round($amount, 2) : '';
        $contacts = $this->linkRecipients;
        $this->linkEmail = (string) ($contacts->firstWhere('is_accounting_contact', true)?->email ?? $contacts->firstWhere('is_primary', true)?->email ?? $contacts->first()?->email ?? '');
    }

    private function syncFields(): void
    {
        $this->loadedVersion = $this->deal->version;
        $this->selectedStatusId = (string) $this->deal->status_id;
        $this->agreedAmount = (string) $this->deal->agreed_amount;
        $this->specialRequest = (string) $this->deal->special_request;
        $this->studentsCount = (string) ($this->deal->students_count ?? '');
        $this->paymentMethodId = (string) ($this->deal->payment_method_id ?? '');
    }

    /**
     * Editing amount/special-request is a plain direct update (this stage's
     * optimistic-locking slice covers status changes and payment recording
     * only — FR-8.19). Changing the payment method goes through
     * Deal::updatePaymentMethod(), which blocks the change once any payment
     * exists (FR-4.30/FR-4.31).
     */
    public function saveDetails(ActivityLogger $activityLogger): void
    {
        $this->detailsError = null;

        $data = $this->validate([
            'agreedAmount' => ['required', 'numeric', 'gt:0'],
            'specialRequest' => ['nullable', 'string'],
            'paymentMethodId' => ['nullable', 'exists:payment_methods,id'],
            'studentsCount' => ['nullable', 'integer', 'min:0'],
        ], [], ['agreedAmount' => 'סכום מוסכם', 'studentsCount' => 'מספר תלמידות']);

        $this->deal->update([
            'agreed_amount' => $data['agreedAmount'],
            'special_request' => $data['specialRequest'] ?: null,
            'students_count' => $data['studentsCount'] !== null && $data['studentsCount'] !== '' ? (int) $data['studentsCount'] : null,
        ]);

        try {
            $this->deal->updatePaymentMethod($data['paymentMethodId'] ?: null);
        } catch (\RuntimeException $e) {
            $this->detailsError = $e->getMessage();
        }

        $activityLogger->log('deal.updated', "עודכנו פרטי עסקה #{$this->deal->id}", [
            'deal_id' => $this->deal->id, 'customer_id' => $this->deal->customer_id,
        ]);

        $this->deal->refresh()->load('paymentMethod');
        $this->syncFields();
    }

    /**
     * FR-8.19 (this stage's slice): status changes go through
     * Deal::updateStatusWithLock(), which enforces `WHERE id = ? AND
     * version = ?` — a conflict (someone else changed the deal first)
     * surfaces here as a friendly banner instead of silently overwriting.
     */
    public function updateStatus(ActivityLogger $activityLogger): void
    {
        $this->statusError = null;

        $data = $this->validate(['selectedStatusId' => ['required', 'exists:status_definitions,id']]);

        $oldStatusName = $this->deal->status?->name;
        $newStatus = StatusDefinition::findOrFail($data['selectedStatusId']);

        try {
            $this->deal->updateStatusWithLock($this->loadedVersion, $newStatus->id);
        } catch (\RuntimeException $e) {
            $this->statusError = $e->getMessage();

            return;
        }

        $activityLogger->log('deal.status_changed', "סטטוס עסקה #{$this->deal->id} שונה מ\"{$oldStatusName}\" ל\"{$newStatus->name}\"", [
            'deal_id' => $this->deal->id,
            'customer_id' => $this->deal->customer_id,
            'metadata' => ['old_status' => $oldStatusName, 'new_status' => $newStatus->name],
        ]);

        $this->deal->load('status');
        $this->syncFields();

        $this->notifySuccess("סטטוס העסקה עודכן ל\"{$newStatus->name}\".");
    }

    /**
     * FR-7.24: the "עדכון סטטוס" submit button only needs a confirmation
     * when the selected status is the terminal/irreversible one — routine
     * status progression (quote -> paid, etc.) isn't warned. The select
     * uses wire:model.live so this re-evaluates server-side on every change.
     */
    public function isSelectedStatusCancellation(): bool
    {
        return optional($this->dealStatuses->firstWhere('id', (int) $this->selectedStatusId))->name === Deal::CANCELLED_STATUS_NAME;
    }

    #[Computed]
    public function dealStatuses()
    {
        return StatusDefinition::where('scope', 'deal')->where('is_active', true)->orderBy('sort_order')->get();
    }

    #[Computed]
    public function paymentMethods()
    {
        return PaymentMethod::where('is_active', true)->orderBy('name')->get();
    }

    // ----- מסמכים (DOCUMENT) -----

    /**
     * Generates the next document in the chain using the sole active
     * template of that type (the template-manager screen is where multiple
     * active templates per type, if ever needed, would be disambiguated —
     * out of scope here). Document::generateFor() is the only place a
     * Document row is ever created and re-checks every business rule
     * server-side regardless of what the UI already disabled.
     */
    /** A deal's own document types — a supplier's expense invoice belongs to the expenses screen. */
    public function dealDocumentTypes(): array
    {
        return array_diff_key(Document::TYPE_LABELS, ['expense_invoice' => true]);
    }

    /** Credit note for a cancelled non-subscription deal — the owner sets what it's for and how much. */
    public string $creditDescription = 'זיכוי בגין ביטול עסקה';
    public string $creditAmount = '';

    public function generateDealCreditNote(ActivityLogger $activityLogger, ExternalOperationRunner $runner, SummitClient $summit, SmoveClient $smove): void
    {
        $this->documentError = null;

        if ($this->deal->subscription) {
            $this->documentError = 'זיכוי על מנוי מופק מכרטיס הלקוח, בלשונית מנוי — לפי החודשים שבוטלו.';

            return;
        }

        $invoiceTotal = (float) ($this->deal->issuedInvoice()?->totalAmount() ?? 0);

        $data = $this->validate([
            'creditDescription' => ['required', 'string', 'max:255'],
            'creditAmount' => ['required', 'numeric', 'gt:0', 'lte:'.$invoiceTotal],
        ], ['creditAmount.lte' => 'סכום הזיכוי לא יכול לעלות על סכום החשבונית (₪'.number_format($invoiceTotal, 2).').'],
            ['creditDescription' => 'פירוט', 'creditAmount' => 'סכום']);

        try {
            ['document' => $document, 'operations' => $operations] = Document::issueCreditNoteFor($this->deal, (float) $data['creditAmount'], $data['creditDescription'], $activityLogger, $runner, $summit, $smove);
        } catch (\RuntimeException $e) {
            $this->documentError = $e->getMessage();

            return;
        }

        $activityLogger->log('document.credit_note_generated', "הופקה חשבונית זיכוי בסך ₪{$data['creditAmount']} לעסקה #{$this->deal->id}: {$data['creditDescription']}", [
            'deal_id' => $this->deal->id, 'customer_id' => $this->deal->customer_id,
            'metadata' => ['document_id' => $document->id, 'amount' => (float) $data['creditAmount']],
        ]);

        unset($this->documents);
        $this->dispatch('close-modals');

        if (($operations['summit'] ?? null)?->status === \App\Models\ExternalOperation::STATUS_FAILED) {
            $this->documentError = 'ההפקה ב-SUMIT נכשלה, ולכן טיוטת הזיכוי נמחקה ולא נשלח דבר ללקוחה. אפשר לנסות שוב. פירוט השגיאה: '.$operations['summit']->error_message;

            return;
        }

        $this->notifySuccess('הזיכוי הופק ב-SUMIT ונשלח ללקוחה במייל.');
    }

    /**
     * The active templates of each template-based document type. With more
     * than one active template of a type, its "+ הפקת …" button asks which
     * one to use (the business owner's 2026-10-16 choice) instead of silently
     * taking the oldest.
     *
     * @return \Illuminate\Support\Collection<string, \Illuminate\Support\Collection<int, DocumentTemplate>>
     */
    #[Computed]
    public function activeTemplatesByType()
    {
        return DocumentTemplate::where('is_active', true)->orderBy('name')->orderBy('id')->get()->groupBy('document_type');
    }

    public function generateDocument(string $documentType, ?int $templateId = null): void
    {
        $this->documentError = null;

        if (in_array($documentType, DocumentTemplate::SYSTEM_TYPES, true)) {
            $template = $documentType;
        } else {
            $active = $this->activeTemplatesByType->get($documentType, collect());

            $template = match (true) {
                $templateId !== null => $active->firstWhere('id', $templateId),
                $active->count() > 1 => false,
                default => $active->first(),
            };

            if ($template === false) {
                $this->documentError = 'יש כמה תבניות פעילות לסוג מסמך זה — יש לבחור באיזו תבנית להשתמש.';

                return;
            }
        }

        if (! $template) {
            $this->documentError = $templateId !== null
                ? 'התבנית שנבחרה כבר אינה פעילה — יש לבחור תבנית אחרת.'
                : 'לא נמצאה תבנית פעילה עבור סוג מסמך זה — יש להגדיר תבנית תחילה במסך תבניות מסמכים.';
            $this->dispatch('close-modals');

            return;
        }

        try {
            $document = Document::generateFor(
                $this->deal,
                $template,
                'digital',
                $documentType === 'invoice' ? ($this->invoiceBusinessEntityId ?: null) : null,
            );
        } catch (\RuntimeException $e) {
            $this->documentError = $e->getMessage();
            $this->dispatch('close-modals');

            return;
        }

        unset($this->documents);

        $this->redirect(route('document-view', $document), navigate: false);
    }

    /**
     * 2026-09-08 request: a document sent as a plain PDF has no online sign
     * form (signUrl() is only generated for a 'digital' send — see
     * Document::sendTo()), so there's no way for confirm() to ever fire on
     * its own; the customer signs on paper and staff records it here
     * instead. Reuses Document::confirm() as-is (same confirmed_at/
     * received_at/signed_at side effects a real online confirmation would
     * have triggered) rather than a parallel "mark signed" concept.
     */
    public function markDocumentSigned(int $documentId, ActivityLogger $activityLogger): void
    {
        $this->documentError = null;

        $document = $this->deal->documents()->findOrFail($documentId);

        try {
            $document->confirm([], $activityLogger);
        } catch (\RuntimeException $e) {
            $this->documentError = $e->getMessage();

            return;
        }

        unset($this->documents);
    }

    #[Computed]
    public function documents()
    {
        return $this->deal->documents()->with(['documentTemplate', 'status'])->get();
    }

    #[Computed]
    public function activeBusinessEntities()
    {
        return BusinessEntity::where('is_active', true)->orderBy('name')->get();
    }

    public function canGenerateDocument(string $documentType): bool
    {
        return Document::canGenerate($this->deal, $documentType);
    }

    // ----- תשלומים (PAYMENT) -----

    /**
     * FR-8.19/FR-4.30-FR-4.34: the only place this screen records a
     * payment — Deal::recordPayment() enforces the version-guarded
     * optimistic lock and the paid/partial status rules server-side.
     */
    public function editPayment(int $paymentId): void
    {
        $payment = $this->deal->payments()->findOrFail($paymentId);

        $this->paymentError = null;
        $this->resetErrorBag();
        $this->editingPaymentId = $payment->id;
        $this->editPaymentAmount = (string) (float) $payment->amount;
        $this->editPaymentDate = (string) $payment->payment_date?->format('Y-m-d');
        $this->editCheckNumber = (string) $payment->check_number;
        $this->editCheckDueDate = (string) $payment->check_due_date?->format('Y-m-d');
        $this->editCheckBank = (string) $payment->check_bank_number;
        $this->editCheckBranch = (string) $payment->check_branch_number;
        $this->editCheckAccount = (string) $payment->check_account_number;
    }

    public function cancelPaymentEdit(): void
    {
        $this->reset(['editingPaymentId', 'editPaymentAmount', 'editPaymentDate', 'editCheckNumber', 'editCheckDueDate', 'editCheckBank', 'editCheckBranch', 'editCheckAccount']);
    }

    public function updatePayment(ActivityLogger $activityLogger): void
    {
        $this->paymentError = null;

        $data = $this->validate([
            'editPaymentAmount' => ['required', 'numeric', 'gt:0'],
            'editPaymentDate' => ['required', 'date'],
            'editCheckNumber' => ['nullable', 'string', 'max:255'],
            'editCheckDueDate' => ['nullable', 'date'],
            'editCheckBank' => ['nullable', 'string', 'max:10'],
            'editCheckBranch' => ['nullable', 'string', 'max:10'],
            'editCheckAccount' => ['nullable', 'string', 'max:30'],
        ], [], ['editPaymentAmount' => 'סכום', 'editPaymentDate' => 'תאריך', 'editCheckDueDate' => 'תאריך פירעון']);

        $payment = $this->deal->payments()->findOrFail($this->editingPaymentId);

        try {
            $this->deal->updatePayment($this->loadedVersion, $payment, [
                'amount' => (float) $data['editPaymentAmount'],
                'payment_date' => $data['editPaymentDate'],
                'check_number' => $data['editCheckNumber'] ?: null,
                'check_due_date' => $data['editCheckDueDate'] ?: null,
                'check_bank_number' => $data['editCheckBank'] ?: null,
                'check_branch_number' => $data['editCheckBranch'] ?: null,
                'check_account_number' => $data['editCheckAccount'] ?: null,
            ]);
        } catch (\RuntimeException $e) {
            $this->paymentError = $e->getMessage();

            return;
        }

        $activityLogger->log('payment.updated', "עודכן תשלום #{$payment->id} בעסקה #{$this->deal->id} (₪{$data['editPaymentAmount']})", [
            'deal_id' => $this->deal->id, 'customer_id' => $this->deal->customer_id,
            'metadata' => ['payment_id' => $payment->id, 'amount' => $data['editPaymentAmount']],
        ]);

        $this->cancelPaymentEdit();
        $this->deal->refresh()->load('status');
        $this->syncFields();
        unset($this->payments);
        $this->notifySuccess('התשלום עודכן.');
    }

    public function deletePayment(int $paymentId, ActivityLogger $activityLogger): void
    {
        $this->paymentError = null;
        $payment = $this->deal->payments()->findOrFail($paymentId);

        try {
            $this->deal->deletePayment($this->loadedVersion, $payment);
        } catch (\RuntimeException $e) {
            $this->paymentError = $e->getMessage();

            return;
        }

        $activityLogger->log('payment.deleted', "נמחק תשלום #{$payment->id} בסך ₪".number_format((float) $payment->amount, 0)." בעסקה #{$this->deal->id} (מחיקה לוגית)", [
            'deal_id' => $this->deal->id, 'customer_id' => $this->deal->customer_id,
            'metadata' => ['payment_id' => $payment->id, 'amount' => (float) $payment->amount],
        ]);

        $this->cancelPaymentEdit();
        $this->deal->refresh()->load('status');
        $this->syncFields();
        unset($this->payments);
        $this->notifySuccess('התשלום נמחק.');
    }

    public function deleteDocument(int $documentId, ActivityLogger $activityLogger): void
    {
        $this->documentError = null;
        $document = $this->deal->documents()->findOrFail($documentId);
        $label = \App\Models\Document::TYPE_LABELS[$document->document_type] ?? $document->document_type;

        try {
            $document->deleteDraft();
        } catch (\RuntimeException $e) {
            $this->documentError = $e->getMessage();

            return;
        }

        $activityLogger->log('document.deleted', "נמחקה טיוטת {$label} בעסקה #{$this->deal->id}", [
            'deal_id' => $this->deal->id, 'customer_id' => $this->deal->customer_id,
            'metadata' => ['document_id' => $documentId, 'document_type' => $document->document_type],
        ]);

        unset($this->documents);
        $this->notifySuccess("טיוטת ה{$label} נמחקה.");
    }

    public function recordPayment(ActivityLogger $activityLogger, ExternalOperationRunner $runner, SummitClient $summit): void
    {
        $this->paymentError = null;

        $data = $this->validate([
            'paymentAmount' => ['required', 'numeric', 'gt:0'],
            'paymentDate' => ['nullable', 'date'],
        ], [], ['paymentAmount' => 'סכום שהתקבל']);

        try {
            $payment = $this->deal->recordPayment(
                $this->loadedVersion,
                (float) $data['paymentAmount'],
                $data['paymentDate'] ?: null,
                (int) $this->payMethodId ?: null,
            );
        } catch (\RuntimeException $e) {
            $this->paymentError = $e->getMessage();

            return;
        }

        $activityLogger->log('payment.recorded', "נרשם תשלום בסך ₪{$data['paymentAmount']} עבור עסקה #{$this->deal->id}", [
            'deal_id' => $this->deal->id,
            'customer_id' => $this->deal->customer_id,
            'metadata' => ['amount' => $data['paymentAmount'], 'payment_id' => $payment->id],
        ]);

        $this->reset(['paymentAmount', 'paymentDate']);
        $this->deal->load('status');
        $this->syncFields();
        unset($this->payments);

        // A check's receipt waits for it to clear (FR-4.28) — markCheckCleared().
        if ($payment->isCheck()) {
            $this->notifySuccess("הצ'ק נרשם (₪{$data['paymentAmount']}). העסקה תסומן כשולמה כשהצ'ק ייפרע ותופק קבלה.");

            return;
        }

        $this->notifyPaymentWithReceipt($payment, "התשלום נרשם (₪{$data['paymentAmount']})", $runner, $summit);
    }

    // ----- צ'קים -----

    public function addCheckRow(): void
    {
        // A new row continues the last one: next number, a month later, same
        // amount — and the same bank/branch/account (same checkbook).
        $last = end($this->checkRows) ?: null;
        $this->checkRows[] = [
            'number' => $last && ctype_digit((string) $last['number']) ? (string) ((int) $last['number'] + 1) : '',
            'due_date' => $last && $last['due_date'] ? \Illuminate\Support\Carbon::parse($last['due_date'])->addMonthNoOverflow()->toDateString() : now()->toDateString(),
            'amount' => $last['amount'] ?? '',
            'bank' => $last['bank'] ?? '',
            'branch' => $last['branch'] ?? '',
            'account' => $last['account'] ?? '',
        ];
    }

    public function removeCheckRow(int $index): void
    {
        unset($this->checkRows[$index]);
        $this->checkRows = array_values($this->checkRows);

        if ($this->checkRows === []) {
            $this->addCheckRow();
        }
    }

    /** Records every check row as a check payment of its own; each one's receipt follows when it clears. */
    public function recordChecks(ActivityLogger $activityLogger): void
    {
        $this->paymentError = null;

        $this->validate([
            'checkRows' => ['required', 'array', 'min:1'],
            'checkRows.*.number' => ['required', 'string', 'max:50'],
            'checkRows.*.due_date' => ['required', 'date'],
            'checkRows.*.amount' => ['required', 'numeric', 'gt:0'],
            'checkRows.*.bank' => ['nullable', 'integer', 'between:1,999'],
            'checkRows.*.branch' => ['nullable', 'digits_between:1,5'],
            'checkRows.*.account' => ['nullable', 'regex:/^[0-9-]{2,20}$/'],
        ], [], [
            'checkRows.*.number' => "מס' צ'ק", 'checkRows.*.due_date' => 'תאריך פירעון', 'checkRows.*.amount' => 'סכום',
            'checkRows.*.bank' => 'בנק', 'checkRows.*.branch' => 'סניף', 'checkRows.*.account' => 'מס\' חשבון',
        ]);

        try {
            $payments = $this->deal->recordChecks($this->loadedVersion, $this->checkRows, (int) $this->payMethodId ?: null);
        } catch (\RuntimeException $e) {
            $this->paymentError = $e->getMessage();

            return;
        }

        $total = array_sum(array_map(fn (Payment $p) => (float) $p->amount, $payments));

        $activityLogger->log('payment.recorded', 'נרשמו '.count($payments)." צ'קים בסך ₪".number_format($total, 2)." עבור עסקה #{$this->deal->id}", [
            'deal_id' => $this->deal->id,
            'customer_id' => $this->deal->customer_id,
            'metadata' => ['amount' => $total, 'payment_ids' => array_map(fn (Payment $p) => $p->id, $payments)],
        ]);

        $this->checkRows = [];
        $this->addCheckRow();
        $this->deal->refresh()->load('status');
        $this->syncFields();
        unset($this->payments);

        $this->notifySuccess(count($payments)." צ'קים נרשמו (₪".number_format($total, 0).'). ביום הפירעון של כל צ\'ק תופיע תזכורת להפקת קבלה.');
    }

    /**
     * Pops up (sticky, via Notifies) whenever the deal risks a double or an
     * over-payment (Deal::paymentRisk()) — after recording any payment,
     * sending a link, registering a standing order, or just opening the deal.
     * A changed risk (other amounts) pops up again.
     */
    public ?string $paymentRiskWarning = null;

    public function rendering(): void
    {
        unset($this->paymentRisk);
        $risk = $this->paymentRisk;

        $this->paymentRiskWarning = $risk
            ? 'חשש לתשלום כפול או ביתר: שולם ועוד צפוי להתקבל ₪'.number_format($risk['committed'], 0).' — יותר מסכום העסקה (₪'.number_format($risk['due'], 0).'). בתיבת התשלומים אפשר לבטל את מה שמיותר.'
            : null;
    }

    /** @see Deal::paymentRisk() */
    #[Computed]
    public function paymentRisk(): ?array
    {
        return $this->deal->fresh()->paymentRisk();
    }

    /** Double payment: withdraws one pending payment link. */
    public function cancelPaymentLink(int $linkId, ActivityLogger $activityLogger): void
    {
        $this->paymentError = null;
        $link = $this->deal->paymentLinks()->findOrFail($linkId);

        try {
            $link->cancel();
        } catch (\RuntimeException $e) {
            $this->paymentError = $e->getMessage();

            return;
        }

        $activityLogger->log('payment_link.cancelled', 'בוטל קישור לתשלום בסך ₪'.number_format((float) $link->amount, 0)." בעסקה #{$this->deal->id}", [
            'deal_id' => $this->deal->id, 'customer_id' => $this->deal->customer_id, 'metadata' => ['payment_link_id' => $link->id],
        ]);

        unset($this->paymentLinks);
        $this->notifySuccess('הקישור לתשלום בוטל. אם הלקוחה כבר פתחה את דף התשלום, כדאי לעדכן אותה שאין לשלם בו.');
    }

    /** Double payment: removes one check not yet cleared (logically, as any mistaken payment). */
    public function cancelCheck(int $paymentId, ActivityLogger $activityLogger): void
    {
        $this->paymentError = null;
        $payment = $this->deal->payments()->findOrFail($paymentId);

        try {
            $this->deal->deletePayment($this->deal->fresh()->version, $payment);
        } catch (\RuntimeException $e) {
            $this->paymentError = $e->getMessage();

            return;
        }

        $activityLogger->log('payment.deleted', "בוטל צ'ק #{$payment->check_number} בסך ₪".number_format((float) $payment->amount, 0)." בעסקה #{$this->deal->id}", [
            'deal_id' => $this->deal->id, 'customer_id' => $this->deal->customer_id,
            'metadata' => ['payment_id' => $payment->id, 'amount' => (float) $payment->amount],
        ]);

        $this->deal->refresh()->load('status');
        $this->syncFields();
        unset($this->payments);
        $this->notifySuccess("הצ'ק בוטל.");
    }

    /** Double payment: the user stopped the standing order in SUMIT (no API for it) and records that here. */
    public function markStandingOrderStopped(ActivityLogger $activityLogger): void
    {
        $this->deal->markStandingOrderStopped();

        $activityLogger->log('deal.standing_order_stopped', "הוראת הקבע של עסקה #{$this->deal->id} סומנה כמופסקת ב-SUMIT", [
            'deal_id' => $this->deal->id, 'customer_id' => $this->deal->customer_id,
        ]);

        $this->notifySuccess('הוראת הקבע סומנה כמופסקת.');
    }

    /**
     * Issues the receipt for a payment just recorded/cleared and tells the
     * user how it went. The payment stands either way; a receipt that
     * couldn't be issued stays available from the payments table.
     */
    private function notifyPaymentWithReceipt(Payment $payment, string $done, ExternalOperationRunner $runner, SummitClient $summit): void
    {
        if ($this->beforePaymentReceipt) {
            $this->notifySuccess("{$done} — מכוסה בקבלה שהופקה לפני התשלום.");

            return;
        }

        if (! $this->deal->issuedInvoice()) {
            $this->notifySuccess("{$done}. הקבלה תופק אחרי הפקת חשבונית לעסקה.");

            return;
        }

        try {
            Receipt::issueFor($this->deal->refresh(), $payment, $runner, $summit);
        } catch (\RuntimeException $e) {
            $this->paymentError = "{$done}, אך הקבלה לא הופקה: {$e->getMessage()}";

            return;
        } finally {
            unset($this->payments);
        }

        $this->notifySuccess($summit->isActive() ? "{$done} והקבלה נשלחה ללקוחה." : "{$done} והקבלה נרשמה במערכת (SUMIT מושבת).");
    }

    /** The payments box's type choice — only for the payment being taken; the deal's planned method is untouched. */
    public function selectPaymentMethod(int $methodId): void
    {
        $this->paymentError = null;
        $this->payMethodId = (string) $this->activePaymentMethods->firstWhere('id', $methodId)?->id;
        $this->resetLinkForm();
        $this->resetErrorBag();
    }

    /**
     * Build-plan 12: charges via Summit first, then records the payment —
     * see Deal::chargeCardViaSummit()'s docblock for why this is the one
     * payment path where the external call happens before the local record.
     */
    public function chargeCard(ActivityLogger $activityLogger, ExternalOperationRunner $runner, SummitClient $summit): void
    {
        $this->paymentError = null;

        $data = $this->validate([
            'paymentAmount' => ['required', 'numeric', 'gt:0'],
        ], [], ['paymentAmount' => 'סכום לסליקה']);

        try {
            $payment = $this->deal->chargeCardViaSummit($this->loadedVersion, (float) $data['paymentAmount'], $runner, $summit);
        } catch (\RuntimeException $e) {
            $this->paymentError = $e->getMessage();

            return;
        }

        $activityLogger->log('payment.recorded', "נסלק אשראי ונרשם תשלום בסך ₪{$data['paymentAmount']} עבור עסקה #{$this->deal->id}", [
            'deal_id' => $this->deal->id,
            'customer_id' => $this->deal->customer_id,
            'metadata' => ['amount' => $data['paymentAmount'], 'payment_id' => $payment->id],
        ]);

        $this->reset(['paymentAmount', 'paymentDate']);
        $this->deal->load('status');
        $this->syncFields();
        unset($this->payments);

        // The receipt follows the charge right away (SUMIT emails it to the
        // customer). If it fails the payment stays — it can be issued again
        // from the payments table.
        try {
            Receipt::issueFor($this->deal->refresh(), $payment, $runner, $summit);
        } catch (\RuntimeException $e) {
            $this->paymentError = "הסליקה הושלמה והתשלום נרשם (₪{$data['paymentAmount']}), אך הקבלה לא הופקה: {$e->getMessage()}";

            return;
        }

        $this->notifySuccess("הסליקה הושלמה, התשלום נרשם והקבלה נשלחה ללקוחה (₪{$data['paymentAmount']}).");
    }

    /**
     * Opens a SUMIT payment page (PaymentLink::createFor()) and emails it.
     * If only the email fails the link still exists — it's listed with a
     * copy button, so it can be sent by hand.
     */
    public function sendPaymentLink(ExternalOperationRunner $runner, SummitClient $summit, SmoveClient $smove): void
    {
        $this->paymentError = null;

        $data = $this->validate([
            'linkAmount' => ['required', 'numeric', 'gt:0'],
            'linkEmail' => ['required', 'email'],
        ], [], ['linkAmount' => 'סכום לתשלום', 'linkEmail' => 'דוא"ל הנמענת']);

        $purpose = $this->payType() === 'recurring' ? PaymentLink::PURPOSE_STANDING_ORDER : PaymentLink::PURPOSE_PAYMENT;

        try {
            $link = PaymentLink::createFor($this->deal, (float) $data['linkAmount'], $runner, $summit, $purpose);
        } catch (\RuntimeException $e) {
            $this->paymentError = $e->getMessage();

            return;
        }

        unset($this->paymentLinks);
        $this->deal->refresh();

        try {
            $link->send($data['linkEmail'], $this->recipientName($data['linkEmail']), $runner, $smove);
        } catch (\RuntimeException $e) {
            $this->paymentError = 'דף התשלום נוצר, אך '.$e->getMessage();

            return;
        }

        $this->notifySuccess("הקישור לתשלום (₪{$data['linkAmount']}) נשלח אל {$data['linkEmail']}.");
    }

    public function resendPaymentLink(int $linkId, ExternalOperationRunner $runner, SmoveClient $smove): void
    {
        $this->paymentError = null;

        $link = $this->deal->paymentLinks()->findOrFail($linkId);

        if ($link->isPaid() || $link->isExpired() || $link->isCancelled()) {
            $this->paymentError = 'הקישור כבר '.($link->isPaid() ? 'שולם' : ($link->isCancelled() ? 'בוטל' : 'פג תוקפו')).' — אין לשלוח אותו שוב.';

            return;
        }

        $email = $link->sent_to_email ?: $this->linkEmail;

        try {
            $link->send($email, $this->recipientName($email), $runner, $smove);
        } catch (\RuntimeException $e) {
            $this->paymentError = $e->getMessage();

            return;
        }

        unset($this->paymentLinks);
        $this->notifySuccess("הקישור נשלח שוב אל {$email}.");
    }

    private function recipientName(string $email): string
    {
        return $this->linkRecipients->firstWhere('email', $email)?->name ?? ($this->deal->customer->school?->name ?? $email);
    }

    /** A standing order's months at the monthly amount typed in (the payments box's preview). */
    #[Computed]
    public function standingOrderPlan(): array
    {
        return $this->deal->standingOrderPlan(is_numeric($this->linkAmount) && (float) $this->linkAmount > 0 ? (float) $this->linkAmount : null);
    }

    #[Computed]
    public function activePaymentMethods()
    {
        return PaymentMethod::where('is_active', true)
            ->orderByRaw("CASE type WHEN 'card' THEN 1 WHEN 'recurring' THEN 2 WHEN 'bank_transfer' THEN 3 WHEN 'check' THEN 4 ELSE 5 END")
            ->orderBy('name')
            ->get();
    }

    /** The customer's contacts with an email — who a payment link can go to. */
    #[Computed]
    public function linkRecipients()
    {
        return $this->deal->customer->contacts()->whereNotNull('email')->where('email', '!=', '')->orderByDesc('is_accounting_contact')->orderByDesc('is_primary')->get();
    }

    #[Computed]
    public function paymentLinks()
    {
        return $this->deal->paymentLinks()->get();
    }

    /** Build-plan 12 — Deal::registerStandingOrderWithSummit(). */
    public function registerStandingOrder(ExternalOperationRunner $runner, SummitClient $summit): void
    {
        $this->paymentError = null;

        try {
            $firstPayment = $this->deal->registerStandingOrderWithSummit($runner, $summit, is_numeric($this->linkAmount) ? (float) $this->linkAmount : null);
        } catch (\RuntimeException $e) {
            $this->paymentError = $e->getMessage();

            return;
        }

        $this->deal->refresh()->load('status');
        $this->syncFields();
        unset($this->payments);

        $this->notifySuccess($firstPayment
            ? "הוראת הקבע נרשמה ב-SUMIT, והתשלום הראשון (₪{$firstPayment->amount}) נגבה ונרשם. הקבלה נשלחה ללקוחה."
            : 'הוראת הקבע נרשמה ב-SUMIT.');
    }

    /** FR-4.28: a separate explicit action from "received" — never inferred. */
    public function markCheckCleared(int $paymentId, ActivityLogger $activityLogger, ExternalOperationRunner $runner, SummitClient $summit): void
    {
        $this->paymentError = null;

        $payment = $this->deal->payments()->findOrFail($paymentId);

        try {
            $payment->markCleared();
        } catch (\RuntimeException $e) {
            $this->paymentError = $e->getMessage();

            return;
        }

        $activityLogger->log('payment.check_cleared', "צ'ק בסך ₪{$payment->amount} נפרע עבור עסקה #{$this->deal->id}", [
            'deal_id' => $this->deal->id, 'customer_id' => $this->deal->customer_id, 'metadata' => ['payment_id' => $payment->id],
        ]);

        unset($this->payments);

        $this->notifyPaymentWithReceipt($payment->fresh(), "הצ'ק סומן כנפרע", $runner, $summit);
        $this->deal->refresh()->load('status');
        $this->syncFields();
    }

    /**
     * FR-4.5/FR-4.23-FR-4.29: $paymentId null issues "קבלה לפני תשלום"
     * (FR-4.24/FR-4.25) — Receipt::issueFor() is the only place a receipt is
     * ever created and re-checks every gate server-side.
     */
    public function issueReceipt(?int $paymentId, ActivityLogger $activityLogger, ExternalOperationRunner $runner, SummitClient $summit): void
    {
        $this->paymentError = null;

        $payment = $paymentId ? $this->deal->payments()->findOrFail($paymentId) : null;

        try {
            $receipt = Receipt::issueFor($this->deal, $payment, $runner, $summit);
        } catch (\RuntimeException $e) {
            $this->paymentError = $e->getMessage();

            return;
        }

        $activityLogger->log('receipt.issued', $receipt->issued_before_payment
            ? "הופקה קבלה לפני תשלום עבור עסקה #{$this->deal->id}"
            : "הופקה קבלה עבור תשלום בעסקה #{$this->deal->id}", [
            'deal_id' => $this->deal->id, 'customer_id' => $this->deal->customer_id, 'metadata' => ['receipt_id' => $receipt->id, 'payment_id' => $payment?->id],
        ]);

        unset($this->payments, $this->beforePaymentReceipt);
        $this->deal->refresh()->load('status');
        $this->syncFields();

        if (! $summit->isActive()) {
            $this->notifyInfo('הקבלה נרשמה במערכת בלבד — החיבור ל-SUMIT מושבת.');

            return;
        }

        $this->notifySuccess('הקבלה הופקה בהצלחה.');
    }

    #[Computed]
    public function payments()
    {
        return $this->deal->payments()->with(['paymentMethod', 'status', 'receipt'])->orderByDesc('id')->get();
    }

    /** FR-4.25: the deal's receipt before payment, if one was issued — it covers the whole invoice. */
    #[Computed]
    public function beforePaymentReceipt(): ?Receipt
    {
        return Receipt::whereHas('document', fn ($q) => $q->where('deal_id', $this->deal->id))
            ->where('issued_before_payment', true)
            ->first();
    }

    #[Computed]
    public function totalPaid(): float
    {
        return $this->deal->totalPaid();
    }

    #[Computed]
    public function outstandingBalance(): float
    {
        return $this->deal->outstandingBalance();
    }
};
?>

<div>
    <div class="topbar">
        <div>
            <span class="badge {{ \App\Models\Deal::badgeClassForStatusName($deal->status?->name) }}" style="margin-bottom:8px; display:inline-flex">{{ $deal->status?->name }}</span>
            <h1>{{ $deal->program_name_snapshot ?? $deal->bundle_name_snapshot }}</h1>
            <p style="color:var(--color-text-secondary); margin:0">
                לקוחה: {{ $deal->customer->school?->name ?? '—' }}
                @if ($deal->customer->hasActiveSubscription())
                    <span class="badge badge-primary" style="margin-inline-start:4px">מנויה פעילה</span>
                @endif
                ·
                נפתחה <span class="ltr-num">{{ $deal->purchased_at->format('d/m/Y') }}</span>
            </p>
        </div>
        <div style="display:flex; gap:var(--sp-sm)">
            <a href="{{ route('customer-detail', $deal->customer) }}" class="btn btn-ghost">חזרה לכרטיס הלקוחה</a>
        </div>
    </div>


    {{-- A cancelled subscription deal: what was decided in the cancellation window (⚡customer-detail.blade.php). --}}
    @if ($deal->status?->name === Deal::CANCELLED_STATUS_NAME && ($cancelled = $deal->subscription)?->cancelled_at)
        <div class="card" style="margin-bottom:var(--sp-lg); border-color:var(--color-error)">
            <h3>פרטי ביטול המנוי</h3>
            <div class="info-grid">
                <div class="item"><div class="k">תאריך ביטול</div><div class="v ltr-num">{{ $cancelled->cancelled_at->format('d/m/Y') }}</div></div>
                @if ($cancelled->cancelled_from_month)
                    <div class="item"><div class="k">בוטל החל מחודש</div><div class="v">חודש {{ $cancelled->cancelled_from_month }} · {{ $cancelled->monthLabel($cancelled->cancelled_from_month) }}</div></div>
                @endif
                <div class="item danger"><div class="k">סכום הזיכוי</div><div class="v ltr-num">₪{{ number_format((float) $cancelled->cancellation_credit, 0) }}</div></div>
                <div class="item wide"><div class="k">סיבת הביטול</div><div class="v">{{ $cancelled->cancellation_reason ?: 'לא נרשמה סיבה' }}</div></div>
            </div>
        </div>
    @endif

    <div class="cols2">
        <div>
            {{-- ===== פרטי המוצר הנמכר (Snapshot) ===== --}}
            <div class="card" style="margin-bottom:var(--sp-lg)">
                <h3>פרטי המוצר בעת המכירה</h3>
                <div class="info-grid">
                    @if ($deal->program_id)
                        <div class="item"><div class="k">תוכנית</div><div class="v">{{ $deal->program_name_snapshot }}</div></div>
                        <div class="item"><div class="k">מחיר בעת המכירה</div><div class="v ltr-num">₪{{ number_format((float) $deal->program_price_snapshot, 0) }}</div></div>
                    @else
                        <div class="item"><div class="k">מארז</div><div class="v">{{ $deal->bundle_name_snapshot }}</div></div>
                        <div class="item"><div class="k">מחיר בעת המכירה</div><div class="v ltr-num">₪{{ number_format((float) $deal->bundle_price_snapshot, 0) }}</div></div>
                    @endif
                </div>
            </div>

            {{-- ===== פרטי עסקה: סכום, אמצעי תשלום, מספר תלמידות, בקשה מיוחדת ===== --}}
            <div class="card">
                <h3>פרטי עסקה</h3>
                <form wire:submit="saveDetails" class="form-grid">
                    <div>
                        <label for="agreedAmount">סכום מוסכם</label>
                        <input type="text" id="agreedAmount" wire:model="agreedAmount" class="ltr-num" dir="ltr">
                        @error('agreedAmount') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
                    </div>
                    <div>
                        <label for="paymentMethodId">אמצעי תשלום מתוכנן</label>
                        <select id="paymentMethodId" wire:model="paymentMethodId">
                            <option value="">— ללא —</option>
                            @foreach ($this->paymentMethods as $option)
                                <option value="{{ $option->id }}">{{ $option->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="studentsCount">מספר תלמידות</label>
                        <input type="text" id="studentsCount" wire:model="studentsCount" class="ltr-num" dir="ltr" inputmode="numeric">
                        @error('studentsCount') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
                    </div>
                    <div class="full">
                        <label for="specialRequest">בקשת התאמה מיוחדת</label>
                        <textarea id="specialRequest" wire:model="specialRequest" rows="3" placeholder="הערה חופשית — ללא סטטוס או תהליך נפרד"></textarea>
                    </div>
                    <div class="full"><button type="submit" class="btn btn-primary">שמירת פרטי עסקה</button></div>
                </form>
            </div>
        </div>

        <div>
            {{-- ===== סטטוס עסקי ===== --}}
            <div class="card">
                <h3>סטטוס עסקה</h3>
                <form wire:submit="updateStatus" class="form-grid">
                    <div class="full">
                        <label for="selectedStatusId">סטטוס</label>
                        <select id="selectedStatusId" wire:model.live="selectedStatusId">
                            @foreach ($this->dealStatuses as $status)
                                <option value="{{ $status->id }}">{{ $status->name }}</option>
                            @endforeach
                        </select>
                        @error('selectedStatusId') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
                    </div>
                    <div class="full">
                        <button
                            type="submit"
                            class="btn btn-primary"
                            @if ($this->isSelectedStatusCancellation()) wire:confirm="ביטול העסקה אינו הפיך — האם להמשיך?" @endif
                        >עדכון סטטוס</button>
                    </div>
                </form>
                @if ($deal->completed_at)
                    <div class="field" style="margin-top:var(--sp-md)"><div class="k">הושלמה בתאריך</div><div class="v ltr-num">{{ $deal->completed_at->format('d/m/Y H:i') }}</div></div>
                @endif
            </div>
        </div>
    </div>

    {{-- ===== מסמכים (DOCUMENT) — build-plan 07 ===== --}}
    <div class="card" style="margin-top:var(--sp-lg)">
        <h3>מסמכים</h3>


        <div class="doc-stepper">
            @foreach ($this->dealDocumentTypes() as $type => $label)
                @php $existing = $this->documents->where('document_type', $type); @endphp
                <span class="doc-step {{ $existing->isNotEmpty() ? 'done' : '' }}">{{ $label }} ({{ $existing->count() }})</span>
                @if (! $loop->last)<span class="doc-step-arrow">←</span>@endif
            @endforeach
        </div>

        <div style="display:flex; gap:var(--sp-sm); flex-wrap:wrap; margin-bottom:var(--sp-lg); align-items:flex-end">
            @foreach ($this->dealDocumentTypes() as $type => $label)
                @if ($type === 'credit_note')
                    {{-- A subscription's credit is issued from the customer card (months cancelled); any other deal's here. --}}
                    @unless ($deal->subscription)
                        @if ($this->canGenerateDocument('credit_note'))
                            <x-modal trigger-label="+ הפקת חשבונית זיכוי" trigger-class="btn btn-secondary" title="חשבונית זיכוי">
                                <form wire:submit="generateDealCreditNote" class="form-grid">
                                    <div class="full">
                                        <label for="creditDescription">פירוט</label>
                                        <input type="text" id="creditDescription" wire:model="creditDescription">
                                        @error('creditDescription') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
                                    </div>
                                    <div>
                                        <label for="creditAmount">סכום הזיכוי</label>
                                        <input type="text" id="creditAmount" wire:model="creditAmount" class="ltr-num" dir="ltr" placeholder="₪{{ number_format((float) ($deal->issuedInvoice()?->totalAmount() ?? 0), 0) }}">
                                        @error('creditAmount') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
                                    </div>
                                    <div class="full"><button type="submit" class="btn btn-primary" wire:confirm="הזיכוי יופק ב-SUMIT ויישלח ללקוחה במייל. זו פעולה חשבונאית בלתי הפיכה — להמשיך?">הפקת חשבונית זיכוי</button></div>
                                </form>
                            </x-modal>
                        @else
                            <button type="button" class="btn btn-secondary" disabled>+ הפקת חשבונית זיכוי</button>
                        @endif
                    @endunless
                @elseif ($type === 'invoice')
                    <div style="display:flex; gap:6px; align-items:flex-end">
                        <div>
                            <label for="invoiceBusinessEntityId" style="margin-bottom:4px">עוסק @if ($deal->business_entity_id)<span class="scope-pill" title="לעסקה כבר הופקו מסמכים ב-SUMIT מעוסק זה — כל מסמכי העסקה מופקים ממנו">נעול לעסקה</span>@endif</label>
                            <select id="invoiceBusinessEntityId" wire:model="invoiceBusinessEntityId" style="min-width:220px" @disabled($deal->business_entity_id)>
                                <option value="">בחרו עוסק</option>
                                @foreach ($this->activeBusinessEntities as $entity)
                                    <option value="{{ $entity->id }}">{{ $entity->name }} — {{ $entity->classification }}</option>
                                @endforeach
                            </select>
                        </div>
                        <button type="button" wire:click="generateDocument('invoice')" class="btn btn-primary" @disabled(! $this->canGenerateDocument('invoice'))>הפקת חשבונית</button>
                    </div>
                @elseif (($choices = $this->activeTemplatesByType->get($type, collect()))->count() > 1 && $this->canGenerateDocument($type))
                    {{-- Several active templates of this type — pick one. --}}
                    <x-modal :trigger-label="'+ הפקת '.$label" trigger-class="btn btn-secondary" :title="'באיזו תבנית להפיק '.$label.'?'">
                        <div style="display:flex; flex-direction:column; gap:var(--sp-sm)">
                            @foreach ($choices as $choice)
                                <button type="button" wire:click="generateDocument('{{ $type }}', {{ $choice->id }})" wire:loading.attr="disabled" class="template-choice">
                                    <span class="template-choice-name">{{ $choice->name }}</span>
                                    <span class="template-choice-excerpt">{{ \Illuminate\Support\Str::limit(trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</div>'], ' ', $choice->content))))), 140) }}</span>
                                    <span class="template-choice-meta">עודכנה ב-<span class="ltr-num">{{ $choice->updated_at?->format('d/m/Y') }}</span></span>
                                </button>
                            @endforeach
                        </div>
                    </x-modal>
                @else
                    <button type="button" wire:click="generateDocument('{{ $type }}')" class="btn btn-secondary" @disabled(! $this->canGenerateDocument($type))>+ הפקת {{ $label }}</button>
                @endif
            @endforeach
        </div>

        @if ($this->documents->isEmpty())
            <div class="empty-state">טרם הופקו מסמכים לעסקה זו.</div>
        @else
            <div class="table-scroll">
            <table>
                <thead><tr><th>סוג</th><th>אופן שליחה</th><th>סטטוס</th><th>נשלח</th><th>התקבל</th><th>נחתם</th><th></th></tr></thead>
                <tbody>
                    @foreach ($this->documents as $document)
                        <tr>
                            <td>{{ \App\Models\Document::TYPE_LABELS[$document->document_type] ?? $document->document_type }}</td>
                            <td>
                                @if (! $document->sent_at)
                                    <span class="text-text-secondary">—</span>
                                @elseif ($document->format === 'pdf')
                                    <span class="badge badge-neutral">קובץ PDF</span>
                                @else
                                    <span class="badge badge-neutral">טופס מקוון</span>
                                @endif
                            </td>
                            <td>
                                @if ($document->engagementStatusLabel())
                                    <span class="badge badge-neutral">{{ $document->engagementStatusLabel() }}</span>
                                @else
                                    <span class="text-text-secondary">—</span>
                                @endif
                            </td>
                            <td class="ltr-num">{{ $document->sent_at?->format('d/m/Y') ?? '—' }}</td>
                            <td class="ltr-num">{{ $document->received_at?->format('d/m/Y') ?? '—' }}</td>
                            <td class="ltr-num">{{ $document->signed_at?->format('d/m/Y') ?? '—' }}</td>
                            <td style="white-space:nowrap">
                                <a href="{{ route('document-view', $document) }}" class="btn btn-ghost btn-sm">צפייה</a>
                                @if ($document->isDeletableDraft())
                                    <button type="button" wire:click="deleteDocument({{ $document->id }})" wire:confirm="למחוק את הטיוטה?" class="btn btn-ghost btn-sm" style="color:var(--color-error)">מחיקה</button>
                                @endif
                                @if ($document->format === 'pdf' && $document->sent_at && ! $document->confirmed_at && ! in_array($document->document_type, ['invoice', 'credit_note'], true))
                                    <button type="button" wire:click="markDocumentSigned({{ $document->id }})" wire:confirm="לסמן את המסמך כנחתם? פעולה זו מדמה חתימה מקוונת של הלקוחה." class="btn btn-secondary btn-sm">סמן כנחתם</button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        @endif
    </div>

    {{-- ===== תשלומים וקבלות (PAYMENT/RECEIPT) =====
         Summary → 1. payment type (the deal's payment method, locked once a
         payment exists — FR-4.30/FR-4.31) → 2. amount and the action that
         type takes: a SUMIT payment link (card), a link that sets up a
         standing order (recurring), or recording it here with its receipt. --}}
    @php
        $method = $this->activePaymentMethods->firstWhere('id', (int) $payMethodId);
        $type = $method?->type;
        $hasInvoice = $deal->issuedInvoice() !== null;
        $credited = $deal->totalCredited();
        $standingOrderRegistered = $deal->standingOrderItemIds() !== [];
        $typeHints = ['card' => 'קישור לתשלום ללקוחה', 'recurring' => 'חיוב חודשי אוטומטי', 'bank_transfer' => 'רישום ידני + קבלה', 'check' => 'קבלה בעת פירעון'];
    @endphp
    <div class="card" style="margin-top:var(--sp-lg)">
        <h3>תשלומים</h3>


        <div class="pay-summary">
            <div class="cell"><div class="k">סכום עסקה</div><div class="v ltr-num">₪{{ number_format((float) $deal->agreed_amount, 0) }}</div></div>
            @if ($credited > 0)
                <div class="cell"><div class="k">זוכה{{ $deal->issuedCreditTotal() < $credited - 0.005 ? ' (חשבונית הזיכוי טרם הופקה)' : '' }}</div><div class="v ltr-num">₪{{ number_format($credited, 0) }}</div></div>
            @endif
            <div class="cell"><div class="k">שולם</div><div class="v ltr-num">₪{{ number_format($this->totalPaid, 0) }}</div></div>
            @php $pendingChecks = $this->deal->pendingChecks(); @endphp
            @if ($pendingChecks->isNotEmpty())
                <div class="cell"><div class="k">צ'קים שטרם נפרעו ({{ $pendingChecks->count() }}) — לא כלולים בשולם</div><div class="v ltr-num">₪{{ number_format($pendingChecks->sum('amount'), 0) }}</div></div>
            @endif
            <div class="cell {{ $this->outstandingBalance > 0 ? 'due' : 'settled' }}"><div class="k">יתרה לתשלום</div><div class="v ltr-num">₪{{ number_format($this->outstandingBalance, 0) }}</div></div>
        </div>

        @if ($risk = $this->paymentRisk)
            <div class="card" style="border-color:var(--color-warning); background:var(--color-warning-bg); margin-bottom:var(--sp-lg)">
                <strong>חשש לתשלום כפול או ביתר</strong>
                <p style="margin:var(--sp-xs) 0 var(--sp-sm)">
                    סכום העסקה ₪{{ number_format($risk['due'], 0) }}, אבל שולם ועוד צפוי להתקבל ₪{{ number_format($risk['committed'], 0) }}.
                    @if ($risk['checks']->isEmpty() && $risk['links']->isEmpty() && $risk['standingOrder'] === null)
                        נרשמו תשלומים מעבר לסכום העסקה — יש לבדוק בטבלת התשלומים, ולהחזיר ללקוחה או להפיק זיכוי במידת הצורך.
                    @else
                        בטלו את מה שמיותר:
                    @endif
                </p>
                <div style="display:flex; flex-direction:column; gap:var(--sp-xs)">
                    @if ($risk['paid'] > 0)
                        <div>שולם: <span class="ltr-num">₪{{ number_format($risk['paid'], 0) }}</span></div>
                    @endif
                    @foreach ($risk['checks'] as $check)
                        <div style="display:flex; gap:var(--sp-sm); align-items:center; flex-wrap:wrap" wire:key="risk-check-{{ $check->id }}">
                            <span>צ'ק {{ $check->check_number ? '#'.$check->check_number : '' }} שטרם נפרע: <span class="ltr-num">₪{{ number_format((float) $check->amount, 0) }}</span></span>
                            @if ($check->isEditable())
                                <button type="button" class="btn btn-secondary btn-sm" wire:click="cancelCheck({{ $check->id }})" wire:confirm="לבטל את הצ'ק הזה?">ביטול הצ'ק</button>
                            @endif
                        </div>
                    @endforeach
                    @foreach ($risk['links'] as $link)
                        <div style="display:flex; gap:var(--sp-sm); align-items:center; flex-wrap:wrap" wire:key="risk-link-{{ $link->id }}">
                            <span>קישור לתשלום שטרם שולם: <span class="ltr-num">₪{{ number_format((float) $link->amount, 0) }}</span></span>
                            <button type="button" class="btn btn-secondary btn-sm" wire:click="cancelPaymentLink({{ $link->id }})" wire:confirm="לבטל את הקישור לתשלום?">ביטול הקישור לתשלום</button>
                        </div>
                    @endforeach
                    @if ($risk['standingOrder'] !== null)
                        <div style="display:flex; gap:var(--sp-sm); align-items:center; flex-wrap:wrap">
                            <span>הוראת קבע פעילה ב-SUMIT, צפויה לגבות עוד: <span class="ltr-num">₪{{ number_format($risk['standingOrder'], 0) }}</span></span>
                            <button type="button" class="btn btn-secondary btn-sm" wire:click="markStandingOrderStopped" wire:confirm="אין אפשרות לעצור הוראת קבע מכאן — יש לעצור אותה קודם ב-SUMIT. האם היא כבר נעצרה שם?">הוראת הקבע נעצרה ב-SUMIT</button>
                        </div>
                    @endif
                </div>
            </div>
        @endif

        @if ($this->outstandingBalance > 0)
            {{-- 1. סוג תשלום --}}
            <p class="pay-step"><span class="n">1</span> איך משלמים הפעם?</p>
            <div class="pay-types" role="radiogroup" aria-label="אמצעי תשלום">
                @foreach ($this->activePaymentMethods as $option)
                    <button type="button" role="radio" aria-checked="{{ $option->id === $method?->id ? 'true' : 'false' }}"
                        class="pay-type {{ $option->id === $method?->id ? 'selected' : '' }}"
                        wire:click="selectPaymentMethod({{ $option->id }})">
                        <span class="label">{{ $option->name }}</span>
                        <span class="hint">{{ $typeHints[$option->type] ?? 'רישום ידני + קבלה' }}</span>
                    </button>
                @endforeach
            </div>

            {{-- 2. סכום ופעולה --}}
            @if ($method)
                <p class="pay-step"><span class="n">2</span>
                    {{ match ($type) { 'card' => 'סכום ושליחת קישור לתשלום', 'recurring' => 'הקמת הוראת קבע', 'check' => "רישום צ'ק", default => 'רישום התשלום והפקת קבלה' } }}
                </p>
                <div class="pay-panel">
                    @if ($type === 'recurring' && $standingOrderRegistered)
                        <div class="actions">
                            <span class="badge badge-success">הוראת קבע רשומה ב-SUMIT</span>
                            <p class="note">כל חיוב חודשי נרשם כאן אוטומטית, והקבלה נשלחת ללקוחה מ-SUMIT.</p>
                        </div>
                    @elseif ($type === 'recurring' && $deal->subscription && ! $deal->subscription->isActive())
                        <p class="note">המנוי של העסקה בוטל — לא מקימים עבורו הוראת קבע. ניתן לבחור אמצעי תשלום אחר לגביית היתרה.</p>
                    @elseif (in_array($type, ['card', 'recurring'], true))
                        <form wire:submit="sendPaymentLink" class="form-grid">
                            <div>
                                <label for="linkAmount">{{ $type === 'recurring' ? 'סכום חודשי' : 'סכום לתשלום' }}</label>
                                <input type="text" id="linkAmount" wire:model.live.debounce.400ms="linkAmount" class="ltr-num" dir="ltr" placeholder="₪">
                                @error('linkAmount') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
                            </div>
                            <div>
                                <label for="linkEmail">שליחה אל (דוא"ל)</label>
                                <input type="email" id="linkEmail" wire:model="linkEmail" list="linkEmailContacts" class="ltr-num" dir="ltr" placeholder="name@example.com" autocomplete="off">
                                <datalist id="linkEmailContacts">
                                    @foreach ($this->linkRecipients as $contact)
                                        <option value="{{ $contact->email }}">{{ $contact->name }}</option>
                                    @endforeach
                                </datalist>
                                @error('linkEmail') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
                            </div>
                            @if ($type === 'recurring')
                                @php $plan = $this->standingOrderPlan; @endphp
                                <p class="full note">
                                    ₪{{ number_format($plan['monthly'], 0) }} × {{ $plan['months'] }} חודשים.
                                    הלקוחה משלמת בקישור את החודש הראשון (הכרטיס נשמר ב-SUMIT), ומהחודש הבא החיוב אוטומטי מאותו כרטיס. על כל תשלום נשלחת קבלה.
                                </p>
                            @else
                                <p class="full note">הלקוחה משלמת בכרטיס אשראי בדף המאובטח של SUMIT. התשלום נרשם כאן והקבלה נשלחת אליה אוטומטית.</p>
                            @endif
                            <div class="full actions">
                                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="sendPaymentLink" @disabled(! $hasInvoice)>
                                    {{ $type === 'recurring' ? 'שליחת קישור להקמת הוראת קבע' : 'שליחת קישור לתשלום' }}
                                </button>
                                @unless ($hasInvoice)
                                    <span class="note">יש להפיק חשבונית לעסקה תחילה.</span>
                                @endunless
                            </div>
                        </form>

                        {{-- Without a link: the card is already saved on the customer's SUMIT card. --}}
                        <details class="pay-secondary">
                            <summary class="note" style="cursor:pointer">{{ $type === 'recurring' ? 'הכרטיס כבר שמור ב-SUMIT? רישום הוראת הקבע ישירות' : 'הכרטיס כבר שמור ב-SUMIT? חיוב ישיר' }}</summary>
                            @if ($type === 'recurring')
                                @php $plan = $this->standingOrderPlan; @endphp
                                <div class="actions" style="margin-top:var(--sp-sm)">
                                    <button type="button" wire:click="registerStandingOrder"
                                        wire:confirm="הוראת קבע מהכרטיס השמור של הלקוחה ב-SUMIT: ₪{{ number_format($plan['monthly'], 2) }} × {{ $plan['months'] }} חודשים, {{ $plan['startDate'] ? 'החל מ-'.\Illuminate\Support\Carbon::parse($plan['startDate'])->format('d/m/Y') : 'החיוב הראשון מיד' }}. להמשיך?"
                                        wire:loading.attr="disabled" class="btn btn-secondary" @disabled($deal->subscription && ! $deal->subscription->isActive())>רישום הוראת קבע מהכרטיס השמור</button>
                                </div>
                            @else
                                <form wire:submit="chargeCard" class="actions" style="margin-top:var(--sp-sm)">
                                    <input type="text" wire:model="paymentAmount" class="ltr-num" dir="ltr" placeholder="₪ סכום" style="max-width:160px" aria-label="סכום לחיוב">
                                    <button type="submit" class="btn btn-secondary" wire:confirm="לחייב עכשיו את הכרטיס השמור של הלקוחה?">חיוב הכרטיס השמור</button>
                                    @error('paymentAmount') <div style="color: var(--color-error); font-size: var(--fs-caption)">{{ $message }}</div> @enderror
                                </form>
                            @endif
                        </details>
                    @elseif ($type === 'check')
                        {{-- One row per check received; each becomes a check payment of its own. --}}
                        <form wire:submit="recordChecks">
                            @foreach ($checkRows as $i => $row)
                                <div class="check-row" wire:key="check-row-{{ $i }}">
                                    <span class="check-row-n">{{ $i + 1 }}</span>
                                    <div>
                                        <label for="check-{{ $i }}-number">מס' צ'ק</label>
                                        <input type="text" id="check-{{ $i }}-number" wire:model="checkRows.{{ $i }}.number" class="ltr-num" dir="ltr" inputmode="numeric">
                                        @error("checkRows.$i.number") <div class="field-error">{{ $message }}</div> @enderror
                                    </div>
                                    <div>
                                        <label for="check-{{ $i }}-due">תאריך פירעון</label>
                                        <input type="date" id="check-{{ $i }}-due" wire:model="checkRows.{{ $i }}.due_date">
                                        @error("checkRows.$i.due_date") <div class="field-error">{{ $message }}</div> @enderror
                                    </div>
                                    <div>
                                        <label for="check-{{ $i }}-amount">סכום</label>
                                        <input type="text" id="check-{{ $i }}-amount" wire:model="checkRows.{{ $i }}.amount" class="ltr-num" dir="ltr" placeholder="₪">
                                        @error("checkRows.$i.amount") <div class="field-error">{{ $message }}</div> @enderror
                                    </div>
                                    <div>
                                        <label for="check-{{ $i }}-bank">בנק</label>
                                        <select id="check-{{ $i }}-bank" wire:model="checkRows.{{ $i }}.bank">
                                            <option value="">—</option>
                                            @foreach (\App\Models\Payment::BANKS as $code => $bankName)
                                                <option value="{{ $code }}">{{ $bankName }} ({{ str_pad($code, 2, '0', STR_PAD_LEFT) }})</option>
                                            @endforeach
                                        </select>
                                        @error("checkRows.$i.bank") <div class="field-error">{{ $message }}</div> @enderror
                                    </div>
                                    <div>
                                        <label for="check-{{ $i }}-branch">סניף</label>
                                        <input type="text" id="check-{{ $i }}-branch" wire:model="checkRows.{{ $i }}.branch" class="ltr-num" dir="ltr" inputmode="numeric">
                                        @error("checkRows.$i.branch") <div class="field-error">{{ $message }}</div> @enderror
                                    </div>
                                    <div>
                                        <label for="check-{{ $i }}-account">מס' חשבון</label>
                                        <input type="text" id="check-{{ $i }}-account" wire:model="checkRows.{{ $i }}.account" class="ltr-num" dir="ltr" inputmode="numeric">
                                        @error("checkRows.$i.account") <div class="field-error">{{ $message }}</div> @enderror
                                    </div>
                                    <div class="check-row-remove">
                                        @if (count($checkRows) > 1)
                                            <button type="button" wire:click="removeCheckRow({{ $i }})" class="btn btn-ghost btn-sm" aria-label="הסרת צ'ק {{ $i + 1 }}">×</button>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                            <div class="actions" style="margin-top:var(--sp-sm)">
                                <button type="button" wire:click="addCheckRow" class="btn btn-ghost btn-sm">+ צ'ק נוסף</button>
                                <span class="note">השורה החדשה ממשיכה את הקודמת: המספר הבא, חודש אחרי, אותו סכום ואותו חשבון. בנק, סניף וחשבון — לא חובה.</span>
                            </div>
                            <p class="note" style="margin-top:var(--sp-sm)">כל צ'ק נרשם כ"טרם נפרע". ביום הפירעון שלו תופיע במסך הראשי תזכורת עם כפתור להפקת הקבלה.</p>
                            <div class="actions" style="margin-top:var(--sp-md)">
                                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="recordChecks">
                                    רישום {{ count($checkRows) > 1 ? count($checkRows)." הצ'קים" : "הצ'ק" }}
                                </button>
                            </div>
                        </form>
                    @else
                        <form wire:submit="recordPayment" class="form-grid">
                            <div>
                                <label for="paymentAmount">סכום שהתקבל</label>
                                <input type="text" id="paymentAmount" wire:model="paymentAmount" class="ltr-num" dir="ltr" placeholder="₪ {{ number_format($this->outstandingBalance, 0) }}">
                                @error('paymentAmount') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
                            </div>
                            <div>
                                <label for="paymentDate">תאריך קבלה</label>
                                <input type="date" id="paymentDate" wire:model="paymentDate">
                            </div>
                            <p class="full note">
                                @if ($hasInvoice)
                                    הקבלה תופק ותישלח ללקוחה מיד עם הרישום.
                                @else
                                    לעסקה טרם הופקה חשבונית — התשלום יירשם, והקבלה תופק אחרי הפקת החשבונית.
                                @endif
                            </p>
                            <div class="full actions">
                                <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="recordPayment">
                                    {{ $hasInvoice ? 'רישום תשלום והפקת קבלה' : 'רישום תשלום' }}
                                </button>
                            </div>
                        </form>
                    @endif
                </div>
            @else
                <p class="pay-note" style="margin-bottom:var(--sp-lg)">בחרו אמצעי תשלום כדי להמשיך.</p>
            @endif
        @endif

        @if ($this->paymentLinks->isNotEmpty())
            <h4 style="margin:0 0 var(--sp-sm)">קישורים שנשלחו</h4>
            <div class="table-scroll" style="margin-bottom:var(--sp-lg)">
            <table>
                <thead><tr><th>נוצר</th><th>סוג</th><th>סכום</th><th>נשלח אל</th><th>סטטוס</th><th>בתוקף עד</th><th></th></tr></thead>
                <tbody>
                    @foreach ($this->paymentLinks as $link)
                        <tr wire:key="paylink-{{ $link->id }}">
                            <td class="ltr-num">{{ $link->created_at->format('d/m/Y H:i') }}</td>
                            <td>{{ $link->isStandingOrder() ? 'הקמת הוראת קבע' : 'תשלום' }}</td>
                            <td class="ltr-num">₪{{ number_format((float) $link->amount, 0) }}</td>
                            <td class="ltr-num">{{ $link->sent_to_email ?? '—' }}</td>
                            <td><span class="badge {{ $link->isPaid() ? 'badge-success' : ($link->isExpired() || $link->isCancelled() ? 'badge-neutral' : 'badge-warning') }}">{{ $link->statusLabel() }}</span></td>
                            <td class="ltr-num">{{ $link->expires_at?->format('d/m/Y') }}</td>
                            <td style="white-space:nowrap">
                                @unless ($link->isPaid() || $link->isExpired() || $link->isCancelled())
                                    <button type="button" class="btn btn-ghost btn-sm" x-data x-on:click="navigator.clipboard.writeText(@js($link->url)); $el.textContent = 'הועתק'">העתקת קישור</button>
                                    <button type="button" class="btn btn-ghost btn-sm" wire:click="resendPaymentLink({{ $link->id }})">שליחה חוזרת</button>
                                @endunless
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        @endif

        <h4 style="margin:0 0 var(--sp-sm)">תשלומים שהתקבלו</h4>
        @if ($this->payments->isEmpty())
            <div class="empty-state">טרם נרשמו תשלומים לעסקה זו.</div>
        @else
            <div class="table-scroll">
            <table>
                <thead><tr><th>תאריך</th><th>סכום</th><th>אמצעי תשלום</th><th>סטטוס</th><th>קבלה</th><th></th></tr></thead>
                <tbody>
                    @foreach ($this->payments as $payment)
                        @if ($editingPaymentId === $payment->id)
                            <tr wire:key="payment-edit-{{ $payment->id }}">
                                <td colspan="6">
                                    <form wire:submit="updatePayment" class="form-grid">
                                        <div><label>תאריך</label><input type="date" wire:model="editPaymentDate">@error('editPaymentDate') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror</div>
                                        <div><label>סכום</label><input type="text" wire:model="editPaymentAmount" class="ltr-num" dir="ltr">@error('editPaymentAmount') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror</div>
                                        @if ($payment->isCheck())
                                            <div><label>מספר צ'ק</label><input type="text" wire:model="editCheckNumber" class="ltr-num" dir="ltr"></div>
                                            <div><label>תאריך פירעון</label><input type="date" wire:model="editCheckDueDate">@error('editCheckDueDate') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror</div>
                                            <div>
                                                <label>בנק</label>
                                                <select wire:model="editCheckBank">
                                                    <option value="">—</option>
                                                    @foreach (\App\Models\Payment::BANKS as $code => $bankName)
                                                        <option value="{{ $code }}">{{ $bankName }} ({{ $code }})</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <div><label>סניף</label><input type="text" wire:model="editCheckBranch" class="ltr-num" dir="ltr"></div>
                                            <div><label>חשבון</label><input type="text" wire:model="editCheckAccount" class="ltr-num" dir="ltr"></div>
                                        @endif
                                        <div class="full" style="display:flex; gap:var(--sp-sm)">
                                            <button type="submit" class="btn btn-primary btn-sm">שמירה</button>
                                            <button type="button" class="btn btn-ghost btn-sm" wire:click="cancelPaymentEdit">ביטול</button>
                                        </div>
                                    </form>
                                </td>
                            </tr>
                            @continue
                        @endif
                        <tr wire:key="payment-{{ $payment->id }}">
                            <td class="ltr-num">{{ $payment->payment_date?->format('d/m/Y') }}</td>
                            <td class="ltr-num">₪{{ number_format((float) $payment->amount, 0) }}</td>
                            {{-- A check's number sits under its method; bank details are in the edit form (and the tooltip). --}}
                            <td @if ($payment->checkBankLabel()) title="{{ $payment->checkBankLabel() }}" @endif>
                                {{ $payment->paymentMethod?->name }}
                                @if ($payment->check_number)
                                    <div style="font-size:var(--fs-caption); color:var(--color-text-secondary)">מס' <span class="ltr-num">{{ $payment->check_number }}</span></div>
                                @endif
                            </td>
                            <td style="white-space:nowrap">
                                @if (! $payment->check_status)
                                    —
                                @elseif ($payment->cleared_date)
                                    <span class="badge badge-success">נפרע <span class="ltr-num">{{ $payment->cleared_date->format('d/m/Y') }}</span></span>
                                @else
                                    <span class="badge {{ $payment->isCheckDue() ? 'badge-error' : 'badge-warning' }}">
                                        {{ $payment->isCheckDue() ? 'הגיע מועד הפירעון' : 'לפירעון' }}
                                        @if ($payment->check_due_date)<span class="ltr-num">{{ $payment->check_due_date->format('d/m/Y') }}</span>@endif
                                    </span>
                                    <button type="button" wire:click="markCheckCleared({{ $payment->id }})" wire:confirm="לסמן את הצ'ק כנפרע ולהפיק קבלה?" class="btn btn-ghost btn-sm">סימון כנפרע</button>
                                @endif
                            </td>
                            <td>
                                @if ($payment->receipt || $payment->sumit_document_id)
                                    <span class="badge badge-info">הופקה קבלה</span>
                                @elseif ($this->beforePaymentReceipt)
                                    <span class="text-text-secondary" style="font-size:var(--fs-caption)">בקבלה לפני תשלום</span>
                                @elseif ($payment->check_status && ! $payment->cleared_date)
                                    <span class="text-text-secondary" style="font-size:var(--fs-caption)">אחרי פירעון</span>
                                @else
                                    <button type="button" wire:click="issueReceipt({{ $payment->id }})" class="btn btn-ghost btn-sm" @disabled(! $hasInvoice)>הפקת קבלה</button>
                                @endif
                            </td>
                            <td style="white-space:nowrap">
                                @if ($payment->isEditable())
                                    <button type="button" wire:click="editPayment({{ $payment->id }})" class="btn btn-ghost btn-sm">עריכה</button>
                                    <button type="button" wire:click="deletePayment({{ $payment->id }})" wire:confirm="למחוק את התשלום? (מחיקה לוגית)" class="btn btn-ghost btn-sm" style="color:var(--color-error)">מחיקה</button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        @endif

        {{-- FR-4.24/FR-4.25 — the exception, kept out of the main flow. --}}
        <div class="pay-secondary">
            @if ($this->beforePaymentReceipt)
                <span class="badge badge-info">הופקה קבלה לפני תשלום <span class="ltr-num">{{ $this->beforePaymentReceipt->issued_at?->format('d/m/Y') }}</span></span>
                <span class="badge {{ $this->outstandingBalance > 0 ? 'badge-warning' : 'badge-success' }}">{{ $this->outstandingBalance > 0 ? 'ממתינה לתשלום' : 'התשלום התקבל' }}</span>
            @else
                <button type="button" wire:click="issueReceipt(null)" wire:confirm="קבלה לפני תשלום מופקת על מלוא סכום החשבונית ומכסה את כל תשלומי העסקה — להמשיך?" class="btn btn-ghost btn-sm" @disabled(! $hasInvoice || $this->payments->contains(fn ($p) => $p->receipt))>הפקת קבלה לפני תשלום</button>
                <span class="text-text-secondary" style="font-size:var(--fs-caption)">למקרים חריגים בלבד — קבלה על מלוא החשבונית לפני שהכסף התקבל.</span>
            @endif
        </div>
    </div>
</div>
