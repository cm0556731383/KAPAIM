<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Build-plan 08 (FR-4.35-FR-4.37) — collections automation, "the most
// critical business core" per the build plan: creates/renews/closes weekly
// collection tasks for unpaid invoiced deals. See
// App\Console\Commands\ProcessCollectionTasks.
Schedule::command('collections:process')->daily();

// Build-plan 10 (FR-5.16/FR-5.17) — logs a (stubbed) reminder for material
// deliveries not opened within the configured window. See
// App\Console\Commands\ProcessMaterialReminders.
Schedule::command('materials:process-reminders')->daily();

// Build-plan 11 (FR-6.7-FR-6.9) — logs an internal reminder (never emails the
// supplier) for expenses still missing an invoice once their expense_date's
// calendar month has fully closed. See App\Console\Commands\ProcessExpenseReminders.
Schedule::command('expenses:process-reminders')->daily();

// A subscription ends once its 10 Hebrew months are over, even if not every
// month was marked supplied. See App\Console\Commands\EndExpiredSubscriptions.
Schedule::command('subscriptions:end-expired')->daily();

// Records payments taken in SUMIT that the app hasn't seen yet (a paid
// payment page whose customer never came back to the return page). SUMIT
// has no payment webhook, so it's polled — twice a day, at the business
// owner's request (each SUMIT API call may be billed). The command reads 3
// days back, so a missed run is caught up. See App\Console\Commands\SyncSumitPayments.
Schedule::command('sumit:sync-payments')->twiceDaily(8, 20)->withoutOverlapping();
