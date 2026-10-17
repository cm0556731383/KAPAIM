<?php

use App\Concerns\Notifies;
use App\Models\Expense;
use App\Models\Program;
use App\Models\Supplier;
use App\Services\ActivityLogger;
use App\Services\Integrations\ExternalOperationRunner;
use App\Services\Integrations\SmoveClient;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Build-plan 11 — מסך הוצאות (US-016/US-017, FR-6.5-FR-6.10). Manual expense
 * entry + a per-row invoice upload (PDF or image) — choosing a file uploads
 * it straight away and records it via Expense::attachInvoice(); the file is
 * then viewed/downloaded through the 'expenses.invoice' route, or deleted
 * (Expense::removeInvoice()) to upload a different one. "ייצוא לאקסל"
 * downloads every expense via the 'expenses.export' route
 * (App\Services\ExpensesSpreadsheet).
 * Rows are editable inline; one is deletable (logically) only while no
 * invoice is attached — Expense::deleteIfNoInvoice().
 */
new
#[Layout('layouts.app', ['title' => 'הוצאות ותזרים — כפיים'])]
class extends Component
{
    use Notifies;
    use WithFileUploads;

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

    /** Business-rule error from Expense::createForSupplier()/deleteIfNoInvoice(). */
    public ?string $expenseError = null;

    /** Invoice files being uploaded, keyed by expense id — one file input per row. */
    public array $invoiceFiles = [];

    public function mount(): void
    {
        abort_unless(auth()->user()->can('expenses.view'), 403);
    }

