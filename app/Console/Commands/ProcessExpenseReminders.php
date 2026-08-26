<?php

namespace App\Console\Commands;

use App\Models\Expense;
use App\Services\ActivityLogger;
use Illuminate\Console\Command;

/**
 * Build-plan 11 — FR-6.7-FR-6.9: mirrors ProcessMaterialReminders's shape
 * exactly (build-plan 10) and registration via Schedule::command(...)->daily()
 * in routes/console.php.
 *
 * The eligibility query (Expense::scopeMissingInvoiceForClosedMonth()) is
 * fully real and exercised by tests: an expense is only ever eligible once
 * its own expense_date's calendar month has fully closed, and never once a
 * document is attached to it (FR-6.10). Actually SENDING the reminder to the
 * supplier is itself an outbound Smove email — exactly like every other
 * Smove-dependent action in this codebase, that half is a deliberate stub
 * (sendReminder() below never calls Mail::send() or any HTTP client) until
 * build-plan 12 wires Smove up for real.
 */
class ProcessExpenseReminders extends Command
{
    protected $signature = 'expenses:process-reminders';

    protected $description = 'Log a (stubbed) reminder to the supplier for expenses still missing an invoice once their month has closed (FR-6.7-FR-6.9)';

    public function handle(ActivityLogger $activityLogger): int
    {
        Expense::missingInvoiceForClosedMonth()
            ->with(['supplier', 'program'])
            ->get()
            ->each(fn (Expense $expense) => $this->sendReminder($expense, $activityLogger));

        return self::SUCCESS;
    }

    /**
     * TODO(stage 12 — Smove): send the actual reminder email to the supplier
     * via Smove here. Deliberately does nothing else beyond logging that a
     * reminder was due — no Mail::send()/HTTP call anywhere in this method.
     */
    private function sendReminder(Expense $expense, ActivityLogger $activityLogger): void
    {
        $activityLogger->log(
            'expense.invoice_reminder_due',
            "תזכורת: חסרה חשבונית מהספק \"{$expense->supplier?->name}\" עבור הוצאה #{$expense->id} בסך ₪{$expense->amount}",
            [
                'expense_id' => $expense->id,
                'metadata' => ['supplier_id' => $expense->supplier_id, 'amount' => (float) $expense->amount],
                'user' => null,
            ],
        );
    }
}
