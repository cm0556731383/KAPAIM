<?php

use App\Services\RevenueReport;
use Carbon\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Build-plan 11 — דוח הכנסות ורווח (US-016, FR-6.1-FR-6.4/FR-6.11-FR-6.13).
 * Read-only — every number comes from App\Services\RevenueReport, kept as a
 * plain unit-testable service rather than duplicated here. Gated on
 * 'expenses.manage' — this codebase has no dedicated 'reports.view'
 * resource seeded/wired anywhere yet, so reusing the closest existing
 * financial-data permission is the more consistent choice than inventing an
 * unused new one (this stage's judgment call — see this stage's report).
 */
new
#[Layout('layouts.app', ['title' => 'דוח הכנסות ורווח — כפיים'])]
class extends Component
{
    public function mount(): void
    {
        abort_unless(auth()->user()->can('expenses.manage'), 403);
    }

    #[Computed]
    public function byMonth(): array
    {
        $report = app(RevenueReport::class);
        $revenue = $report->revenueByMonth();
        $profit = $report->profitByMonth();

        $months = array_unique(array_merge(array_keys($revenue), array_keys($profit)));
        sort($months);

        return collect($months)->map(fn ($month) => [
            'label' => Carbon::createFromFormat('Y-m', $month)->format('m/Y'),
            'revenue' => $revenue[$month] ?? 0.0,
            'expense' => ($revenue[$month] ?? 0.0) - ($profit[$month] ?? 0.0),
            'profit' => $profit[$month] ?? 0.0,
        ])->all();
    }

    #[Computed]
    public function byProgram(): array
    {
        $report = app(RevenueReport::class);
        $revenue = $report->revenueByProgram();
        $profit = $report->profitByProgram();

        $labels = array_unique(array_merge(array_keys($revenue), array_keys($profit)));
        sort($labels);

        return collect($labels)->map(fn ($label) => [
            'label' => $label,
            'revenue' => $revenue[$label] ?? 0.0,
            'expense' => ($revenue[$label] ?? 0.0) - ($profit[$label] ?? 0.0),
            'profit' => $profit[$label] ?? 0.0,
        ])->all();
    }
};
?>

<div>
    <div class="topbar">
        <div>
            <h1 style="margin-bottom:2px">דוח הכנסות ורווח</h1>
            <p class="text-text-secondary m-0">הכנסות, הוצאות ורווחיות לפי חודש ותוכנית</p>
        </div>
    </div>

    <div class="tabs">
        <a href="{{ route('suppliers') }}">ספקים</a>
        <a href="{{ route('expenses') }}">הוצאות</a>
        <a href="{{ route('cashflow-report') }}" class="active">דוח הכנסות ורווח</a>
    </div>

    <h3>הכנסות ורווח — לפי חודש</h3>
    <p style="color:var(--color-text-secondary); font-size:var(--fs-small); margin-top:-6px">
        הכנסה ממנוי שנתי ששולם מראש נפרשת על פני חודשי המנוי · רווח מחושב כהפרש בין הכנסה להוצאה של אותו חודש
    </p>
    <div class="card" style="padding:0; overflow:hidden; margin-bottom: var(--sp-xl)">
        <div class="table-scroll">
        <table>
            <thead><tr><th>חודש</th><th>הכנסה</th><th>הוצאה</th><th>רווח</th></tr></thead>
            <tbody>
                @forelse ($this->byMonth as $row)
                    <tr>
                        <td>{{ $row['label'] }}</td>
                        <td class="ltr-num">₪{{ number_format($row['revenue'], 0) }}</td>
                        <td class="ltr-num">₪{{ number_format($row['expense'], 0) }}</td>
                        <td class="ltr-num" style="font-weight:700; color: {{ $row['profit'] >= 0 ? 'var(--color-success)' : 'var(--color-error)' }}">
                            {{ $row['profit'] < 0 ? '-' : '' }}₪{{ number_format(abs($row['profit']), 0) }}
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="empty-state">אין עדיין נתונים להצגה.</td></tr>
                @endforelse
            </tbody>
        </table>
        </div>
    </div>

    <h3>הכנסות ורווח — לפי תוכנית</h3>
    <p style="color:var(--color-text-secondary); font-size:var(--fs-small); margin-top:-6px">
        רכישת מארז מוצגת תחת דלי "{{ \App\Services\RevenueReport::BUNDLE_BUCKET_LABEL }}" · הוצאה ללא שיוך לתוכנית אינה מופיעה כאן
    </p>
    <div class="card" style="padding:0; overflow:hidden">
        <div class="table-scroll">
        <table>
            <thead><tr><th>תוכנית</th><th>הכנסה</th><th>הוצאה</th><th>רווח</th></tr></thead>
            <tbody>
                @forelse ($this->byProgram as $row)
                    <tr>
                        <td>{{ $row['label'] }}</td>
                        <td class="ltr-num">₪{{ number_format($row['revenue'], 0) }}</td>
                        <td class="ltr-num">₪{{ number_format($row['expense'], 0) }}</td>
                        <td class="ltr-num" style="font-weight:700; color: {{ $row['profit'] >= 0 ? 'var(--color-success)' : 'var(--color-error)' }}">
                            {{ $row['profit'] < 0 ? '-' : '' }}₪{{ number_format(abs($row['profit']), 0) }}
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="empty-state">אין עדיין נתונים להצגה.</td></tr>
                @endforelse
            </tbody>
        </table>
        </div>
    </div>
</div>
