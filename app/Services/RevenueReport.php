<?php

namespace App\Services;

use App\Models\Deal;
use App\Models\Expense;
use App\Models\Payment;
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
 * is_subscription_type program does NOT recognize its revenue in the
 * month(s) its Payment rows actually landed. Every such deal has exactly
 * one Subscription row (Deal::openSubscriptionIfApplicable(), build-plan
 * 09) — that subscription's agreed_price is instead spread evenly
 * (agreed_price / 12) across the 12 calendar months starting at
 * Subscription.start_date, independent of the deal's real payment history.
 * This is deliberately independent of whether the subscription was later
 * cancelled too — recognized revenue reflects the ORIGINAL sale schedule,
 * the same way Subscription::cancel() never rewrites deal/payment history
 * either (this stage's judgment call — see this stage's report).
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

    /** @return array<string, float> 'Y-m' => recognized revenue, sorted ascending */
    public function revenueByMonth(): array
    {
        $revenue = [];

        $this->nonSubscriptionPayments()->each(function (Payment $payment) use (&$revenue) {
            $month = CarbonImmutable::parse($payment->payment_date)->format('Y-m');
            $revenue[$month] = ($revenue[$month] ?? 0) + (float) $payment->amount;
        });

        $this->subscriptions()->each(function (Subscription $subscription) use (&$revenue) {
            foreach ($this->subscriptionMonthlySlices($subscription) as $month => $amount) {
                $revenue[$month] = ($revenue[$month] ?? 0) + $amount;
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
            $label = $subscription->deal->program?->name ?? self::BUNDLE_BUCKET_LABEL;
            $revenue[$label] = ($revenue[$label] ?? 0) + (float) $subscription->agreed_price;
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

    /** Every real Payment whose deal is NOT against the subscription-type program — those are handled by subscriptionMonthlySlices() instead. */
    private function nonSubscriptionPayments(): Collection
    {
        return Payment::with('deal.program')->get()
            ->filter(fn (Payment $payment) => $payment->deal && ! $payment->deal->program?->is_subscription_type);
    }

    private function subscriptions(): Collection
    {
        return Subscription::with('deal.program')->get();
    }

    /** @return array<string, float> 'Y-m' => agreed_price/12 for each of the 12 months starting at start_date (FR-6.4) */
    private function subscriptionMonthlySlices(Subscription $subscription): array
    {
        $monthly = round((float) $subscription->agreed_price / 12, 2);
        $start = CarbonImmutable::parse($subscription->start_date)->startOfMonth();

        $slices = [];
        for ($i = 0; $i < 12; $i++) {
            $slices[$start->addMonths($i)->format('Y-m')] = $monthly;
        }

        return $slices;
    }

    private function programLabelForDeal(Deal $deal): string
    {
        return $deal->program?->name ?? self::BUNDLE_BUCKET_LABEL;
    }
}
