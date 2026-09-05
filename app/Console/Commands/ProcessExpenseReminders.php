<?php

namespace App\Console\Commands;

use App\Models\Expense;
use App\Services\ActivityLogger;
use App\Services\Integrations\ExternalOperationRunner;
use App\Services\Integrations\SmoveClient;
use Illuminate\Console\Command;

/**
 * Build-plan 11/12 — FR-6.7-FR-6.9: mirrors ProcessMaterialReminders's shape
 * exactly (build-plan 10) and registration via Schedule::command(...)->daily()
 * in routes/console.php.
 *
 * The eligibility query (Expense::scopeMissingInvoiceForClosedMonth()) is
 * fully real and exercised by tests: an expense is only ever eligible once
 * its own expense_date's calendar month has fully closed, and never once a
 * document is attached to it (FR-6.10). Build-plan 12: sendReminder() now
 * really pushes the email to the supplier via SmoveClient, wrapped in
 * ExternalOperationRunner exactly like ProcessMaterialReminders — see that
 * class's docblock for why a failure here is recorded, not thrown.
 */
class ProcessExpenseReminders extends Command
{
    protected $signature = 'expenses:process-reminders';

    protected $description = 'Send a reminder to the supplier for expenses still missing an invoice once their month has closed (FR-6.7-FR-6.9)';

    public function handle(ActivityLogger $activityLogger, ExternalOperationRunner $runner, SmoveClient $smove): int
    {
        Expense::missingInvoiceForClosedMonth()
            ->with(['supplier', 'program'])
            ->get()
            ->each(fn (Expense $expense) => $this->sendReminder($expense, $activityLogger, $runner, $smove));

        return self::SUCCESS;
    }

    private function sendReminder(Expense $expense, ActivityLogger $activityLogger, ExternalOperationRunner $runner, SmoveClient $smove): void
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

        if (! $expense->supplier?->email) {
            return;
        }

        $runner->run(
            'smove',
            'expense_invoice_reminder',
            'scheduled_job',
            fn () => $smove->sendTransactionalEmail(
                $expense->supplier->email,
                $expense->supplier->name,
                'תזכורת: חשבונית חסרה',
                "שלום {$expense->supplier->name},\n\nחסרה לנו חשבונית עבור הוצאה בסך ₪{$expense->amount} מתאריך {$expense->expense_date->format('d/m/Y')}. נשמח לקבלה בהקדם.\n\nבברכה, כפיים",
            ),
            [
                'expense_id' => $expense->id,
                'user' => null,
                'description' => "שליחת תזכורת חשבונית חסרה לספק \"{$expense->supplier->name}\" (הוצאה #{$expense->id})",
            ],
        );
    }
}
