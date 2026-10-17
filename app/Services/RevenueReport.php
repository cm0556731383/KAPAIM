<?php

namespace App\Services;

use App\Models\Bundle;
use App\Models\Deal;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Program;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Build-plan 11 — US-016/FR-6.1-FR-6.4/FR-6.11-FR-6.13. Kept as a plain,
 * unit-testable service rather than folded into a Livewire component's PHP
 * class, per this stage's own instruction.
 *
 * Every month key returned below is a 'Y-m' string (e.g. '2026-08');
 * ⚡cashflow-report.blade.php formats it for display.
 *
 * FR-6.4, the one genuinely tricky rule: a deal against the catalog's
 * is_subscription_type bundle (moved from Program to Bundle 2026-09-09)
 * does NOT recognize its revenue in the month(s) its Payment rows actually
 * landed. Every such deal has exactly one Subscription row
 * (Deal::openSubscriptionIfApplicable(), build-plan 09) — that
 * subscription's agreed_price is instead spread evenly over the
 * subscription's own months (Subscription::TOTAL_DELIVERIES, 10 Hebrew
 * months from Subscription::firstMonthDate()) — each tenth recognized in the
 * Gregorian month that Hebrew month starts in — independent of the deal's
 * real payment history. A cancelled subscription recognizes only its
 * months before the cancellation, net of the credit actually given — see
 * subscriptionMonthlySlices() (the business owner's 2026-10-16 decision;
 * it used to keep the original full schedule).
 *
 * revenueByProgram()/profitByProgram() bucket every bundle-deal's revenue
 * under the synthetic BUNDLE_BUCKET_LABEL (a bundle is never a catalog
 * Program row, so Expense.program_id — FK'd to `programs` — can never
 * target it either: no expense ever reduces this bucket).
 *
 * FR-6.13: profit = revenue minus the expenses attributed to that same
 * program. An expense with no program_id (Expense.program_id is nullable —
 * FR-6.6) is excluded from the BY-PROGRAM profit calculation entirely
 * (there is no sensible program bucket to charge it against) but still
 * reduces the BY-MONTH total (every real expense reduces overall profit for
 * the month it was incurred in) — the simpler of the two defensible options
 * FR-6.13 leaves open, chosen over inventing a synthetic "no program"
 * bucket.
 */
class RevenueReport
{
    public const BUNDLE_BUCKET_LABEL = 'מארזים';

    /** statement()'s source filter key for an expense with no program. */
    public const NO_PROGRAM_SOURCE = 'none';

    public const MONTH_NAMES = [
        1 => 'ינואר', 2 => 'פברואר', 3 => 'מרץ', 4 => 'אפריל', 5 => 'מאי', 6 => 'יוני',
        7 => 'יולי', 8 => 'אוגוסט', 9 => 'ספטמבר', 10 => 'אוקטובר', 11 => 'נובמבר', 12 => 'דצמבר',
    ];

    /**
     * The income statement on ⚡cashflow-report.blade.php and its PDF: income
     * by program/bundle on top, expenses by supplier below, and net profit — over the same recognized-revenue rules as
     * the rest of this class (FR-6.4 subscription spreading included).
     *
     * Each filter is optional and they combine: $years (e.g. [2026]), $months
     * (1-12, in any of those years — so [9, 10] over [2025, 2026] is four
     * months), $sources (SOURCE keys from sourceOptions(): 'program:{id}',
     * 'bundle:{id}', or NO_PROGRAM_SOURCE for expenses not tied to a program).
     * An empty filter means "all".
     *
     * Each income/expense line also carries its count and its items (date,
     * description, amount) — the drill-down rows on the screen.
     *
     * @return array{revenue: list<array{label: string, amount: float, count: int, items: list<array>}>, revenue_total: float, expenses: list<array{label: string, amount: float, count: int, items: list<array>}>, expense_total: float, profit: float, period: string}
     */
    public function statement(array $years = [], array $months = [], array $sources = []): array
    {
        $matches = function (array $entry) use ($years, $months, $sources): bool {
            [$year, $month] = array_map('intval', explode('-', $entry['month']));

            return (! $years || in_array($year, array_map('intval', $years), true))
                && (! $months || in_array($month, array_map('intval', $months), true))
                && (! $sources || in_array($entry['source'], $sources, true));
        };

        $revenue = $this->revenueEntries()->filter($matches);
        $expenses = $this->expenseEntries()->filter($matches);

        $items = fn (Collection $rows) => $rows->sortBy('date')->map(fn (array $row) => [
            'date' => CarbonImmutable::parse($row['date'])->format('d/m/Y'),
            'description' => $row['description'],
            'amount' => round($row['amount'], 2),
        ])->values()->all();

        $revenueLines = $revenue->groupBy('label')
            ->map(fn (Collection $rows, string $label) => ['label' => $label, 'amount' => round($rows->sum('amount'), 2), 'count' => $rows->count(), 'items' => $items($rows)])
            ->sortByDesc('amount')->values()->all();
        $expenseLines = $expenses->groupBy('label')
            ->map(fn (Collection $rows, string $label) => ['label' => $label, 'amount' => round($rows->sum('amount'), 2), 'count' => $rows->count(), 'items' => $items($rows)])
            ->sortByDesc('amount')->values()->all();

        $revenueTotal = round($revenue->sum('amount'), 2);
        $expenseTotal = round($expenses->sum('amount'), 2);

        return [
            'revenue' => $revenueLines,
            'revenue_total' => $revenueTotal,
            'expenses' => $expenseLines,
            'expense_total' => $expenseTotal,
            'profit' => round($revenueTotal - $expenseTotal, 2),
            'period' => $this->periodDescription($years, $months, $sources),
        ];
    }