    public function addExpense(ActivityLogger $activityLogger): void
    {
        $this->expenseError = null;

        $data = $this->validate([
            'supplierId' => ['required', 'exists:suppliers,id'],
            'programId' => ['nullable', 'exists:programs,id'],
            'amount' => ['required', 'numeric', 'gt:0', \App\Support\Money::maxRule()],
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
     * FR-6.6/FR-6.10: a file chosen in a row's invoice input is attached as
     * soon as its upload finishes — see Expense::attachInvoice().
     */
    public function updatedInvoiceFiles($file, $expenseId): void
    {
        $this->expenseError = null;

        $this->validate(
            ["invoiceFiles.{$expenseId}" => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp,heic,heif', 'max:10240']],
            [
                "invoiceFiles.{$expenseId}.mimes" => 'ניתן להעלות חשבונית בקובץ PDF או תמונה (JPG, PNG, WEBP, HEIC) בלבד.',
                "invoiceFiles.{$expenseId}.max" => 'הקובץ גדול מדי — עד 10MB.',
            ],
        );

        $expense = Expense::findOrFail($expenseId);
        $document = $expense->attachInvoice($file);

        app(ActivityLogger::class)->log('expense.invoice_attached', "צורפה חשבונית להוצאה #{$expense->id}", [
            'expense_id' => $expense->id,
            'document_id' => $document->id,
        ]);

        unset($this->invoiceFiles[$expenseId], $this->expenses);
        $this->notifySuccess('החשבונית הועלתה.');
    }

    /**
     * Emails the supplier a request for this expense's missing invoice, with
     * a link to upload it straight into the system — Expense::requestInvoice().
     */
    public function requestInvoice(int $expenseId, ExternalOperationRunner $runner, SmoveClient $smove): void
    {
        $expense = Expense::with(['supplier', 'program', 'document'])->findOrFail($expenseId);

        try {
            $operation = $expense->requestInvoice($runner, $smove);
        } catch (\RuntimeException $e) {
            $this->notifyError($e->getMessage());

            return;
        }

        unset($this->expenses);

        $operation->succeeded()
            ? $this->notifySuccess("בקשה לחשבונית נשלחה לספק ({$expense->supplier->email}).")
            : $this->notifyError('שליחת המייל לספק נכשלה: '.$operation->error_message);
    }

    /**
     * "שליחת בקשות לכל הספקים": one email per supplier for every expense
     * still missing an invoice — Expense::requestAllMissingInvoices().
     */
    public function requestAllMissingInvoices(ExternalOperationRunner $runner, SmoveClient $smove): void
    {
        try {
            $result = Expense::requestAllMissingInvoices($runner, $smove);
        } catch (\RuntimeException $e) {
            $this->notifyError($e->getMessage());

            return;
        }

        unset($this->expenses, $this->missingInvoices);

        if ($result['sent']) {
            $this->notifySuccess('נשלחו בקשות ל-'.count($result['sent']).' ספקים עבור '.$result['expense_count'].' הוצאות.');
        }

        $problems = [];
        if ($result['no_email']) {
            $problems[] = 'לא נשלח לספקים ללא כתובת מייל: '.implode(', ', $result['no_email']).'.';
        }
        foreach ($result['failed'] as $supplier => $error) {
            $problems[] = "השליחה לספק {$supplier} נכשלה: {$error}";
        }
        if ($problems) {
            $this->notifyError(implode(' ', $problems));
        }
    }

    /** Deletes an expense's invoice file so the right one can be uploaded instead — Expense::removeInvoice(). */
    public function removeInvoice(int $expenseId, ActivityLogger $activityLogger): void
    {
        $expense = Expense::findOrFail($expenseId);
        $fileName = $expense->invoiceFileName();
        $expense->removeInvoice();

        $activityLogger->log('expense.invoice_removed', "נמחקה החשבונית \"{$fileName}\" מהוצאה #{$expense->id}", ['expense_id' => $expense->id]);

        unset($this->expenses);
        $this->notifySuccess('החשבונית נמחקה.');
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
            'editAmount' => ['required', 'numeric', 'gt:0', \App\Support\Money::maxRule()],
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
        return Expense::with(['supplier', 'program', 'status', 'document'])->orderByDesc('expense_date')->orderByDesc('id')->get();
    }

    /** Expenses still without an invoice file — for the "send to all suppliers" button. */
    #[Computed]
    public function missingInvoices()
    {
        return Expense::withoutInvoiceFile();
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
        <div style="display:flex; gap:var(--sp-sm); align-items:center">
        @php($missing = $this->missingInvoices)
        @php($missingSuppliers = $missing->filter(fn ($e) => $e->supplier?->email)->pluck('supplier_id')->unique()->count())
        @if ($missingSuppliers)
            <button type="button" wire:click="requestAllMissingInvoices" wire:loading.attr="disabled" wire:target="requestAllMissingInvoices"
                wire:confirm="לשלוח מייל ל-{{ $missingSuppliers }} ספקים עבור {{ $missing->filter(fn ($e) => $e->supplier?->email)->count() }} הוצאות ללא חשבונית? ספק שחסרות לו כמה חשבוניות יקבל מייל אחד עם קישור לכל אחת."
                class="btn btn-secondary">
                <span wire:loading.remove wire:target="requestAllMissingInvoices">בקשת חשבוניות מכל הספקים ({{ $missing->filter(fn ($e) => $e->supplier?->email)->count() }})</span>
                <span wire:loading wire:target="requestAllMissingInvoices">שולח...</span>
            </button>
        @endif
        <a href="{{ route('expenses.export') }}" class="btn btn-secondary">ייצוא לאקסל</a>
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
    </div>

    <div class="tabs">
        @can('suppliers.view')<a href="{{ route('suppliers') }}">ספקים</a>@endcan
        @can('expenses.view')<a href="{{ route('expenses') }}" class="active">הוצאות</a>@endcan
        @can('cashflow-report.view')<a href="{{ route('cashflow-report') }}">דוח הכנסות והוצאות</a>@endcan
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
                            @if ($expense->hasInvoiceFile())
                                <div style="display:flex; gap:4px; flex-wrap:wrap; align-items:center">
                                    <a href="{{ route('expenses.invoice', $expense) }}" target="_blank" class="btn btn-ghost btn-sm" title="{{ $expense->invoiceFileName() }}">צפייה</a>
                                    <a href="{{ route('expenses.invoice', [$expense, 'download' => 1]) }}" class="btn btn-ghost btn-sm">הורדה</a>
                                    <button type="button" wire:click="removeInvoice({{ $expense->id }})" wire:confirm="למחוק את החשבונית? הקובץ יימחק ואפשר יהיה להעלות חשבונית אחרת." class="btn btn-ghost btn-sm" style="color:var(--color-error)">מחיקה</button>
                                </div>
                            @else
                                <div style="display:flex; gap:4px; flex-wrap:wrap; align-items:center">
                                    <label class="btn btn-secondary btn-sm" style="cursor:pointer; margin:0">
                                        העלאת חשבונית
                                        <input type="file" wire:model="invoiceFiles.{{ $expense->id }}" accept="application/pdf,image/*" style="display:none">
                                    </label>
                                    @if ($expense->supplier?->email)
                                        <button type="button" wire:click="requestInvoice({{ $expense->id }})" wire:confirm="לשלוח לספק {{ $expense->supplier->name }} ({{ $expense->supplier->email }}) מייל עם בקשה לחשבונית וקישור להעלאתה?" wire:loading.attr="disabled" wire:target="requestInvoice({{ $expense->id }})" class="btn btn-ghost btn-sm">{{ $expense->invoice_requested_at ? 'שליחה חוזרת לספק' : 'בקשה מהספק במייל' }}</button>
                                    @else
                                        <button type="button" class="btn btn-ghost btn-sm" style="opacity:.5; cursor:not-allowed" disabled title="לספק אין כתובת מייל — אפשר להוסיף אותה בכרטיס הספק">בקשה מהספק במייל</button>
                                    @endif
                                </div>
                                @if ($expense->invoice_requested_at)
                                    <div style="font-size:var(--fs-caption); color:var(--color-text-secondary); margin-top:4px">נשלחה בקשה לספק ב-<span class="ltr-num">{{ $expense->invoice_requested_at->format('d/m/Y') }}</span></div>
                                @endif
                            @endif
                            <div wire:loading wire:target="invoiceFiles.{{ $expense->id }}" style="font-size:var(--fs-caption); color:var(--color-text-secondary); margin-top:4px">מעלה...</div>
                            @error('invoiceFiles.'.$expense->id) <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
                        </td>
                        <td style="color:var(--color-text-secondary)">{{ $expense->notes ?: '—' }}</td>
                        <td>
                            <div style="display:flex; gap:4px; flex-wrap:wrap">
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
