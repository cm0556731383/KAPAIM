<?php

use App\Services\RevenueReport;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Build-plan 11 — דוח הכנסות והוצאות (US-016, FR-6.1-FR-6.4/FR-6.11-FR-6.13),
 * laid out as an income statement: income by program/bundle on top,
 * expenses by supplier below, net profit, then a month-by-month breakdown.
 * Every number comes from RevenueReport::statement(); the filters (years,
 * months, programs — any combination, empty = all) live in the URL, so the
 * "PDF" button (cashflow-report.pdf) prints exactly what's on screen.
 * Gated on 'expenses.manage' — the closest existing financial-data
 * permission (no dedicated 'reports.view' exists).
 */
new
#[Layout('layouts.app', ['title' => 'דוח הכנסות והוצאות — כפיים'])]
class extends Component
{
    #[Url]
    public array $years = [];

    #[Url]
    public array $months = [];

    #[Url]
    public array $sources = [];

    public function mount(): void
    {
        abort_unless(auth()->user()->can('expenses.manage'), 403);
    }

    public function clearFilters(): void
    {
        $this->reset(['years', 'months', 'sources']);
    }

    #[Computed]
    public function statement(): array
    {
        return app(RevenueReport::class)->statement($this->years, $this->months, $this->sources);
    }

    #[Computed]
    public function availableYears(): array
    {
        return app(RevenueReport::class)->availableYears();
    }

    #[Computed]
    public function sourceOptions(): array
    {
        return app(RevenueReport::class)->sourceOptions();
    }

    public function pdfUrl(): string
    {
        return route('cashflow-report.pdf', array_filter([
            'years' => array_values($this->years),
            'months' => array_values($this->months),
            'sources' => array_values($this->sources),
        ]));
    }
};
?>

