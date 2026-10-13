<?php

use App\Concerns\Notifies;
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
 * Rows are editable inline; one is deletable (logically) only while no
 * invoice is attached — Expense::deleteIfNoInvoice().
 */
new
#[Layout('layouts.app', ['title' => 'הוצאות ותזרים — כפיים'])]
class extends Component
{
    use Notifies;

    public string $supplierId = '';
    public string $programId = '';
    public string $amount = '';
    public string $expenseDate = '';
    public string $notes = '';

    public ?int $editingExpenseId = null;
    public string $editSupplierId = '';
    public string $editProgramId = '';
    public string $editAmount = '';
    public string $editExpenseDate = '';
    public string $editNotes = '';

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

        $this->dispatch('close-modals');
        $this->notifySuccess('ההוצאה נוצרה בהצלחה.');
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

    public function editExpense(int $id): void
    {
        $expense = Expense::findOrFail($id);

        $this->expenseError = null;
        $this->resetErrorBag();
        $this->editingExpenseId = $expense->id;
        $this->editSupplierId = (string) $expense->supplier_id;
        $this->editProgramId = (string) $expense->program_id;
        $this->editAmount = (string) (float) $expense->amount;
        $this->editExpenseDate = $expense->expense_date->format('Y-m-d');
        $this->editNotes = (string) $expense->notes;
    }

    public function cancelExpenseEdit(): void
    {
        $this->reset(['editingExpenseId', 'editSupplierId', 'editProgramId', 'editAmount', 'editExpenseDate', 'editNotes']);
    }

    public function updateExpense(ActivityLogger $activityLogger): void
    {
        $data = $this->validate([
            'editSupplierId' => ['required', 'exists:suppliers,id'],
            'editProgramId' => ['nullable', 'exists:programs,id'],
            'editAmount' => ['required', 'numeric', 'gt:0'],
            'editExpenseDate' => ['required', 'date'],
            'editNotes' => ['nullable', 'string'],
        ], [], ['editSupplierId' => 'ספק', 'editAmount' => 'סכום', 'editExpenseDate' => 'תאריך']);

        $expense = Expense::findOrFail($this->editingExpenseId);
        $expense->update([
            'supplier_id' => $data['editSupplierId'],
            'program_id' => $data['editProgramId'] ?: null,
            'amount' => $data['editAmount'],
            'expense_date' => $data['editExpenseDate'],
            'notes' => $data['editNotes'] ?: null,
        ]);

        $activityLogger->log('expense.updated', "עודכנה הוצאה #{$expense->id} (₪{$data['editAmount']})", ['expense_id' => $expense->id]);

        $this->cancelExpenseEdit();
        unset($this->expenses);
        $this->notifySuccess('ההוצאה עודכנה.');
    }

    public function deleteExpense(int $id, ActivityLogger $activityLogger): void
    {
        $this->expenseError = null;
        $expense = Expense::findOrFail($id);

        try {
            $expense->deleteIfNoInvoice();
        } catch (\RuntimeException $e) {
            $this->expenseError = $e->getMessage();

            return;
        }

        $activityLogger->log('expense.deleted', "נמחקה הוצאה #{$expense->id} בסך ₪".number_format((float) $expense->amount, 0)." (מחיקה לוגית)", ['expense_id' => $expense->id]);

        $this->cancelExpenseEdit();
        unset($this->expenses);
        $this->notifySuccess('ההוצאה נמחקה.');
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
        </div>
        <x-modal trigger-label="+ הוצאה חדשה" title="הוצאה חדשה">
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
        </x-modal>
    </div>

    <div class="tabs">
        <a href="{{ route('suppliers') }}">ספקים</a>
        <a href="{{ route('expenses') }}" class="active">הוצאות</a>
        <a href="{{ route('cashflow-report') }}">דוח הכנסות ורווח</a>
    </div>


    <div class="card" style="padding:0; overflow:hidden; margin-bottom: var(--sp-xl)">
        <div class="table-scroll">
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
                    @if ($editingExpenseId === $expense->id)
                        <tr>
                            <td colspan="7">
                                <form wire:submit="updateExpense" class="form-grid">
                                    <div><label>תאריך</label><input type="date" wire:model="editExpenseDate" class="ltr-num" dir="ltr">@error('editExpenseDate') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror</div>
                                    <div><label>סכום</label><input type="text" wire:model="editAmount" class="ltr-num" dir="ltr">@error('editAmount') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror</div>
                                    <div>
                                        <label>ספק</label>
                                        <select wire:model="editSupplierId">
                                            @foreach ($this->suppliers as $supplier)
                                                <option value="{{ $supplier->id }}">{{ $supplier->name }}</option>
                                            @endforeach
                                        </select>
                                        @error('editSupplierId') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
                                    </div>
                                    <div>
                                        <label>תוכנית</label>
                                        <select wire:model="editProgramId">
                                            <option value="">ללא</option>
                                            @foreach ($this->programs as $program)
                                                <option value="{{ $program->id }}">{{ $program->name }}</option>
                                            @endforeach
                                            @if ($expense->program && ! $expense->program->is_active)
                                                <option value="{{ $expense->program->id }}">{{ $expense->program->name }} (מושבתת)</option>
                                            @endif
                                        </select>
                                    </div>
                                    <div class="full"><label>הערות</label><textarea wire:model="editNotes" rows="2"></textarea></div>
                                    <div class="full" style="display:flex; gap:var(--sp-sm)">
                                        <button type="submit" class="btn btn-primary btn-sm">שמירה</button>
                                        <button type="button" class="btn btn-ghost btn-sm" wire:click="cancelExpenseEdit">ביטול</button>
                                    </div>
                                </form>
                            </td>
                        </tr>
                        @continue
                    @endif
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
                            <div style="display:flex; gap:4px; flex-wrap:wrap">
                                @if ($expense->document_id)
                                    <a href="{{ route('document-view', $expense->document_id) }}" class="btn btn-ghost">צפייה</a>
                                @else
                                    <button type="button" wire:click="attachInvoice({{ $expense->id }})" class="btn btn-secondary" style="padding:6px 12px; font-size:var(--fs-caption)">צירוף חשבונית</button>
                                @endif
                                <button type="button" wire:click="editExpense({{ $expense->id }})" class="btn btn-ghost">עריכה</button>
                                @unless ($expense->document_id)
                                    <button type="button" wire:click="deleteExpense({{ $expense->id }})" wire:confirm="למחוק את ההוצאה? (מחיקה לוגית)" class="btn btn-ghost" style="color:var(--color-error)">מחיקה</button>
                                @endunless
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="empty-state">עדיין לא נרשמו הוצאות.</td></tr>
                @endforelse
            </tbody>
        </table>
        </div>
    </div>

</div>
