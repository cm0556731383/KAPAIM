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

    /**
     * Every choice each filter offers, value => label. An empty filter means
     * "all of them"; [NONE] means nothing is ticked.
     *
     * @return array<string, array<string, string>>
     */
    public function filterOptions(): array
    {
        return [
            'years' => collect($this->availableYears)->mapWithKeys(fn ($year) => [(string) $year => (string) $year])->all(),
            'months' => collect(RevenueReport::MONTH_NAMES)->mapWithKeys(fn ($name, $number) => [(string) $number => $name])->all(),
            'sources' => $this->sourceOptions,
        ];
    }

    /** A filter with nothing ticked (after "הכל" is unticked) — stored as this one value, since an empty filter means "all". */
    public const NONE = 'none';

    /** The ticked values of a filter: every option for "all", none for NONE. */
    public function selectedValues(string $filter): array
    {
        $options = array_map('strval', array_keys($this->filterOptions()[$filter]));

        return match (true) {
            $this->{$filter} === [] => $options,
            $this->{$filter} === [self::NONE] => [],
            default => array_values(array_intersect($options, array_map('strval', $this->{$filter}))),
        };
    }

    public function isNone(string $filter): bool
    {
        return $this->{$filter} === [self::NONE];
    }

    /** One option's checkbox — ticks/unticks just that option. Everything ticked is stored as "all", nothing as NONE. */
    public function toggleFilter(string $filter, string $value): void
    {
        $options = array_map('strval', array_keys($this->filterOptions()[$filter] ?? abort(404)));
        abort_unless(in_array($value, $options, true), 404);

        $selected = $this->selectedValues($filter);
        $selected = in_array($value, $selected, true)
            ? array_values(array_diff($selected, [$value]))
            : array_values(array_intersect($options, [...$selected, $value]));

        $this->{$filter} = match (true) {
            $selected === [] => [self::NONE],
            count($selected) === count($options) => [],
            default => $selected,
        };
    }

    /** The "הכל" checkbox: ticks every option, or — when they're all ticked already — unticks them all, so a single one can be picked. */
    public function selectAll(string $filter): void
    {
        abort_unless(in_array($filter, ['years', 'months', 'sources'], true), 404);

        $this->{$filter} = $this->{$filter} === [] ? [self::NONE] : [];
    }

    public function clearFilters(): void
    {
        $this->reset(['years', 'months', 'sources']);
    }

    /** What a filter's dropdown button says — "כל החודשים", "ספטמבר, אוקטובר", or "5 חודשים". */
    public function filterSummary(string $filter, string $allLabel, string $plural): string
    {
        if (! $this->{$filter}) {
            return $allLabel;
        }
        if ($this->isNone($filter)) {
            return 'לא נבחר';
        }

        $options = $this->filterOptions()[$filter];
        $labels = collect($this->selectedValues($filter))->map(fn ($key) => $options[$key])->values();

        return $labels->count() <= 2 ? $labels->implode(', ') : $labels->count().' '.$plural;
    }

    #[Computed]
    public function statement(): array
    {
        if ($this->hasEmptyFilter()) {
            return ['revenue' => [], 'revenue_total' => 0.0, 'expenses' => [], 'expense_total' => 0.0, 'profit' => 0.0, 'period' => ''];
        }

        return app(RevenueReport::class)->statement($this->years, $this->months, $this->sources);
    }

    /** Some filter has nothing ticked — there's nothing to report (or print) until something is. */
    public function hasEmptyFilter(): bool
    {
        return $this->isNone('years') || $this->isNone('months') || $this->isNone('sources');
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

    public function pdfUrl(string $detail): string
    {
        return route('cashflow-report.pdf', array_filter([
            'years' => array_values($this->years),
            'months' => array_values($this->months),
            'sources' => array_values($this->sources),
            'detail' => $detail,
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
    $isOn = fn (string $filter, $value) => in_array((string) $value, $this->selectedValues($filter), true);
@endphp

<div>
    <style>
        .rp-filterbar { display:flex; gap:var(--sp-sm); align-items:center; flex-wrap:wrap; margin-bottom:var(--sp-lg); }
        .rp-dd { position:relative; }
        .rp-dd-btn { display:inline-flex; align-items:center; gap:8px; padding:8px 14px; background:var(--color-surface); border:1px solid var(--color-border); border-radius:var(--radius-pill); font:inherit; font-size:var(--fs-small); color:var(--color-text-primary); cursor:pointer; transition:border-color .15s, box-shadow .15s; }
        .rp-dd-btn:hover { border-color:var(--color-primary); }
        .rp-dd-btn.is-set { border-color:var(--color-primary); background:var(--color-primary-lighter); }
        .rp-dd-title { color:var(--color-text-secondary); }
        .rp-dd-value { font-weight:600; max-width:220px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .rp-dd-caret { width:14px; height:14px; color:var(--color-text-secondary); }
        .rp-dd-panel { position:absolute; top:calc(100% + 6px); inset-inline-start:0; z-index:30; min-width:220px; max-height:340px; overflow:auto; background:var(--color-surface); border:1px solid var(--color-border); border-radius:12px; box-shadow:var(--shadow-card-hover); padding:6px; }
        .rp-dd-item { display:flex; align-items:center; gap:8px; width:100%; margin:0; padding:7px 10px; border:none; background:none; border-radius:8px; font:inherit; font-size:var(--fs-small); color:var(--color-text-primary); text-align:start; cursor:pointer; white-space:nowrap; }
        .rp-dd-item:hover { background:var(--color-primary-lighter); }
        .rp-box { flex:0 0 16px; height:16px; border:1.5px solid var(--color-border); border-radius:4px; background:#fff; display:inline-flex; align-items:center; justify-content:center; }
        .rp-dd-item[aria-checked="true"] .rp-box { background:var(--color-primary); border-color:var(--color-primary); }
        .rp-dd-item[aria-checked="true"] .rp-box::after { content:""; width:8px; height:4px; border:2px solid #fff; border-top:none; border-right:none; transform:rotate(-45deg) translate(1px,-1px); }
        .rp-dd-all { font-weight:600; border-bottom:1px solid var(--color-border); border-radius:8px 8px 0 0; margin-bottom:4px; }
        .rp-dd-cols { display:grid; grid-template-columns:1fr 1fr; min-width:240px; }
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
            .rp-dd, .rp-dd-btn { width:100%; } .rp-dd-value { max-width:none; flex:1; text-align:start; }
            .rp-dd-panel { inset-inline:0; }
            .rp-table td, .rp-table th { padding:10px 8px; }
            .rp-l1 td:first-child { padding-inline-start:24px; }
            .rp-l2 td:first-child { padding-inline-start:36px; }
        }
    </style>

    <div class="topbar">
        <div>
            <h1 style="margin-bottom:2px">דוח הכנסות והוצאות</h1>
        </div>
        <div style="display:flex; gap:var(--sp-sm); flex-wrap:wrap">
            @if ($this->hasEmptyFilter())
                <button type="button" class="btn btn-secondary" disabled style="opacity:.5; cursor:not-allowed" title="יש לבחור לפחות פריט אחד בכל סינון">PDF מקוצר</button>
                <button type="button" class="btn btn-primary" disabled style="opacity:.5; cursor:not-allowed" title="יש לבחור לפחות פריט אחד בכל סינון">PDF מפורט</button>
            @else
                <a href="{{ $this->pdfUrl('summary') }}" target="_blank" class="btn btn-secondary" title="הכנסות, הוצאות ורווח — סכומים כוללים בלבד">PDF מקוצר</a>
                <a href="{{ $this->pdfUrl('full') }}" target="_blank" class="btn btn-primary" title="כל תוכנית וספק, וכל תשלום והוצאה">PDF מפורט</a>
            @endif
        </div>
    </div>

    <div class="tabs">
        <a href="{{ route('suppliers') }}">ספקים</a>
        <a href="{{ route('expenses') }}">הוצאות</a>
        <a href="{{ route('cashflow-report') }}" class="active">דוח הכנסות והוצאות</a>
    </div>

    <div class="rp-filterbar">
        @foreach ([['key' => 'years', 'title' => 'שנים', 'all' => 'כל השנים', 'plural' => 'שנים'],
                   ['key' => 'months', 'title' => 'חודשים', 'all' => 'כל החודשים', 'plural' => 'חודשים'],
                   ['key' => 'sources', 'title' => 'תוכניות', 'all' => 'כל התוכניות', 'plural' => 'תוכניות']] as $filter)
            <div class="rp-dd" x-data="{ open: false }" x-on:click.outside="open = false" x-on:keydown.escape="open = false">
                <button type="button" class="rp-dd-btn {{ $this->{$filter['key']} ? 'is-set' : '' }}" x-on:click="open = ! open" :aria-expanded="open">
                    <span class="rp-dd-title">{{ $filter['title'] }}</span>
                    <span class="rp-dd-value">{{ $this->filterSummary($filter['key'], $filter['all'], $filter['plural']) }}</span>
                    <svg class="rp-dd-caret" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2"><path d="m4 6 4 4 4-4"/></svg>
                </button>
                <div class="rp-dd-panel" x-show="open" x-cloak x-transition.opacity.duration.100ms>
                    <button type="button" class="rp-dd-item rp-dd-all" role="menuitemcheckbox" aria-checked="{{ $this->{$filter['key']} ? 'false' : 'true' }}" wire:click="selectAll('{{ $filter['key'] }}')">
                        <span class="rp-box"></span>הכל
                    </button>
                    <div class="rp-dd-list {{ $filter['key'] === 'months' ? 'rp-dd-cols' : '' }}">
                        @foreach ($this->filterOptions()[$filter['key']] as $value => $label)
                            <button type="button" class="rp-dd-item" role="menuitemcheckbox" aria-checked="{{ $isOn($filter['key'], $value) ? 'true' : 'false' }}" wire:key="{{ $filter['key'] }}-{{ $value }}" wire:click="toggleFilter('{{ $filter['key'] }}', '{{ $value }}')">
                                <span class="rp-box"></span>{{ $label }}
                            </button>
                        @endforeach
                    </div>
                </div>
            </div>
        @endforeach
        @if ($years || $months || $sources)
            <button type="button" wire:click="clearFilters" class="btn btn-ghost btn-sm">ניקוי</button>
        @endif
    </div>

    @if ($this->hasEmptyFilter())
        <div class="card" style="margin-bottom:var(--sp-lg); border-color:var(--color-warning); background:var(--color-warning-bg); font-size:var(--fs-small)">
            באחד הסינונים לא נבחר אף פריט, לכן הדוח ריק. יש לבחור לפחות פריט אחד, או "הכל".
        </div>
    @endif

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

    <p style="color:var(--color-text-secondary); font-size:var(--fs-caption); margin-top:var(--sp-md)">הכנסה ממנוי נפרשת שווה בשווה על פני {{ \App\Models\Subscription::TOTAL_DELIVERIES }} חודשי המנוי, ובמנוי שבוטל נספרים רק החודשים שלפני הביטול. הכנסות מסווגות לפי התוכנית או המארז של העסקה, והוצאות לפי הספק. לחיצה על שורה פותחת את הפירוט שלה.</p>
</div>
