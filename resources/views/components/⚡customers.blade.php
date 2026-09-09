<?php

use App\Models\Customer;
use App\Models\Program;
use App\Models\School;
use App\Models\StatusDefinition;
use App\Models\Subscription;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Build-plan 05: a plain list of converted customers — FR-8.2 means there is
 * no "customer חדשה" form here (unlike ⚡leads.blade.php): the only way a
 * customer row exists is Lead::convertToCustomer() (see ⚡lead-detail.blade.php).
 */
new
#[Layout('layouts.app', ['title' => 'לקוחות — כפיים'])]
class extends Component
{
    // ===== סינון =====
    public string $filterName = '';
    public string $filterCity = '';
    public string $filterProgramId = '';
    public string $filterSubscription = '';
    public string $filterStatusId = '';

    public function mount(): void
    {
        abort_unless(auth()->user()->can('customers.manage'), 403);
    }

    #[Computed]
    public function customers()
    {
        return Customer::query()
            ->with(['school', 'status', 'contacts', 'subscriptions.status'])
            ->when($this->filterName !== '', fn ($q) => $q->whereHas('school', fn ($sq) => $sq->where('name', 'like', '%'.$this->filterName.'%')))
            ->when($this->filterCity !== '', fn ($q) => $q->whereHas('school', fn ($sq) => $sq->where('city', $this->filterCity)))
            ->when($this->filterProgramId !== '', function ($q) {
                $programId = $this->filterProgramId;

                // A program filter also catches an active subscriber whose
                // subscription bundle includes that program — not only a
                // customer who bought it as its own standalone deal.
                $q->where(function ($outer) use ($programId) {
                    $outer->whereHas('deals', fn ($dq) => $dq->where('program_id', $programId))
                        ->orWhereHas('subscriptions', function ($sq) use ($programId) {
                            $sq->whereHas('status', fn ($stq) => $stq->where('name', Subscription::ACTIVE_STATUS_NAME))
                                ->whereHas('deal.bundle.programs', fn ($pq) => $pq->where('programs.id', $programId));
                        });
                });
            })
            ->when($this->filterSubscription === 'yes', fn ($q) => $q->whereHas(
                'subscriptions', fn ($sq) => $sq->whereHas('status', fn ($stq) => $stq->where('name', Subscription::ACTIVE_STATUS_NAME))
            ))
            ->when($this->filterSubscription === 'no', fn ($q) => $q->whereDoesntHave(
                'subscriptions', fn ($sq) => $sq->whereHas('status', fn ($stq) => $stq->where('name', Subscription::ACTIVE_STATUS_NAME))
            ))
            ->when($this->filterStatusId !== '', fn ($q) => $q->where('status_id', $this->filterStatusId))
            ->orderByDesc('converted_at')
            ->get();
    }

    #[Computed]
    public function cities()
    {
        return School::whereNotNull('city')->where('city', '!=', '')
            ->whereHas('customer')
            ->distinct()->orderBy('city')->pluck('city');
    }

    #[Computed]
    public function programs()
    {
        return Program::where('is_active', true)->orderBy('name')->get();
    }

    #[Computed]
    public function statuses()
    {
        return StatusDefinition::where('scope', 'customer')->where('is_active', true)->orderBy('sort_order')->get();
    }
};
?>

<div>
    <div class="topbar">
        <div>
            <h1 class="mb-0.5">לקוחות</h1>
            <p class="text-text-secondary m-0">CUSTOMER — נוצרות אך ורק מהמרת ליד; ניהול כרטיס הלקוחה בכרטיס עצמו</p>
        </div>
    </div>

    {{-- ===== סינון ===== --}}
    <div class="filter-row">
        <div>
            <label for="filterName">שם בית ספר</label>
            <input type="text" id="filterName" wire:model.live.debounce.400ms="filterName" placeholder="חיפוש לפי שם...">
        </div>
        <div>
            <label for="filterCity">עיר</label>
            <select id="filterCity" wire:model.live="filterCity">
                <option value="">כל הערים</option>
                @foreach ($this->cities as $city)
                    <option value="{{ $city }}">{{ $city }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="filterProgramId">תכנית שנרכשה</label>
            <select id="filterProgramId" wire:model.live="filterProgramId">
                <option value="">כל התוכניות</option>
                @foreach ($this->programs as $program)
                    <option value="{{ $program->id }}">{{ $program->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="filterSubscription">מנוי</label>
            <select id="filterSubscription" wire:model.live="filterSubscription">
                <option value="">הכל</option>
                <option value="yes">יש מנוי פעיל</option>
                <option value="no">אין מנוי פעיל</option>
            </select>
        </div>
        <div>
            <label for="filterStatusId">סטטוס</label>
            <select id="filterStatusId" wire:model.live="filterStatusId">
                <option value="">כל הסטטוסים</option>
                @foreach ($this->statuses as $status)
                    <option value="{{ $status->id }}">{{ $status->name }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <div class="card" style="padding:0; overflow:hidden">
        <div class="table-scroll">
        <table>
            <thead>
                <tr>
                    <th>בית ספר</th>
                    <th>איש קשר ראשי</th>
                    <th>סטטוס</th>
                    <th>מנוי</th>
                    <th>לקוחה מאז</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->customers as $customer)
                    @php
                        $primaryContact = $customer->contacts->firstWhere('is_primary', true);
                        $hasActiveSubscription = $customer->subscriptions->first(fn ($s) => $s->status?->name === \App\Models\Subscription::ACTIVE_STATUS_NAME) !== null;
                    @endphp
                    <tr class="row-link" onclick="window.location='{{ route('customer-detail', $customer) }}'">
                        <td>{{ $customer->school?->name ?? '—' }}</td>
                        <td>{{ $primaryContact ? $primaryContact->name.($primaryContact->role ? ', '.$primaryContact->role : '') : '—' }}</td>
                        <td><span class="badge {{ \App\Models\Customer::badgeClassForStatusName($customer->status?->name) }}">{{ $customer->status?->name }}</span></td>
                        <td>@if ($hasActiveSubscription)<span class="badge badge-primary">מנויה פעילה</span>@else—@endif</td>
                        <td class="ltr-num">{{ $customer->converted_at->format('d/m/Y') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-text-secondary">אין עדיין לקוחות — לקוחה נוצרת מהמרת ליד בכרטיס הליד.</td></tr>
                @endforelse
            </tbody>
        </table>
        </div>
    </div>
</div>