<div>
    <style>
        .rf-card { background:var(--color-surface, #fff); border:1px solid var(--color-border); border-radius:12px; padding:var(--sp-md) var(--sp-lg); margin-bottom:var(--sp-lg); }
        .rf-row { display:flex; gap:var(--sp-md); align-items:flex-start; padding:8px 0; }
        .rf-row + .rf-row { border-top:1px dashed var(--color-border); }
        .rf-label { flex:0 0 72px; font-weight:600; font-size:var(--fs-small); padding-top:5px; }
        .rf-chips { display:flex; flex-wrap:wrap; gap:6px; }
        .rf-chip { display:inline-flex; align-items:center; margin:0; padding:4px 12px; border:1px solid var(--color-border); border-radius:999px; font-size:var(--fs-small); cursor:pointer; user-select:none; background:#fff; transition:background .15s, border-color .15s; }
        .rf-chip input { display:none; }
        .rf-chip:hover { border-color:var(--color-primary); }
        .rf-chip:has(input:checked) { background:var(--color-primary); border-color:var(--color-primary); color:#fff; }
        .rf-foot { display:flex; justify-content:space-between; align-items:center; gap:var(--sp-sm); flex-wrap:wrap; padding-top:10px; border-top:1px dashed var(--color-border); font-size:var(--fs-caption); color:var(--color-text-secondary); }

        .st-sheet { background:#fff; border:1px solid var(--color-border); border-radius:14px; padding:36px 40px; max-width:900px; box-shadow:0 18px 40px -18px rgb(28 35 31 / .18); }
        .st-head { display:flex; justify-content:space-between; align-items:flex-end; gap:var(--sp-md); padding-bottom:16px; border-bottom:3px solid var(--color-primary); margin-bottom:20px; }
        .st-head h2 { margin:0; font-size:24px; color:var(--color-primary); }
        .st-head .meta { color:var(--color-text-secondary); font-size:var(--fs-small); margin-top:4px; }
        .st-head img { height:52px; }
        .st-table, .st-months { width:100%; border-collapse:collapse; }
        .st-table td { padding:9px 14px; }
        .st-section td { background:var(--color-primary); color:#fff; font-weight:700; letter-spacing:.2px; }
        .st-section td:first-child { border-radius:0 8px 8px 0; } .st-section td:last-child { border-radius:8px 0 0 8px; }
        .st-line td { border-bottom:1px solid var(--color-border); }
        .st-empty td { color:var(--color-text-secondary); font-style:italic; }
        .st-total td { font-weight:700; background:var(--color-background); border-top:2px solid var(--color-text-primary); }
        .st-gap td { height:14px; padding:0; }
        .st-net td { font-weight:800; font-size:20px; padding:14px; }
        .st-profit td { background:#DCFCE7; color:#166534; } .st-loss td { background:#FEE2E2; color:#B91C1C; }
        .st-net td:first-child { border-radius:0 10px 10px 0; } .st-net td:last-child { border-radius:10px 0 0 10px; }
        .st-amount { text-align:left; white-space:nowrap; width:30%; }
        .st-muted { color:var(--color-text-secondary); font-size:var(--fs-caption); }
        .st-subtitle { font-weight:700; color:var(--color-primary); margin:32px 0 10px; font-size:17px; }
        .st-months th { text-align:right; background:var(--color-background); padding:8px 12px; font-size:var(--fs-small); border-bottom:2px solid var(--color-text-primary); }
        .st-months th.st-amount { text-align:left; }
        .st-months td { padding:7px 12px; border-bottom:1px solid var(--color-border); font-size:var(--fs-small); }
        .st-months .st-amount { width:20%; }
        .st-scroll { overflow-x:auto; }
        .st-months-total td { font-weight:700; border-top:2px solid var(--color-text-primary); border-bottom:none; }
        .st-pos { color:#166534; } .st-neg { color:#B91C1C; }
        .st-note { color:var(--color-text-secondary); font-size:var(--fs-caption); margin:24px 0 0; }
        @media (max-width: 640px) {
            .st-sheet { padding:20px 16px; }
            .st-head { flex-direction:column-reverse; align-items:flex-start; }
            .rf-row { flex-direction:column; gap:6px; }
            .rf-label { flex-basis:auto; padding-top:0; }
            .st-months th, .st-months td { padding:7px 6px; }
            .st-months td:first-child { white-space:nowrap; }
        }
    </style>

    <div class="topbar">
        <div>
            <h1 style="margin-bottom:2px">דוח הכנסות והוצאות</h1>
        </div>
        <a href="{{ $this->pdfUrl() }}" target="_blank" class="btn btn-primary">הפקת PDF להדפסה</a>
    </div>

    <div class="tabs">
        <a href="{{ route('suppliers') }}">ספקים</a>
        <a href="{{ route('expenses') }}">הוצאות</a>
        <a href="{{ route('cashflow-report') }}" class="active">דוח הכנסות והוצאות</a>
    </div>

    <div class="rf-card">
        <div class="rf-row">
            <div class="rf-label">שנים</div>
            <div class="rf-chips">
                @foreach ($this->availableYears as $year)
                    <label class="rf-chip"><input type="checkbox" wire:model.live="years" value="{{ $year }}">{{ $year }}</label>
                @endforeach
            </div>
        </div>
        <div class="rf-row">
            <div class="rf-label">חודשים</div>
            <div class="rf-chips">
                @foreach (RevenueReport::MONTH_NAMES as $number => $name)
                    <label class="rf-chip"><input type="checkbox" wire:model.live="months" value="{{ $number }}">{{ $name }}</label>
                @endforeach
            </div>
        </div>
        <div class="rf-row">
            <div class="rf-label">תוכניות</div>
            <div class="rf-chips">
                @foreach ($this->sourceOptions as $key => $label)
                    <label class="rf-chip"><input type="checkbox" wire:model.live="sources" value="{{ $key }}">{{ $label }}</label>
                @endforeach
            </div>
        </div>
        <div class="rf-foot">
            <span>אפשר לשלב: למשל ספטמבר–דצמבר של 2025 ו-2026 בתוכנית אחת. בלי בחירה — הכול נכלל.</span>
            @if ($years || $months || $sources)
                <button type="button" wire:click="clearFilters" class="btn btn-ghost btn-sm">ניקוי הסינון</button>
            @endif
        </div>
    </div>

    <div class="st-sheet" wire:loading.class="opacity-60">
        <div class="st-head">
            <div>
                <h2>דוח הכנסות והוצאות</h2>
                <div class="meta">{{ $this->statement['period'] }}</div>
            </div>
            <img src="{{ asset('images/logo.png') }}" alt="כפיים">
        </div>

        @include('reports.statement-body', ['statement' => $this->statement])
    </div>
</div>