    public const PDF_DETAILS = ['summary' => 'מקוצר', 'full' => 'מפורט'];

    /**
     * statement() as a printable A4 PDF (reports/statement-pdf.blade.php) —
     * the raw bytes. $detail: 'summary' (totals only) or 'full' (every
     * program/supplier and its individual payments/expenses).
     */
    public function statementPdf(array $years = [], array $months = [], array $sources = [], string $detail = 'full'): string
    {
        $mpdf = new \Mpdf\Mpdf([
            'default_font' => 'dejavusans', 'directionality' => 'rtl', 'format' => 'A4',
            'margin_top' => 16, 'margin_bottom' => 18, 'margin_left' => 14, 'margin_right' => 14,
        ]);
        $mpdf->SetTitle('דוח הכנסות והוצאות');
        $mpdf->SetHTMLFooter('<div style="font-family:dejavusans; font-size:9px; color:#5C6B5F; text-align:center; direction:rtl">כפיים · עמוד {PAGENO} מתוך {nbpg}</div>');

        // Same benign-warning suppression as Document::renderPdfBinary().
        $previousErrorReporting = error_reporting(E_ERROR | E_PARSE | E_COMPILE_ERROR | E_CORE_ERROR);

        try {
            $mpdf->WriteHTML(view('reports.statement-pdf', [
                'statement' => $this->statement($years, $months, $sources),
                'detail' => array_key_exists($detail, self::PDF_DETAILS) ? $detail : 'full',
            ])->render());

            return $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
        } finally {
            error_reporting($previousErrorReporting);
        }
    }

    /** @return array<string, string> statement() source key => label: every program, every bundle, and program-less expenses. */
    public function sourceOptions(): array
    {
        $options = [];
        foreach (Program::orderBy('name')->get() as $program) {
            $options["program:{$program->id}"] = $program->name;
        }
        foreach (Bundle::orderBy('name')->get() as $bundle) {
            $options["bundle:{$bundle->id}"] = $bundle->name;
        }
        $options[self::NO_PROGRAM_SOURCE] = 'הוצאות כלליות (ללא תוכנית)';

        return $options;
    }

    /** @return list<int> every year with income or expenses, plus the current one, newest first. */
    public function availableYears(): array
    {
        return $this->revenueEntries()->merge($this->expenseEntries())
            ->map(fn (array $entry) => (int) substr($entry['month'], 0, 4))
            ->push((int) now()->format('Y'))
            ->unique()->sortDesc()->values()->all();
    }

    /** @return Collection<int, array{month: string, amount: float, source: string, label: string}> one row per non-subscription payment and per subscription month-slice */
    private function revenueEntries(): Collection
    {
        $entries = $this->nonSubscriptionPayments()->toBase()->map(fn (Payment $payment) => [
            'month' => CarbonImmutable::parse($this->incomeDate($payment))->format('Y-m'),
            'date' => CarbonImmutable::parse($this->incomeDate($payment))->toDateString(),
            'amount' => (float) $payment->amount,
            'description' => "תשלום · {$this->customerName($payment->deal)}",
        ] + $this->revenueSource($payment->deal));

        $this->subscriptions()->each(function (Subscription $subscription) use (&$entries) {
            foreach ($this->subscriptionMonthlySlices($subscription) as $slice) {
                $entries->push([
                    'month' => $slice['date']->format('Y-m'),
                    'date' => $slice['date']->toDateString(),
                    'amount' => $slice['amount'],
                    'description' => ($slice['number']
                        ? "מנוי, חודש {$slice['number']} מתוך ".Subscription::TOTAL_DELIVERIES." ({$slice['label']})"
                        : 'התאמה לסכום הזיכוי בביטול המנוי').' · '.$this->customerName($subscription->deal),
                ] + $this->revenueSource($subscription->deal));
            }
        });

        return $entries->values();
    }

