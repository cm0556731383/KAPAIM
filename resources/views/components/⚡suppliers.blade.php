<?php

use App\Concerns\Notifies;
use App\Models\Supplier;
use App\Services\ActivityLogger;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Build-plan 11 — מסך ספקים (US-017, FR-6.14/FR-6.15). Mirrors
 * ⚡programs-catalog.blade.php's list+form CRUD shape. No delete/disable
 * route — see the suppliers-table migration's docblock — "עריכה" loads a
 * row back into the same form for an in-place update instead.
 */
new
#[Layout('layouts.app', ['title' => 'ספקים — כפיים'])]
class extends Component
{
    use Notifies;

    /** Set while editing an existing supplier; null while creating a new one. */
    public ?int $editingSupplierId = null;

    public string $name = '';
    public string $companyNumber = '';
    public string $classification = 'עוסק פטור';
    public string $phone = '';
    public string $email = '';
    public string $notes = '';

    public function mount(): void
    {
        abort_unless(auth()->user()->can('suppliers.manage'), 403);
    }

    public function saveSupplier(ActivityLogger $activityLogger): void
    {
        $data = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'companyNumber' => ['nullable', 'string', 'max:255'],
            'classification' => ['required', 'in:'.implode(',', Supplier::CLASSIFICATIONS)],
            'phone' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'notes' => ['nullable', 'string'],
        ], [], ['name' => 'שם הספק', 'companyNumber' => 'מספר חברה']);

        $attributes = [
            'name' => $data['name'],
            'company_number' => $data['companyNumber'] ?: null,
            'classification' => $data['classification'],
            'phone' => $data['phone'] ?: null,
            'email' => $data['email'] ?: null,
            'notes' => $data['notes'] ?: null,
        ];

        if ($this->editingSupplierId) {
            $supplier = Supplier::findOrFail($this->editingSupplierId);
            $supplier->update($attributes);

            $activityLogger->log('supplier.updated', "עודכן ספק: {$supplier->name}", ['metadata' => ['supplier_id' => $supplier->id]]);

            $this->notifySuccess("הספק \"{$supplier->name}\" עודכן בהצלחה.");
        } else {
            $supplier = Supplier::create($attributes);

            // FR-5.26/FR-6.14-15 — see Supplier::joinSuppliersMailingList().
            $supplier->joinSuppliersMailingList();

            $activityLogger->log('supplier.created', "נוצר ספק חדש: {$supplier->name}", ['metadata' => ['supplier_id' => $supplier->id]]);

            $this->notifySuccess("הספק \"{$supplier->name}\" נוצר בהצלחה.");
        }

        $this->cancelEdit();
        unset($this->suppliers);
    }

    public function editSupplier(int $id): void
    {
        $supplier = Supplier::findOrFail($id);

        $this->editingSupplierId = $supplier->id;
        $this->name = $supplier->name;
        $this->companyNumber = (string) $supplier->company_number;
        $this->classification = $supplier->classification;
        $this->phone = (string) $supplier->phone;
        $this->email = (string) $supplier->email;
        $this->notes = (string) $supplier->notes;
    }

    public function cancelEdit(): void
    {
        $this->reset(['editingSupplierId', 'name', 'companyNumber', 'phone', 'email', 'notes']);
        $this->classification = 'עוסק פטור';
        $this->resetErrorBag();
    }

    #[Computed]
    public function suppliers()
    {
        return Supplier::orderBy('name')->get();
    }

    #[Computed]
    public function classifications(): array
    {
        return Supplier::CLASSIFICATIONS;
    }
};
?>

<div>
    <div class="topbar">
        <div>
            <h1 style="margin-bottom:2px">ספקים</h1>
            <p class="text-text-secondary m-0">כלל הספקים המשמשים לייצור ואספקת התוכניות</p>
        </div>
    </div>

    <div class="tabs">
        <a href="{{ route('suppliers') }}" class="active">ספקים</a>
        <a href="{{ route('expenses') }}">הוצאות</a>
        <a href="{{ route('cashflow-report') }}">דוח הכנסות ורווח</a>
    </div>

    <div class="mb-8" style="display:flex; align-items:center; gap:10px; background: var(--color-primary-lighter); color: var(--color-primary-hover); border-radius: var(--radius-control); padding: var(--sp-sm) var(--sp-md); font-size: var(--fs-small); font-weight:500;">
        פתיחת ספק חדש מצרפת אותו אוטומטית לרשימת התפוצה "ספקים" — אין צורך בשיוך ידני.
    </div>

    <div class="card" style="padding:0; overflow:hidden; margin-bottom: var(--sp-xl)">
        <table>
            <thead>
                <tr>
                    <th>שם</th>
                    <th>מס' חברה</th>
                    <th>סיווג</th>
                    <th>טלפון</th>
                    <th>דוא"ל</th>
                    <th>הערות</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->suppliers as $supplier)
                    <tr>
                        <td>{{ $supplier->name }}</td>
                        <td class="ltr-num">{{ $supplier->company_number ?? '—' }}</td>
                        <td><span class="badge badge-info">{{ $supplier->classification }}</span></td>
                        <td class="ltr-num">{{ $supplier->phone ?? '—' }}</td>
                        <td>{{ $supplier->email ?? '—' }}</td>
                        <td style="color:var(--color-text-secondary)">{{ $supplier->notes ?: '—' }}</td>
                        <td><button type="button" wire:click="editSupplier({{ $supplier->id }})" class="btn btn-ghost">עריכה</button></td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="empty-state">עדיין לא נוספו ספקים.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="card" style="max-width:640px">
        <h3>{{ $editingSupplierId ? 'עריכת ספק' : '+ ספק חדש' }}</h3>
        <form wire:submit="saveSupplier" class="form-grid">
            <div class="full">
                <label for="name">שם הספק</label>
                <input type="text" id="name" wire:model="name" placeholder="למשל: דפוס הראל בע&quot;מ">
                @error('name') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
            </div>
            <div>
                <label for="companyNumber">מספר חברה</label>
                <input type="text" id="companyNumber" wire:model="companyNumber" class="ltr-num" dir="ltr" placeholder="514392201">
                @error('companyNumber') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
            </div>
            <div>
                <label for="classification">סיווג עסקי</label>
                <select id="classification" wire:model="classification">
                    @foreach ($this->classifications as $option)
                        <option value="{{ $option }}">{{ $option }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="phone">טלפון</label>
                <input type="text" id="phone" wire:model="phone" class="ltr-num" dir="ltr" placeholder="03-1234567">
                @error('phone') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
            </div>
            <div class="full">
                <label for="email">דוא"ל</label>
                <input type="email" id="email" wire:model="email" placeholder="supplier@example.com">
                @error('email') <div style="color: var(--color-error); font-size: var(--fs-caption); margin-top: 4px;">{{ $message }}</div> @enderror
            </div>
            <div class="full">
                <label for="notes">הערות</label>
                <textarea id="notes" wire:model="notes" rows="3" placeholder="הערות חופשיות על הספק..."></textarea>
            </div>
            <div class="full" style="display:flex; gap:var(--sp-sm)">
                <button type="submit" class="btn btn-primary">{{ $editingSupplierId ? 'עדכון ספק' : 'שמירת ספק' }}</button>
                @if ($editingSupplierId)
                    <button type="button" wire:click="cancelEdit" class="btn btn-ghost">ביטול</button>
                @endif
            </div>
        </form>
    </div>
</div>
