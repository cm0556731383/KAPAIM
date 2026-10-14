<?php

use App\Concerns\Notifies;
use App\Models\Payment;
use App\Models\Receipt;
use App\Services\ActivityLogger;
use App\Services\DashboardSummary;
use App\Services\Integrations\ExternalOperationRunner;
use App\Services\Integrations\SummitClient;
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
#[Layout('layouts.app', ['title' => 'מסך הבית — כפיים', 'hideUserBar' => true])]
class extends Component
{
    use Notifies;

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

    /** Checks whose due date has come and that aren't marked cleared yet — the "issue its receipt?" reminder. */
    #[Computed]
    public function dueChecks()
    {
        if (! $this->canSeeFinancials) {
            return collect();
        }

        return Payment::checksDue()->with(['deal.customer.school', 'deal.documents'])->get();
    }

    /**
     * The reminder's answer: the check cleared — marked so (FR-4.28) and its
     * receipt issued, SUMIT emailing it to the customer.
     */
    public function clearCheck(int $paymentId, ActivityLogger $activityLogger, ExternalOperationRunner $runner, SummitClient $summit): void
    {
        abort_unless($this->canSeeFinancials, 403);

        $payment = Payment::checksDue()->findOrFail($paymentId);
        $deal = $payment->deal;

        try {
            $payment->markCleared();
        } catch (\RuntimeException $e) {
            $this->notifyWarning($e->getMessage());

            return;
        }

        $activityLogger->log('payment.check_cleared', "צ'ק בסך ₪{$payment->amount} נפרע עבור עסקה #{$deal->id}", [
            'deal_id' => $deal->id, 'customer_id' => $deal->customer_id, 'metadata' => ['payment_id' => $payment->id],
        ]);
        unset($this->dueChecks);

        if (! $deal->issuedInvoice()) {
            $this->notifyInfo("הצ'ק סומן כנפרע. לעסקה טרם הופקה חשבונית — הקבלה תופק מכרטיס העסקה אחרי הפקתה.");

            return;
        }

        try {
            Receipt::issueFor($deal, $payment->fresh(), $runner, $summit);
        } catch (\RuntimeException $e) {
            $this->notifyWarning("הצ'ק סומן כנפרע, אך הקבלה לא הופקה: {$e->getMessage()}");

            return;
        }

        $this->notifySuccess("הצ'ק סומן כנפרע והקבלה נשלחה ללקוחה.");
    }

    /** "Not yet" — the check stays uncleared; the reminder comes back tomorrow. */
    public function snoozeCheck(int $paymentId): void
    {
        $snoozed = session('snoozed_checks', []);
        $snoozed[$paymentId] = today()->toDateString();
        session(['snoozed_checks' => $snoozed]);
        unset($this->dueChecks);
    }

