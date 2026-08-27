<?php

use App\Concerns\Notifies;
use App\Models\BusinessEntity;
use App\Models\Deal;
use App\Models\Document;
use App\Models\DocumentTemplate;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\ExternalOperation;
use App\Models\Receipt;
use App\Models\StatusDefinition;
use App\Services\ActivityLogger;
use App\Services\Integrations\ExternalOperationRunner;
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

    public function mount(Deal $deal): void
    {
        abort_unless(auth()->user()->can('deals.manage'), 403);

        $this->deal = $deal->load(['customer.school', 'program', 'bundle', 'status', 'paymentMethod']);
        $this->syncFields();
    }

    private function syncFields(): void
    {
        $this->loadedVersion = $this->deal->version;
        $this->selectedStatusId = (string) $this->deal->status_id;
        $this->agreedAmount = (string) $this->deal->agreed_amount;
        $this->specialRequest = (string) $this->deal->special_request;
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
        ], [], ['agreedAmount' => 'סכום מוסכם']);

        $this->deal->update([
            'agreed_amount' => $data['agreedAmount'],
            'special_request' => $data['specialRequest'] ?: null,
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
    public function generateDocument(string $documentType): void
    {
        $this->documentError = null;

        $template = DocumentTemplate::where('document_type', $documentType)->where('is_active', true)->orderBy('id')->first();

        if (! $template) {
            $this->documentError = 'לא נמצאה תבנית פעילה עבור סוג מסמך זה — יש להגדיר תבנית תחילה במסך תבניות מסמכים.';

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

            return;
        }

        unset($this->documents);

        $this->redirect(route('document-view', $document), navigate: false);
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
    public function recordPayment(ActivityLogger $activityLogger): void
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

        $this->notifySuccess("התשלום נרשם בהצלחה (₪{$data['paymentAmount']}).");
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

        $this->notifySuccess("הסליקה הושלמה והתשלום נרשם בהצלחה (₪{$data['paymentAmount']}).");
    }

    /** Build-plan 12 — Deal::registerStandingOrderWithSummit(). */
    public function registerStandingOrder(ExternalOperationRunner $runner, SummitClient $summit): void
    {
        $this->paymentError = null;

        try {
            $this->deal->registerStandingOrderWithSummit($runner, $summit);
        } catch (\RuntimeException $e) {
            $this->paymentError = $e->getMessage();

            return;
        }

        $this->notifySuccess('הוראת הקבע נרשמה מול Summit בהצלחה.');
    }

    /** FR-4.28: a separate explicit action from "received" — never inferred. */
    public function markCheckCleared(int $paymentId, ActivityLogger $activityLogger): void
    {
        $this->paymentError = null;

        $payment = Payment::findOrFail($paymentId);

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
    }

    /**
     * FR-4.5/FR-4.23-FR-4.29: $paymentId null issues "קבלה לפני תשלום"
     * (FR-4.24/FR-4.25) — Receipt::issueFor() is the only place a receipt is
     * ever created and re-checks every gate server-side.
     */
    public function issueReceipt(?int $paymentId, ActivityLogger $activityLogger, ExternalOperationRunner $runner, SummitClient $summit): void
    {
        $this->paymentError = null;

        $payment = $paymentId ? Payment::findOrFail($paymentId) : null;

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

        unset($this->payments);

        $summitFailed = ExternalOperation::where('document_id', $receipt->document_id)
            ->where('operation_type', 'issue_receipt')
            ->where('status', ExternalOperation::STATUS_FAILED)
            ->latest('id')
            ->exists();

        if ($summitFailed) {
            $this->notifyWarning('הקבלה נרשמה במערכת, אך ההפקה מול Summit נכשלה — ראו יומן פעילות (FR-8.16).');

            return;
        }

        $this->notifySuccess('הקבלה הופקה בהצלחה.');
    }

    #[Computed]
    public function payments()
    {
        return $this->deal->payments()->with(['paymentMethod', 'status', 'receipt'])->orderByDesc('id')->get();
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
            <h1>עסקה #{{ $deal->id }} — {{ $deal->program_name_snapshot ?? $deal->bundle_name_snapshot }}</h1>
            <p style="color:var(--color-text-secondary); margin:0">
                לקוחה: {{ $deal->customer->school?->name ?? 'לקוחה #'.$deal->customer_id }}
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

    <x-business-error-banner :message="$statusError" />
    <x-business-error-banner :message="$detailsError" />

    <div class="cols2">
        <div>
            {{-- ===== פרטי המוצר הנמכר (Snapshot) ===== --}}
            <div class="card" style="margin-bottom:var(--sp-lg)">
                <h3>פרטי המוצר בעת המכירה</h3>
                <p class="text-text-secondary" style="font-size:var(--fs-caption); margin-top:-8px">
                    שם ומחיר קפואים למועד יצירת העסקה — ממשיכים להופיע כאן גם אם התוכנית/המארז החי שונה או הושבת בהמשך (FR-3.6).
                </p>
                @if ($deal->program_id)
                    <div class="field"><div class="k">תוכנית</div><div class="v">{{ $deal->program_name_snapshot }}</div></div>
                    <div class="field"><div class="k">מחיר תוכנית (בעת המכירה)</div><div class="v ltr-num">₪{{ number_format((float) $deal->program_price_snapshot, 0) }}</div></div>
                @else
                    <div class="field"><div class="k">מארז</div><div class="v">{{ $deal->bundle_name_snapshot }}</div></div>
                    <div class="field"><div class="k">מחיר מארז (בעת המכירה)</div><div class="v ltr-num">₪{{ number_format((float) $deal->bundle_price_snapshot, 0) }}</div></div>
                @endif
            </div>

            {{-- ===== פרטי עסקה: סכום, בקשה מיוחדת, אמצעי תשלום ===== --}}
            <div class="card">
                <h3>פרטי עסקה</h3>
                <form wire:submit="saveDetails" class="form-grid">
                    <div>
                        <label for="agreedAmount">סכום מוסכם</label>
                        <input type="text" id="agreedAmount" wire:model="agreedAmount" class="ltr-num" dir="ltr">
                        @error('agreedAmount') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
                    </div>
                    <div>
                        <label for="paymentMethodId">אמצעי תשלום</label>
                        <select id="paymentMethodId" wire:model="paymentMethodId" @disabled($this->payments->isNotEmpty())>
                            <option value="">— ללא —</option>
                            @foreach ($this->paymentMethods as $method)
                                <option value="{{ $method->id }}">{{ $method->name }}</option>
                            @endforeach
                        </select>
                        <p class="text-text-secondary" style="font-size:var(--fs-caption); margin-top:4px">ניתן לשנות רק כל עוד לא התקבל תשלום בפועל עבור העסקה (FR-4.30/FR-4.31).</p>
                    </div>
                    <div class="full">
                        <label for="specialRequest">בקשת התאמה מיוחדת (FR-3.7)</label>
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
                <p class="text-text-secondary" style="font-size:var(--fs-caption); margin-top:var(--sp-md)">
                    ביטול עסקה הוא שינוי סטטוס בלבד — עסקה לעולם אינה נמחקת (FR-3.5). עדכון סטטוס במקביל משתי משתמשות אינו דורס בשקט (FR-8.19).
                </p>
                @if ($deal->completed_at)
                    <div class="field" style="margin-top:var(--sp-md)"><div class="k">הושלמה בתאריך</div><div class="v ltr-num">{{ $deal->completed_at->format('d/m/Y H:i') }}</div></div>
                @endif
            </div>
        </div>
    </div>

    {{-- ===== מסמכים (DOCUMENT) — build-plan 07 ===== --}}
    <div class="card" style="margin-top:var(--sp-lg)">
        <h3>מסמכים</h3>
        <p class="text-text-secondary" style="font-size:var(--fs-caption); margin-top:-8px">
            רצף עסקי מחייב: הצעת מחיר (אופציונלי) ← טופס הזמנה ← חוזה ← חשבונית. לא ניתן לדלג על שלב (FR-4.1, FR-4.3, FR-4.4).
        </p>

        <x-business-error-banner :message="$documentError" />

        <div class="doc-stepper">
            @foreach (\App\Models\Document::TYPE_LABELS as $type => $label)
                @php $existing = $this->documents->where('document_type', $type); @endphp
                <span class="doc-step {{ $existing->isNotEmpty() ? 'done' : '' }}">{{ $label }} ({{ $existing->count() }})</span>
                @if (! $loop->last)<span class="doc-step-arrow">←</span>@endif
            @endforeach
        </div>

        <div style="display:flex; gap:var(--sp-sm); flex-wrap:wrap; margin-bottom:var(--sp-lg); align-items:flex-end">
            @foreach (\App\Models\Document::TYPE_LABELS as $type => $label)
                @if ($type === 'invoice')
                    <div style="display:flex; gap:6px; align-items:flex-end">
                        <div>
                            <label for="invoiceBusinessEntityId" style="margin-bottom:4px">עוסק פטור (FR-4.15/FR-4.16)</label>
                            <select id="invoiceBusinessEntityId" wire:model="invoiceBusinessEntityId" style="min-width:220px">
                                <option value="">בחרו עוסק</option>
                                @foreach ($this->activeBusinessEntities as $entity)
                                    <option value="{{ $entity->id }}">{{ $entity->name }} — {{ $entity->classification }}</option>
                                @endforeach
                            </select>
                        </div>
                        <button type="button" wire:click="generateDocument('invoice')" class="btn btn-primary" @disabled(! $this->canGenerateDocument('invoice'))>הפקת חשבונית</button>
                    </div>
                @else
                    <button type="button" wire:click="generateDocument('{{ $type }}')" class="btn btn-secondary" @disabled(! $this->canGenerateDocument($type))>+ הפקת {{ $label }}</button>
                @endif
            @endforeach
        </div>

        @if ($this->documents->isEmpty())
            <div class="empty-state">טרם הופקו מסמכים לעסקה זו.</div>
        @else
            <table>
                <thead><tr><th>סוג</th><th>סטטוס</th><th>נשלח</th><th>התקבל</th><th>נחתם</th><th></th></tr></thead>
                <tbody>
                    @foreach ($this->documents as $document)
                        <tr>
                            <td>{{ \App\Models\Document::TYPE_LABELS[$document->document_type] ?? $document->document_type }}</td>
                            <td><span class="badge badge-neutral">{{ $document->status?->name }}</span></td>
                            <td class="ltr-num">{{ $document->sent_at?->format('d/m/Y') ?? '—' }}</td>
                            <td class="ltr-num">{{ $document->received_at?->format('d/m/Y') ?? '—' }}</td>
                            <td class="ltr-num">{{ $document->signed_at?->format('d/m/Y') ?? '—' }}</td>
                            <td><a href="{{ route('document-view', $document) }}" class="btn btn-ghost btn-sm">פתיחת מסמך</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    {{-- ===== תשלומים וקבלות (PAYMENT/RECEIPT) — build-plan 08 ===== --}}
    <div class="card" style="margin-top:var(--sp-lg)">
        <h3>תשלומים</h3>

        <x-business-error-banner :message="$paymentError" />

        <div class="cols2" style="margin-bottom:var(--sp-lg)">
            <div class="field"><div class="k">סכום עסקה</div><div class="v ltr-num">₪{{ number_format((float) $deal->agreed_amount, 0) }}</div></div>
            <div class="field"><div class="k">שולם עד כה</div><div class="v ltr-num">₪{{ number_format($this->totalPaid, 0) }}</div></div>
        </div>
        <div class="field" style="margin-bottom:var(--sp-lg)">
            <div class="k">יתרת חוב</div>
            <div class="v ltr-num" style="{{ $this->outstandingBalance > 0 ? 'color:var(--color-error)' : 'color:var(--color-success)' }}">₪{{ number_format($this->outstandingBalance, 0) }}</div>
        </div>

        @if ($deal->paymentMethod?->type === 'card')
            <form wire:submit="chargeCard" class="form-grid" style="margin-bottom:var(--sp-lg)">
                <div>
                    <label for="paymentAmount">סכום לסליקה</label>
                    <input type="text" id="paymentAmount" wire:model="paymentAmount" class="ltr-num" dir="ltr" placeholder="₪">
                    @error('paymentAmount') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
                </div>
                <div class="full"><button type="submit" class="btn btn-primary">סליקת אשראי מול Summit</button></div>
                <p class="full text-text-secondary" style="font-size:var(--fs-caption); margin:0">הסכום נסלק בפועל מול Summit, ורק לאחר אישור הסליקה נרשם תשלום מקומי (PRD §1.4).</p>
            </form>
        @else
            <form wire:submit="recordPayment" class="form-grid" style="margin-bottom:var(--sp-lg)">
                <div>
                    <label for="paymentAmount">סכום שהתקבל</label>
                    <input type="text" id="paymentAmount" wire:model="paymentAmount" class="ltr-num" dir="ltr" placeholder="₪">
                    @error('paymentAmount') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
                </div>
                <div>
                    <label for="paymentDate">תאריך קבלה (ברירת מחדל: היום)</label>
                    <input type="date" id="paymentDate" wire:model="paymentDate">
                </div>
                <div class="full"><button type="submit" class="btn btn-primary" @disabled(! $deal->payment_method_id)>רישום תשלום</button></div>
                @unless ($deal->payment_method_id)
                    <p class="full text-text-secondary" style="font-size:var(--fs-caption); margin:0">יש לבחור אמצעי תשלום בפרטי העסקה לפני רישום תשלום (FR-4.30).</p>
                @endunless
            </form>
        @endif

        @if ($deal->paymentMethod?->type === 'recurring')
            <div style="display:flex; gap:var(--sp-sm); align-items:center; margin-bottom:var(--sp-lg)">
                <button type="button" wire:click="registerStandingOrder" class="btn btn-secondary">רישום הוראת קבע מול Summit</button>
                <p class="text-text-secondary" style="font-size:var(--fs-caption); margin:0">גבייה חודשית וקבלה אוטומטית לאחריה (FR-4.27) יתבצעו מרגע זה מול Summit.</p>
            </div>
        @endif

        <div style="display:flex; gap:var(--sp-sm); flex-wrap:wrap; margin-bottom:var(--sp-lg)">
            <button type="button" wire:click="issueReceipt(null)" class="btn btn-secondary" @disabled($this->documents->where('document_type', 'invoice')->isEmpty())>הפקת קבלה לפני תשלום (FR-4.24/FR-4.25)</button>
        </div>

        @if ($this->payments->isEmpty())
            <div class="empty-state">טרם נרשמו תשלומים לעסקה זו.</div>
        @else
            <table>
                <thead><tr><th>תאריך</th><th>סכום</th><th>אמצעי תשלום</th><th>סטטוס צ'ק</th><th>קבלה</th><th></th></tr></thead>
                <tbody>
                    @foreach ($this->payments as $payment)
                        <tr>
                            <td class="ltr-num">{{ $payment->payment_date?->format('d/m/Y') }}</td>
                            <td class="ltr-num">₪{{ number_format((float) $payment->amount, 0) }}</td>
                            <td>{{ $payment->paymentMethod?->name }}</td>
                            <td>
                                @if ($payment->check_status)
                                    @if ($payment->cleared_date)
                                        <span class="badge badge-success">נפרע <span class="ltr-num">{{ $payment->cleared_date->format('d/m/Y') }}</span></span>
                                    @else
                                        <span class="badge badge-warning">טרם נפרע</span>
                                        <button type="button" wire:click="markCheckCleared({{ $payment->id }})" class="btn btn-ghost btn-sm">סימון כנפרע</button>
                                    @endif
                                @else
                                    —
                                @endif
                            </td>
                            <td>
                                @if ($payment->receipt)
                                    <span class="badge badge-info">הופקה קבלה</span>
                                @else
                                    <button type="button" wire:click="issueReceipt({{ $payment->id }})" class="btn btn-ghost btn-sm" @disabled($payment->check_status && ! $payment->cleared_date)>הפקת קבלה</button>
                                @endif
                            </td>
                            <td></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
        <p class="text-text-secondary" style="font-size:var(--fs-caption); margin-top:var(--sp-md)">
            תשלום חלקי אינו סוגר את העסקה — היא מסומנת "שולמה" רק כשמלוא הסכום התקבל (FR-4.32/FR-4.33). קבלה עבור צ'ק מופקת רק לאחר פירעון בפועל, ולא יותר מקבלה אחת לכל תשלום (FR-4.28/FR-4.29).
        </p>
    </div>
</div>
