<?php

use App\Services\DashboardSummary;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Build-plan 15 — מסך הבית / דשבורד (US-019, FR-2.16). A pure aggregation
 * layer over stages 4-11 — every number/row below comes straight from
 * App\Services\DashboardSummary, which also carries the build-plan 13/14
 * permission scoping (FR-7.3): a leads.view-only user sees only their own
 * lead-related items here, and none of the financial/collections/materials
 * sections at all — see that service's docblock.
 */
new
#[Layout('layouts.app', ['title' => 'מסך הבית — כפיים'])]
class extends Component
{
    #[Computed]
    public function canSeeLeadItems(): bool
    {
        $user = auth()->user();

        return $user->can('leads.manage') || $user->can('leads.view');
    }

    #[Computed]
    public function canSeeFinancials(): bool
    {
        return auth()->user()->can('payments.manage');
    }

    #[Computed]
    public function newLeadsCount(): int
    {
        return app(DashboardSummary::class)->newLeadsCount(auth()->user());
    }

    #[Computed]
    public function awaitingCallbackCount(): int
    {
        return app(DashboardSummary::class)->awaitingCallbackCount(auth()->user());
    }

    #[Computed]
    public function followUpsDueTodayCount(): int
    {
        return app(DashboardSummary::class)->followUpsDueTodayCount(auth()->user());
    }

    #[Computed]
    public function openCollectionsTotal(): array
    {
        return app(DashboardSummary::class)->openCollectionsTotal(auth()->user());
    }

    #[Computed]
    public function checksToDeposit(): array
    {
        return app(DashboardSummary::class)->checksToDeposit(auth()->user());
    }

    #[Computed]
    public function attentionItems()
    {
        return app(DashboardSummary::class)->attentionItems(auth()->user());
    }

    #[Computed]
    public function latestProgramSummary(): ?array
    {
        return app(DashboardSummary::class)->latestProgramSummary(auth()->user());
    }
};
?>

<div>
    <div class="topbar">
        <div>
            <h1 class="mb-0.5">שלום, {{ auth()->user()->name }}</h1>
            <p class="text-text-secondary m-0 ltr-num" style="direction:ltr; text-align:start">{{ now()->format('d/m/Y') }}</p>
        </div>
        @if ($this->canSeeLeadItems)
            <a href="{{ route('leads') }}" class="btn btn-primary">+ ליד חדש</a>
        @endif
    </div>

    <div class="kpis">
        @if ($this->canSeeLeadItems)
            <div class="card kpi">
                <div class="kpi-head">
                    <span class="label">לידים חדשים</span>
                    <span class="icon-chip tone-secondary"><svg><use href="#icon-leads"></use></svg></span>
                </div>
                <div class="num">{{ $this->newLeadsCount }}</div>
            </div>
            <div class="card kpi">
                <div class="kpi-head">
                    <span class="label">ממתינות לשיחה חוזרת</span>
                    <span class="icon-chip tone-accent"><svg><use href="#icon-phone"></use></svg></span>
                </div>
                <div class="num">{{ $this->awaitingCallbackCount }}</div>
            </div>
            <div class="card kpi">
                <div class="kpi-head">
                    <span class="label">Up Follow להיום</span>
                    <span class="icon-chip tone-primary"><svg><use href="#icon-activity"></use></svg></span>
                </div>
                <div class="num">{{ $this->followUpsDueTodayCount }}</div>
            </div>
        @endif
        @if ($this->canSeeFinancials)
            <div class="card kpi">
                <div class="kpi-head">
                    <span class="label">סך פתוח לגבייה</span>
                    <span class="icon-chip tone-error"><svg><use href="#icon-payments"></use></svg></span>
                </div>
                <div class="num ltr-num" style="direction:ltr">₪{{ number_format($this->openCollectionsTotal['amount'], 0) }}</div>
                <div class="delta" style="color:var(--color-error)">{{ $this->openCollectionsTotal['customerCount'] }} לקוחות</div>
            </div>
            <div class="card kpi">
                <div class="kpi-head">
                    <span class="label">צ'קים להפקדה</span>
                    <span class="icon-chip tone-primary"><svg><use href="#icon-check"></use></svg></span>
                </div>
                <div class="num">{{ $this->checksToDeposit['count'] }}</div>
                <div class="delta ltr-num" style="color:var(--color-text-secondary); direction:ltr">₪{{ number_format($this->checksToDeposit['amount'], 0) }}</div>
            </div>
        @endif
        @if (! $this->canSeeLeadItems && ! $this->canSeeFinancials)
            <div class="card kpi"><div class="empty-state">אין עדיין נתונים להצגה עבור המשתמשת הזו.</div></div>
        @endif
    </div>

    <div class="cols">
        <div class="card">
            <h3>משימות ופריטי טיפול</h3>
            <p style="color:var(--color-text-secondary); font-size:var(--fs-caption); margin:-8px 0 var(--sp-sm)">כל שורה מובילה ישירות לכרטיס הרלוונטי · פריט שהושלם נעלם מהרשימה</p>
            @forelse ($this->attentionItems as $item)
                <a class="list-item" href="{{ $item['url'] }}" style="text-decoration:none; color:inherit">
                    <div>
                        <div>{{ $item['label'] }}</div>
                        <div class="who">{{ $item['subtitle'] }}</div>
                    </div>
                    <span class="badge {{ $item['badgeClass'] }}">{{ $item['badge'] }}</span>
                </a>
            @empty
                <div class="empty-state">אין כרגע פריטים הדורשים טיפול.</div>
            @endforelse
        </div>

        @if ($this->latestProgramSummary)
            @php $summary = $this->latestProgramSummary; @endphp
            <div class="card">
                <h3>תוכנית אחרונה — "{{ $summary['program']->name }}"</h3>
                <p style="color:var(--color-text-secondary); font-size:var(--fs-caption); margin:-8px 0 var(--sp-sm)">מי כבר רכשה ומי רק התעניינה</p>
                @forelse ($summary['purchased'] as $customer)
                    <a class="list-item" href="{{ route('customer-detail', $customer) }}" style="text-decoration:none; color:inherit">
                        <div style="display:flex; align-items:center; gap:10px">
                            <span class="icon-chip tone-primary" style="width:30px; height:30px; border-radius:8px"><svg style="width:15px;height:15px"><use href="#icon-deals"></use></svg></span>
                            {{ $customer->school?->name ?? '—' }} — רכשה
                        </div>
                    </a>
                @empty
                @endforelse
                @forelse ($summary['interested'] as $lead)
                    <a class="list-item" href="{{ route('lead-detail', $lead) }}" style="text-decoration:none; color:inherit">
                        <div style="display:flex; align-items:center; gap:10px">
                            <span class="icon-chip tone-secondary" style="width:30px; height:30px; border-radius:8px"><svg style="width:15px;height:15px"><use href="#icon-star"></use></svg></span>
                            {{ $lead->school?->name ?? $lead->email }} — התעניינה
                        </div>
                    </a>
                @empty
                @endforelse
                @if ($summary['purchased']->isEmpty() && $summary['interested']->isEmpty())
                    <div class="empty-state">אין עדיין נתונים עבור התוכנית האחרונה שנוספה לקטלוג.</div>
                @endif
            </div>
        @endif
    </div>
</div>