    public function isSnoozed(int $paymentId): bool
    {
        return (session('snoozed_checks', [])[$paymentId] ?? null) === today()->toDateString();
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

<div class="home">
    @php
        $dueChecks = $this->dueChecks->reject(fn ($p) => $this->isSnoozed($p->id));
        $openCount = $this->attentionItems->count() + $dueChecks->count();
    @endphp

    {{-- The day's greeting, on the logo's own mark (the "כ" ring and its arcs). --}}
    <section class="home-hero">
        <svg class="home-hero-mark" viewBox="0 0 240 240" aria-hidden="true" focusable="false">
            <path class="arc arc-outer" d="M 120 12 A 108 108 0 1 1 26 174" />
            <path class="arc arc-inner" d="M 196 58 A 92 92 0 0 1 64 192" />
            <path class="ring" d="M 82 66 A 62 62 0 1 1 82 174" />
            <circle class="dot" cx="120" cy="120" r="13" />
        </svg>
        <div class="home-hero-user">
            <span>{{ auth()->user()->role?->name }}</span>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit"><svg aria-hidden="true"><use href="#icon-logout"></use></svg>יציאה</button>
            </form>
        </div>
        <div class="home-hero-text">
            <p class="home-hero-date">{{ now()->locale('he')->dayName === 'שבת' ? 'שבת' : 'יום '.now()->locale('he')->dayName }}, {{ \App\Services\HebrewCalendar::dateLabel(now()) }} <span class="ltr-num">({{ now()->format('d/m/Y') }})</span></p>
            <h1>שלום, {{ auth()->user()->name }}</h1>
            <p class="home-hero-summary">
                @if ($openCount === 0)
                    אין היום פריטים שמחכים לטיפול.
                @elseif ($openCount === 1)
                    פריט אחד מחכה לטיפול היום.
                @else
                    <span class="ltr-num">{{ $openCount }}</span> פריטים מחכים לטיפול היום.
                @endif
            </p>
            @if ($this->canSeeLeadItems)
                <a href="{{ route('leads') }}" class="btn home-hero-cta">+ ליד חדש</a>
            @endif
        </div>
    </section>

    @if ($dueChecks->isNotEmpty())
        {{-- Reminder: a check's due date has come — has it cleared? Then issue its receipt. --}}
        <div class="card check-reminder" role="alert">
            <h3>צ'קים לפירעון — להפיק קבלה?</h3>
            <p class="pay-note" style="margin:-8px 0 var(--sp-md)">הגיע מועד הפירעון של {{ $dueChecks->count() === 1 ? "הצ'ק הבא" : "הצ'קים הבאים" }}. אם הכסף נכנס לחשבון — סמני כנפרע, והקבלה תופק ותישלח ללקוחה.</p>
            @foreach ($dueChecks as $check)
                <div class="check-reminder-row" wire:key="due-check-{{ $check->id }}">
                    <div>
                        <a href="{{ route('deal-detail', $check->deal) }}" style="font-weight:600">{{ $check->deal->customer->school?->name ?? '—' }}</a>
                        <div class="pay-note ltr-num">
                            צ'ק{{ $check->check_number ? " מס' {$check->check_number}" : '' }} ·
                            ₪{{ number_format((float) $check->amount, 0) }} ·
                            פירעון {{ $check->check_due_date->format('d/m/Y') }}{{ $check->check_due_date->isToday() ? ' (היום)' : '' }}
                        </div>
                        @if ($check->checkBankLabel())
                            <div class="pay-note">{{ $check->checkBankLabel() }}</div>
                        @endif
                    </div>
                    <div style="display:flex; gap:6px; flex-wrap:wrap">
                        <button type="button" wire:click="clearCheck({{ $check->id }})" wire:loading.attr="disabled" class="btn btn-primary btn-sm">נפרע — הפקת קבלה</button>
                        <button type="button" wire:click="snoozeCheck({{ $check->id }})" class="btn btn-ghost btn-sm">עדיין לא — להזכיר מחר</button>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    <div class="kpis">
        @if ($this->canSeeLeadItems)
            <a class="card kpi tone-pink" href="{{ route('leads') }}" style="text-decoration:none; color:inherit">
                <div class="kpi-head">
                    <span class="label">לידים</span>
                    <span class="icon-chip tone-secondary"><svg><use href="#icon-leads"></use></svg></span>
                </div>
                <div class="num">{{ $this->newLeadsCount }}</div>
            </a>
            <div class="card kpi tone-amber">
                <div class="kpi-head">
                    <span class="label">ממתינות לשיחה חוזרת</span>
                    <span class="icon-chip tone-accent"><svg><use href="#icon-phone"></use></svg></span>
                </div>
                <div class="num">{{ $this->awaitingCallbackCount }}</div>
            </div>
            <div class="card kpi tone-teal">
                <div class="kpi-head">
                    <span class="label">Follow Up להיום</span>
                    <span class="icon-chip tone-primary"><svg><use href="#icon-activity"></use></svg></span>
                </div>
                <div class="num">{{ $this->followUpsDueTodayCount }}</div>
            </div>
        @endif
        @if ($this->canSeeFinancials)
            <div class="card kpi tone-pink">
                <div class="kpi-head">
                    <span class="label">סך פתוח לגבייה</span>
                    <span class="icon-chip tone-error"><svg><use href="#icon-payments"></use></svg></span>
                </div>
                <div class="num ltr-num" style="direction:ltr">₪{{ number_format($this->openCollectionsTotal['amount'], 0) }}</div>
                <div class="delta" style="color:var(--color-error)">{{ $this->openCollectionsTotal['customerCount'] }} לקוחות</div>
            </div>
            <div class="card kpi tone-blue">
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
            @forelse ($this->attentionItems as $item)
                <a class="list-item attention-item {{ $item['badgeClass'] }}-mark" href="{{ $item['url'] }}" style="text-decoration:none; color:inherit">
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
                <h3>מי רכשה ומי התעניינה ב"{{ $summary['program']->name }}"</h3>
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
