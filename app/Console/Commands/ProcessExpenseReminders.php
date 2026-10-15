<?php

namespace App\Console\Commands;

use App\Models\Expense;
use App\Services\ActivityLogger;
use Illuminate\Console\Command;

/**
 * Build-plan 11 — FR-6.7-FR-6.9: registered via Schedule::command(...)->daily()
 * in routes/console.php.
 *
 * The eligibility query (Expense::scopeMissingInvoiceForClosedMonth()) is
 * fully real and exercised by tests: an expense is only ever eligible once
 * its own expense_date's calendar month has fully closed, and never once a
 * document is attached to it (FR-6.10).
 *
 * Internal only: it records a reminder in the activity log and never emails
 * the supplier (the business owner's 2026-10-16 instruction — suppliers were
 * getting the same email every day). Suppliers are asked from the expenses
 * page instead, per expense or all at once — Expense::requestInvoice() /
 * Expense::requestAllMissingInvoices().
 */
class ProcessExpenseReminders extends Command
{
    protected $signature = 'expenses:process-reminders';

    protected $description = 'Log a reminder for expenses still missing an invoice once their month has closed (FR-6.7-FR-6.9)';

    public function handle(ActivityLogger $activityLogger): int
    {
        Expense::missingInvoiceForClosedMonth()
            ->with('supplier')
            ->get()
            ->each(fn (Expense $expense) => $activityLogger->log(
                'expense.invoice_reminder_due',
                "תזכורת: חסרה חשבונית מהספק \"{$expense->supplier?->name}\" עבור הוצאה #{$expense->id} בסך ₪{$expense->amount}",
                [
                    'expense_id' => $expense->id,
                    'metadata' => ['supplier_id' => $expense->supplier_id, 'amount' => (float) $expense->amount],
                    'user' => null,
                ],
            ));

        return self::SUCCESS;
    }
}
