<?php

use App\Services\RevenueReport;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Build-plan 11 — דוח הכנסות והוצאות (US-016, FR-6.1-FR-6.4/FR-6.11-FR-6.13),
 * an income statement in the app's own look: summary cards, then one table
 * that opens collapsed — an income row and an expenses row — each
 * expandable to its programs/suppliers and from there to the individual
 * payments/expenses (client-side, Alpine). The printable PDF keeps its own document layout
 * (reports/statement-pdf.blade.php).
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

    /** A filter chip's click — adds or removes $value from years/months/sources. */
    public function toggleFilter(string $filter, string $value): void
    {
        abort_unless(in_array($filter, ['years', 'months', 'sources'], true), 404);

        $current = array_map('strval', $this->{$filter});
        $this->{$filter} = in_array($value, $current, true)
            ? array_values(array_diff($current, [$value]))
            : [...$current, $value];
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

@php
    $statement = $this->statement;
    $money = fn (float $amount) => ($amount < 0 ? '-' : '').'₪'.number_format(abs($amount), floor(abs($amount)) == abs($amount) ? 0 : 2);
    $countOf = fn (int $n, string $plural) => $n === 1
        ? ['תוכניות' => 'תוכנית אחת', 'ספקים' => 'ספק אחד', 'תשלומים' => 'תשלום אחד', 'הוצאות' => 'הוצאה אחת'][$plural]
        : "{$n} {$plural}";
    $isOn = fn (string $filter, $value) => in_array((string) $value, array_map('strval', $this->{$filter}), true);
@endphp

<div>
    <style>
        .rp-filters { display:grid; grid-template-columns:auto 1fr; gap:10px var(--sp-md); align-items:center; }
        .rp-filters .filters { margin:0; gap:6px; }
        .rp-filters .rp-label { font-size:var(--fs-small); font-weight:600; color:var(--color-text-secondary); }
        .rp-table td, .rp-table th { padding:12px 16px; }
        .rp-table .num { text-align:left; white-space:nowrap; }
        .rp-toggle { cursor:pointer; user-select:none; }
        .rp-toggle:hover { background:var(--color-primary-lighter); }
        .rp-chevron { display:inline-block; width:16px; height:16px; vertical-align:-3px; margin-inline-end:6px; transition:transform .15s; color:var(--color-text-secondary); }
        .rp-open > :is(td, th) .rp-chevron { transform:rotate(-90deg); }
        .rp-l0 td { font-weight:700; font-size:16px; }
        .rp-l1 td { background:var(--color-background); }
        .rp-l1 td:first-child { padding-inline-start:40px; }
        .rp-l2 td { font-size:var(--fs-small); color:var(--color-text-secondary); background:var(--color-surface); }
        .rp-l2 td:first-child { padding-inline-start:68px; }
        .rp-count { font-weight:400; font-size:var(--fs-caption); color:var(--color-text-secondary); margin-inline-start:6px; }
        .rp-net td { font-weight:800; font-size:18px; border-top:2px solid var(--color-text-primary); }
        .rp-pos { color:var(--color-success); } .rp-neg { color:var(--color-error); }
        @media (max-width: 640px) {
            .rp-filters { grid-template-columns:1fr; }
            .rp-table td, .rp-table th { padding:10px 8px; }
            .rp-l1 td:first-child { padding-inline-start:24px; }
            .rp-l2 td:first-child { padding-inline-start:36px; }
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

    <div class="card" style="margin-bottom:var(--sp-lg)">
        <div class="rp-filters">
            <span class="rp-label">שנים</span>
            <div class="filters">
                @foreach ($this->availableYears as $year)
                    <button type="button" wire:click="toggleFilter('years', '{{ $year }}')" class="chip {{ $isOn('years', $year) ? 'active' : '' }}">{{ $year }}</button>
                @endforeach
            </div>
            <span class="rp-label">חודשים</span>
            <div class="filters">
                @foreach (RevenueReport::MONTH_NAMES as $number => $name)
                    <button type="button" wire:click="toggleFilter('months', '{{ $number }}')" class="chip {{ $isOn('months', $number) ? 'active' : '' }}">{{ $name }}</button>
                @endforeach
            </div>
            <span class="rp-label">תוכניות</span>
            <div class="filters">
                @foreach ($this->sourceOptions as $key => $label)
                    <button type="button" wire:click="toggleFilter('sources', '{{ $key }}')" class="chip {{ $isOn('sources', $key) ? 'active' : '' }}">{{ $label }}</button>
                @endforeach
            </div>
        </div>
        <div style="display:flex; justify-content:space-between; align-items:center; gap:var(--sp-sm); flex-wrap:wrap; margin-top:var(--sp-md); font-size:var(--fs-caption); color:var(--color-text-secondary)">
            <span>{{ $statement['period'] }}</span>
            @if ($years || $months || $sources)
                <button type="button" wire:click="clearFilters" class="btn btn-ghost btn-sm">ניקוי הסינון</button>
            @endif
        </div>
    </div>

    <div class="kpis" wire:loading.class="opacity-60">
        <div class="card kpi tone-teal">
            <div class="kpi-head"><span class="label">הכנסות</span><span class="icon-chip tone-primary"><svg><use href="#icon-payments"></use></svg></span></div>
            <div class="num ltr-num">{{ $money($statement['revenue_total']) }}</div>
        </div>
        <div class="card kpi tone-pink">
            <div class="kpi-head"><span class="label">הוצאות</span><span class="icon-chip tone-secondary"><svg><use href="#icon-suppliers"></use></svg></span></div>
            <div class="num ltr-num">{{ $money($statement['expense_total']) }}</div>
        </div>
        <div class="card kpi {{ $statement['profit'] < 0 ? 'tone-pink' : 'tone-blue' }}">
            <div class="kpi-head"><span class="label">{{ $statement['profit'] < 0 ? 'הפסד' : 'רווח נקי' }}</span><span class="icon-chip tone-accent"><svg><use href="#icon-dashboard"></use></svg></span></div>
            <div class="num ltr-num {{ $statement['profit'] < 0 ? 'rp-neg' : 'rp-pos' }}">{{ $money($statement['profit']) }}</div>
        </div>
    </div>

    {{-- Collapsed by default; open state lives in Alpine, so it survives filtering. --}}
    <div class="card" style="padding:0; overflow:hidden; margin-bottom:var(--sp-lg)" x-data="{ open: {}, toggle(key) { this.open[key] = ! this.open[key] } }">
        <div class="table-scroll">
        <table class="rp-table">
            <thead><tr><th>סעיף</th><th class="num">סכום</th></tr></thead>
            <tbody>
                @foreach ([['key' => 'rev', 'title' => 'הכנסות', 'lines' => $statement['revenue'], 'total' => $statement['revenue_total'], 'unit' => 'תוכניות', 'item' => 'תשלומים', 'empty' => 'אין הכנסות בתקופה שנבחרה'],
                           ['key' => 'exp', 'title' => 'הוצאות', 'lines' => $statement['expenses'], 'total' => $statement['expense_total'], 'unit' => 'ספקים', 'item' => 'הוצאות', 'empty' => 'אין הוצאות בתקופה שנבחרה']] as $section)
                    <tr class="rp-l0 rp-toggle" x-on:click="toggle('{{ $section['key'] }}')" :class="open['{{ $section['key'] }}'] && 'rp-open'">
                        <td>
                            <svg class="rp-chevron" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 4 6 8l4 4"/></svg>{{ $section['title'] }}
                            <span class="rp-count">{{ $countOf(count($section['lines']), $section['unit']) }}</span>
                        </td>
                        <td class="num ltr-num">{{ $money($section['total']) }}</td>
                    </tr>
                    @forelse ($section['lines'] as $i => $line)
                        @php($lineKey = $section['key'].'-'.$i)
                        <tr class="rp-l1 rp-toggle" x-show="open['{{ $section['key'] }}']" x-cloak x-on:click="toggle('{{ $lineKey }}')" :class="open['{{ $lineKey }}'] && 'rp-open'" wire:key="{{ $lineKey }}-{{ md5($line['label']) }}">
                            <td>
                                <svg class="rp-chevron" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 4 6 8l4 4"/></svg>{{ $line['label'] }}
                                <span class="rp-count">{{ $countOf($line['count'], $section['item']) }}</span>
                            </td>
                            <td class="num ltr-num">{{ $money($line['amount']) }}</td>
                        </tr>
                        @foreach ($line['items'] as $item)
                            <tr class="rp-l2" x-show="open['{{ $section['key'] }}'] && open['{{ $lineKey }}']" x-cloak>
                                <td><span class="ltr-num">{{ $item['date'] }}</span> · {{ $item['description'] }}</td>
                                <td class="num ltr-num">{{ $money($item['amount']) }}</td>
                            </tr>
                        @endforeach
                    @empty
                        <tr class="rp-l1" x-show="open['{{ $section['key'] }}']" x-cloak><td colspan="2" style="color:var(--color-text-secondary)">{{ $section['empty'] }}</td></tr>
                    @endforelse
                @endforeach
                <tr class="rp-net">
                    <td>{{ $statement['profit'] < 0 ? 'הפסד' : 'רווח נקי' }}</td>
                    <td class="num ltr-num {{ $statement['profit'] < 0 ? 'rp-neg' : 'rp-pos' }}">{{ $money($statement['profit']) }}</td>
                </tr>
            </tbody>
        </table>
        </div>
    </div>

    <p style="color:var(--color-text-secondary); font-size:var(--fs-caption); margin-top:var(--sp-md)">הכנסה ממנוי שנתי נפרשת על פני 12 חודשי המנוי. הכנסות מסווגות לפי התוכנית או המארז של העסקה, והוצאות לפי הספק. לחיצה על שורה פותחת את הפירוט שלה.</p>
</div>
