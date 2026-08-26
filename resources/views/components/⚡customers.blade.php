<?php

use App\Models\Customer;
use App\Models\StatusDefinition;
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
            ->when($this->filterStatusId !== '', fn ($q) => $q->where('status_id', $this->filterStatusId))
            ->orderByDesc('converted_at')
            ->get();
    }

    #[Computed]
    public function statuses()
    {
        return StatusDefinition::where('scope', 'customer')->where('is_active', true)->orderBy('sort_order')->get();
    }

    #[Computed]
    public function statusCounts()
    {
        return Customer::query()->selectRaw('status_id, count(*) as aggregate')->groupBy('status_id')->pluck('aggregate', 'status_id');
    }
};
?>

<div>
    <div class="topbar">
        <div>
            <h1 class="mb-0.5">לקוחות</h1>
            <p class="text-text-secondary m-0">CUSTOMER — נוצרות אך ורק מהמרת ליד (FR-8.2); ניהול כרטיס הלקוחה בכרטיס עצמו</p>
        </div>
    </div>

    <div class="filters">
        <span class="chip {{ $filterStatusId === '' ? 'active' : '' }}" wire:click="$set('filterStatusId', '')">הכל ({{ Customer::count() }})</span>
        @foreach ($this->statuses as $status)
            <span class="chip {{ (string) $filterStatusId === (string) $status->id ? 'active' : '' }}" wire:click="$set('filterStatusId', '{{ $status->id }}')">{{ $status->name }} ({{ $this->statusCounts[$status->id] ?? 0 }})</span>
        @endforeach
    </div>

    <div class="card" style="padding:0; overflow:hidden">
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
