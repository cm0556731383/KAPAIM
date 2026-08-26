<?php

use App\Models\Expense;
use App\Models\Program;
use App\Models\Supplier;
use App\Services\ActivityLogger;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Build-plan 11 — מסך הוצאות (US-016/US-017, FR-6.5-FR-6.10). Manual expense
 * entry + a per-row "צירוף חשבונית" action that generates an
 * 'expense_invoice' Document via Expense::attachInvoice() — the manual
 * "record the invoice the supplier already sent us" flow, not a
 * digital-form-fill flow like the sales document chain (build-plan 07).
 * No edit/delete route — matches this codebase's project-wide convention.
 */
new
#[Layout('layouts.app', ['title' => 'הוצאות ותזרים — כפיים'])]
class extends Component
{
    public string $supplierId = '';
    public string $programId = '';
    public string $amount = '';
    public string $expenseDate = '';
    public string $notes = '';

    /** Business-rule error from Expense::createForSupplier()/attachInvoice(). */
    public ?string $expenseError = null;

    public function mount(): void
    {
        abort_unless(auth()->user()->can('expenses.manage'), 403);
    }

    public function addExpense(ActivityLogger $activityLogger): void
    {
        $this->expenseError = null;

        $data = $this->validate([
            'supplierId' => ['required', 'exists:suppliers,id'],
            'programId' => ['nullable', 'exists:programs,id'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'expenseDate' => ['required', 'date'],
            'notes' => ['nullable', 'string'],
        ], [], ['supplierId' => 'ספק', 'amount' => 'סכום', 'expenseDate' => 'תאריך']);

        $supplier = Supplier::findOrFail($data['supplierId']);
        $program = $data['programId'] ? Program::find($data['programId']) : null;

        try {
            $expense = Expense::createForSupplier($supplier, (float) $data['amount'], $data['expenseDate'], $program, $data['notes'] ?: null);
        } catch (\RuntimeException $e) {
            $this->expenseError = $e->getMessage();

            return;
        }

        $activityLogger->log('expense.created', "נוצרה הוצאה חדשה בסך ₪{$data['amount']} עבור הספק \"{$supplier->name}\"", [
            'expense_id' => $expense->id,
            'metadata' => ['supplier_id' => $supplier->id, 'amount' => $data['amount']],
        ]);

        $this->reset(['supplierId', 'programId', 'amount', 'expenseDate', 'notes']);
        unset($this->expenses);
    }

    /**
     * FR-6.6/FR-6.10: attaches the sole active 'expense_invoice' template as
     * this expense's invoice document — see Expense::attachInvoice().
     */
    public function attachInvoice(int $expenseId, ActivityLogger $activityLogger): void
    {
        $this->expenseError = null;

        $expense = Expense::findOrFail($expenseId);

        try {
            $document = $expense->attachInvoice();
        } catch (\RuntimeException $e) {
            $this->expenseError = $e->getMessage();

            return;
        }

        $activityLogger->log('expense.invoice_attached', "צורפה חשבונית להוצאה #{$expense->id}", [
            'expense_id' => $expense->id,
            'document_id' => $document->id,
        ]);

        unset($this->expenses);
    }

    #[Computed]
    public function expenses()
    {
        return Expense::with(['supplier', 'program', 'status'])->orderByDesc('expense_date')->orderByDesc('id')->get();
    }

    #[Computed]
    public function suppliers()
    {
        return Supplier::orderBy('name')->get();
    }

    #[Computed]
    public function programs()
    {
        return Program::where('is_active', true)->orderBy('name')->get();
    }
};
?>

<div>
    <div class="topbar">
        <div>
            <h1 style="margin-bottom:2px">הוצאות</h1>
            <p class="text-text-secondary m-0">רישום הוצאות ידני וזיהוי הוצאות שחסרה להן חשבונית (FR-6.5-FR-6.10)</p>
        </div>
    </div>

    <div class="tabs">
        <a href="{{ route('suppliers') }}">ספקים</a>
        <a href="{{ route('expenses') }}" class="active">הוצאות</a>
        <a href="{{ route('cashflow-report') }}">דוח הכנסות ורווח</a>
    </div>

    @if ($expenseError)
        <div class="mb-8" style="background: var(--color-error-bg); color: var(--color-error); border-radius: var(--radius-control); padding: var(--sp-sm) var(--sp-md); font-size: var(--fs-small); font-weight:500;">
            {{ $expenseError }}
        </div>
    @endif

    <div class="card" style="padding:0; overflow:hidden; margin-bottom: var(--sp-xl)">
        <table>
            <thead>
                <tr>
                    <th>תאריך</th>
                    <th>ספק</th>
                    <th>תוכנית</th>
                    <th>סכום</th>
                    <th>חשבונית</th>
                    <th>הערות</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->expenses as $expense)
                    <tr>
                        <td class="ltr-num">{{ $expense->expense_date->format('d/m/Y') }}</td>
                        <td>{{ $expense->supplier?->name }}</td>
                        <td>{{ $expense->program?->name ?? '—' }}</td>
                        <td class="ltr-num">₪{{ number_format((float) $expense->amount, 0) }}</td>
                        <td>
                            @if ($expense->document_id)
                                <span class="badge badge-success">יש חשבונית</span>
                            @else
                                <span class="badge badge-error">חסרה חשבונית</span>
                            @endif
                        </td>
                        <td style="color:var(--color-text-secondary)">{{ $expense->notes ?: '—' }}</td>
                        <td>
                            @if ($expense->document_id)
                                <a href="{{ route('document-view', $expense->document_id) }}" class="btn btn-ghost">צפייה</a>
                            @else
                                <button type="button" wire:click="attachInvoice({{ $expense->id }})" class="btn btn-secondary" style="padding:6px 12px; font-size:var(--fs-caption)">צירוף חשבונית</button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="empty-state">עדיין לא נרשמו הוצאות.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="card" style="max-width:640px">
        <h3>+ הוצאה חדשה</h3>
        <form wire:submit="addExpense" class="form-grid">
            <div>
                <label for="expenseDate">תאריך</label>
                <input type="date" id="expenseDate" wire:model="expenseDate" class="ltr-num" dir="ltr">
                @error('expenseDate') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
            </div>
            <div>
                <label for="amount">סכום</label>
                <input type="text" id="amount" wire:model="amount" class="ltr-num" dir="ltr" placeholder="₪0">
                @error('amount') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
            </div>
            <div>
                <label for="supplierId">ספק</label>
                <select id="supplierId" wire:model="supplierId">
                    <option value="">בחרו ספק</option>
                    @foreach ($this->suppliers as $supplier)
                        <option value="{{ $supplier->id }}">{{ $supplier->name }}</option>
                    @endforeach
                </select>
                @error('supplierId') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
            </div>
            <div>
                <label for="programId">תוכנית (לא חובה)</label>
                <select id="programId" wire:model="programId">
                    <option value="">ללא שיוך לתוכנית</option>
                    @foreach ($this->programs as $program)
                        <option value="{{ $program->id }}">{{ $program->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="full">
                <label for="notes">הערות</label>
                <textarea id="notes" wire:model="notes" rows="2" placeholder="הערות חופשיות..."></textarea>
            </div>
            <div class="full">
                <button type="submit" class="btn btn-primary">שמירת הוצאה</button>
            </div>
        </form>
        <p class="text-text-secondary" style="font-size:var(--fs-caption); margin-top:var(--sp-sm)">
            צירוף חשבונית מתבצע מרשימת ההוצאות למעלה, לאחר שמירת ההוצאה (FR-6.6).
        </p>
    </div>
</div>