    /** @return Collection<int, array{month: string, amount: float, source: string, label: string}> one row per expense, labelled by supplier */
    private function expenseEntries(): Collection
    {
        return Expense::with('supplier')->get()->toBase()->map(fn (Expense $expense) => [
            'month' => CarbonImmutable::parse($expense->expense_date)->format('Y-m'),
            'date' => CarbonImmutable::parse($expense->expense_date)->toDateString(),
            'amount' => (float) $expense->amount,
            'description' => $expense->notes ?: 'הוצאה #'.$expense->id,
            'source' => $expense->program_id ? "program:{$expense->program_id}" : self::NO_PROGRAM_SOURCE,
            'label' => $expense->supplier?->name ?? 'ספק לא ידוע',
        ]);
    }

    private function customerName(?Deal $deal): string
    {
        return $deal?->customer?->school?->name ?? 'עסקה #'.$deal?->id;
    }

    /** @return array{source: string, label: string} what a deal's income is filed under — its program, else its bundle. */
    private function revenueSource(Deal $deal): array
    {
        if ($deal->program) {
            return ['source' => "program:{$deal->program_id}", 'label' => $deal->program->name];
        }

        return ['source' => 'bundle:'.(int) $deal->bundle_id, 'label' => $deal->bundle?->name ?? self::BUNDLE_BUCKET_LABEL];
    }

    private function periodDescription(array $years, array $months, array $sources): string
    {
        $parts = [];
        $parts[] = $years ? 'שנים: '.collect($years)->map(fn ($y) => (int) $y)->sort()->implode(', ') : 'כל השנים';
        if ($months) {
            $parts[] = 'חודשים: '.collect($months)->map(fn ($m) => (int) $m)->sort()->map(fn ($m) => self::MONTH_NAMES[$m] ?? $m)->implode(', ');
        }
        if ($sources) {
            $labels = $this->sourceOptions();
            $parts[] = 'תוכניות: '.collect($sources)->map(fn ($key) => $labels[$key] ?? $key)->implode(', ');
        }

        return implode(' · ', $parts);
    }

    /** @return array<string, float> 'Y-m' => recognized revenue, sorted ascending */
    public function revenueByMonth(): array
    {
        $revenue = [];

        $this->nonSubscriptionPayments()->each(function (Payment $payment) use (&$revenue) {
            $month = CarbonImmutable::parse($this->incomeDate($payment))->format('Y-m');
            $revenue[$month] = ($revenue[$month] ?? 0) + (float) $payment->amount;
        });

        $this->subscriptions()->each(function (Subscription $subscription) use (&$revenue) {
            foreach ($this->subscriptionMonthlySlices($subscription) as $slice) {
                $month = $slice['date']->format('Y-m');
                $revenue[$month] = ($revenue[$month] ?? 0) + $slice['amount'];
            }
        });

        ksort($revenue);

        return $revenue;
    }

    /** @return array<string, float> program/bucket label => recognized revenue */
    public function revenueByProgram(): array
    {
        $revenue = [];

        $this->nonSubscriptionPayments()->each(function (Payment $payment) use (&$revenue) {
            $label = $this->programLabelForDeal($payment->deal);
            $revenue[$label] = ($revenue[$label] ?? 0) + (float) $payment->amount;
        });

        $this->subscriptions()->each(function (Subscription $subscription) use (&$revenue) {
            $label = $subscription->deal->program?->name ?? $subscription->deal->bundle?->name ?? self::BUNDLE_BUCKET_LABEL;
            $revenue[$label] = ($revenue[$label] ?? 0) + array_sum(array_column($this->subscriptionMonthlySlices($subscription), 'amount'));
        });

        return $revenue;
    }

    /** @return array<string, float> 'Y-m' => revenue minus every expense incurred that month, sorted ascending */
    public function profitByMonth(): array
    {
        $revenue = $this->revenueByMonth();
        $expenses = $this->expensesByMonth();

        $months = array_unique(array_merge(array_keys($revenue), array_keys($expenses)));
        sort($months);

        $profit = [];
        foreach ($months as $month) {
            $profit[$month] = ($revenue[$month] ?? 0) - ($expenses[$month] ?? 0);
        }

        return $profit;
    }

