<?php

use App\Models\Deal;
use App\Models\PaymentMethod;
use App\Models\StatusDefinition;
use App\Services\ActivityLogger;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Build-plan 06 — מסך עסקה. A Deal only ever exists via
 * Deal::createForCustomer() (called from ⚡customer-detail.blade.php's
 * "עסקאות" tab) — this component never creates one, only displays it and
 * drives its status workflow (FR-8.19: optimistic locking on `version`).
 */
new
#[Layout('layouts.app', ['title' => 'כרטיס עסקה — כפיים'])]
class extends Component
{
    public Deal $deal;

    /** The `version` this component loaded the deal with — see Deal::updateStatusWithLock(). */
    public int $loadedVersion;

    public string $selectedStatusId = '';

    public string $agreedAmount = '';

    public string $specialRequest = '';

    public string $paymentMethodId = '';

    /** FR-8.19 conflict error — a friendly banner, not a silent overwrite. */
    public ?string $statusError = null;

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
     * Editing amount/payment-method/special-request is a plain direct
     * update (this stage's optimistic-locking slice covers status changes
     * only — FR-8.19).
     */
    public function saveDetails(ActivityLogger $activityLogger): void
    {
        $data = $this->validate([
            'agreedAmount' => ['required', 'numeric', 'gt:0'],
            'specialRequest' => ['nullable', 'string'],
            'paymentMethodId' => ['nullable', 'exists:payment_methods,id'],
        ], [], ['agreedAmount' => 'סכום מוסכם']);

        $this->deal->update([
            'agreed_amount' => $data['agreedAmount'],
            'special_request' => $data['specialRequest'] ?: null,
            'payment_method_id' => $data['paymentMethodId'] ?: null,
        ]);

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
};
?>

<div>
    <div class="topbar">
        <div>
            <span class="badge {{ \App\Models\Deal::badgeClassForStatusName($deal->status?->name) }}" style="margin-bottom:8px; display:inline-flex">{{ $deal->status?->name }}</span>
            <h1>עסקה #{{ $deal->id }} — {{ $deal->program_name_snapshot ?? $deal->bundle_name_snapshot }}</h1>
            <p style="color:var(--color-text-secondary); margin:0">
                לקוחה: {{ $deal->customer->school?->name ?? 'לקוחה #'.$deal->customer_id }} ·
                נפתחה <span class="ltr-num">{{ $deal->purchased_at->format('d/m/Y') }}</span>
            </p>
        </div>
        <div style="display:flex; gap:var(--sp-sm)">
            <a href="{{ route('customer-detail', $deal->customer) }}" class="btn btn-ghost">חזרה לכרטיס הלקוחה</a>
        </div>
    </div>

    @if ($statusError)
        <div class="mb-8" style="background: var(--color-error-bg); color: var(--color-error); border-radius: var(--radius-control); padding: var(--sp-sm) var(--sp-md); font-size: var(--fs-small); font-weight:500;">
            {{ $statusError }}
        </div>
    @endif

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
                        <select id="paymentMethodId" wire:model="paymentMethodId">
                            <option value="">— ללא —</option>
                            @foreach ($this->paymentMethods as $method)
                                <option value="{{ $method->id }}">{{ $method->name }}</option>
                            @endforeach
                        </select>
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
                        <select id="selectedStatusId" wire:model="selectedStatusId">
                            @foreach ($this->dealStatuses as $status)
                                <option value="{{ $status->id }}">{{ $status->name }}</option>
                            @endforeach
                        </select>
                        @error('selectedStatusId') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
                    </div>
                    <div class="full"><button type="submit" class="btn btn-primary">עדכון סטטוס</button></div>
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
</div>
