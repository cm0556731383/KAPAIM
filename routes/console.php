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