    /** @return array<string, float> program/bucket label => revenue minus program-attributed expenses */
    public function profitByProgram(): array
    {
        $revenue = $this->revenueByProgram();
        $expenses = $this->expensesByProgram();

        $labels = array_unique(array_merge(array_keys($revenue), array_keys($expenses)));

        $profit = [];
        foreach ($labels as $label) {
            $profit[$label] = ($revenue[$label] ?? 0) - ($expenses[$label] ?? 0);
        }

        return $profit;
    }

    /** @return array<string, float> 'Y-m' => total expense amount incurred that month (every expense, program-attributed or not — FR-6.13's month-level side of this stage's judgment call) */
    private function expensesByMonth(): array
    {
        $expenses = [];

        Expense::query()->get()->each(function (Expense $expense) use (&$expenses) {
            $month = CarbonImmutable::parse($expense->expense_date)->format('Y-m');
            $expenses[$month] = ($expenses[$month] ?? 0) + (float) $expense->amount;
        });

        return $expenses;
    }

    /** @return array<string, float> program name => total expense amount attributed to it (program-less expenses excluded — see class docblock) */
    private function expensesByProgram(): array
    {
        $expenses = [];

        Expense::with('program')->whereNotNull('program_id')->get()->each(function (Expense $expense) use (&$expenses) {
            $label = $expense->program->name;
            $expenses[$label] = ($expenses[$label] ?? 0) + (float) $expense->amount;
        });

        return $expenses;
    }

    /** Every real Payment whose deal is NOT against the subscription-type bundle — those are handled by subscriptionMonthlySlices() instead. */
    /**
     * A check is income only once it cleared — received but not yet cleared
     * it isn't (the business owner's call, 2026-10-18) — and then in the
     * month it cleared.
     */
    private function nonSubscriptionPayments(): Collection
    {
        return Payment::with('paymentMethod', 'deal.bundle', 'deal.program', 'deal.customer.school')->get()
            ->filter(fn (Payment $payment) => $payment->deal && ! $payment->deal->bundle?->is_subscription_type)
            ->reject(fn (Payment $payment) => $payment->isCheck() && ! $payment->cleared_date);
    }

    private function incomeDate(Payment $payment): \DateTimeInterface|string
    {
        return $payment->isCheck() ? $payment->cleared_date : $payment->payment_date;
    }

    private function subscriptions(): Collection
    {
        return Subscription::with('deal.program', 'deal.bundle', 'deal.customer.school')->get();
    }

    /**
     * FR-6.4: agreed_price split evenly over the subscription's months
     * (Subscription::TOTAL_DELIVERIES), each dated at the start of its Hebrew
     * month — the same months the subscription itself counts
     * (Subscription::monthLabel()). The last slice absorbs any rounding
     * remainder so the slices always add up to the exact price.
     *
     * A cancelled subscription keeps only the months before
     * cancelled_from_month. Its credit can be set by hand on cancellation, so
     * whatever the kept months don't account for — price minus the actual
     * credit, minus the kept slices — is one adjustment slice (number null)
     * on the cancellation date, so recognized revenue always equals the
     * price less the credit note.
     *
     * @return list<array{number: ?int, date: CarbonImmutable, label: string, amount: float}>
     */
    private function subscriptionMonthlySlices(Subscription $subscription): array
    {
        $months = Subscription::TOTAL_DELIVERIES;
        $price = (float) $subscription->agreed_price;
        $monthly = round($price / $months, 2);
        $first = $subscription->firstMonthDate();

        $slices = [];
        for ($i = 0; $i < $months; $i++) {
            $date = HebrewCalendar::addMonths($first, $i);
            $slices[] = [
                'number' => $i + 1,
                'date' => $date,
                'label' => HebrewCalendar::monthLabel($date),
                'amount' => $i === $months - 1 ? round($price - $monthly * ($months - 1), 2) : $monthly,
            ];
        }

        if ($subscription->cancelled_from_month === null) {
            return $slices;
        }

        $kept = array_values(array_filter($slices, fn (array $slice) => $slice['number'] < $subscription->cancelled_from_month));
        $adjustment = round($price - (float) $subscription->cancellation_credit - array_sum(array_column($kept, 'amount')), 2);

        if (abs($adjustment) > 0.005) {
            $date = CarbonImmutable::parse(($subscription->cancelled_at ?? now())->toDateString());
            $kept[] = ['number' => null, 'date' => $date, 'label' => HebrewCalendar::monthLabel($date), 'amount' => $adjustment];
        }

        return $kept;
    }

    private function programLabelForDeal(Deal $deal): string
    {
        return $deal->program?->name ?? self::BUNDLE_BUCKET_LABEL;
    }
}

