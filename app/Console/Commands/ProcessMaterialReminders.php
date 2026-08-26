<?php

namespace App\Console\Commands;

use App\Models\ExternalIntegrationSetting;
use App\Models\MaterialDelivery;
use App\Services\ActivityLogger;
use Illuminate\Console\Command;

/**
 * Build-plan 10 — FR-5.16/FR-5.17: mirrors ProcessCollectionTasks's shape
 * (build-plan 08) and registration via Schedule::command(...)->daily() in
 * routes/console.php.
 *
 * The reminder WINDOW is configurable via ExternalIntegrationSetting
 * (system = 'smove', settings['material_reminder_hours']) rather than a new
 * generic settings table (build-plan 10's own instruction) — falls back to
 * DEFAULT_REMINDER_HOURS when unset.
 *
 * The eligibility query (MaterialDelivery::overdueForReminder()) is fully
 * real and exercised by tests. Actually SENDING the reminder is itself an
 * outbound Smove email — exactly like every other Smove-dependent action in
 * this stage, that half is a deliberate stub (sendReminder() below never
 * calls Mail::send() or any HTTP client) until build-plan 12 wires Smove up
 * for real.
 */
class ProcessMaterialReminders extends Command
{
    public const DEFAULT_REMINDER_HOURS = 48;

    protected $signature = 'materials:process-reminders';

    protected $description = 'Log a (stubbed) reminder for material deliveries not opened within the configured window (FR-5.16/FR-5.17)';

    public function handle(ActivityLogger $activityLogger): int
    {
        $hours = $this->reminderWindowHours();

        MaterialDelivery::overdueForReminder($hours)
            ->with(['customer.school', 'program'])
            ->get()
            ->each(fn (MaterialDelivery $delivery) => $this->sendReminder($delivery, $activityLogger));

        return self::SUCCESS;
    }

    private function reminderWindowHours(): int
    {
        $settings = ExternalIntegrationSetting::where('system', 'smove')->first()?->settings ?? [];

        return (int) ($settings['material_reminder_hours'] ?? self::DEFAULT_REMINDER_HOURS);
    }

    /**
     * TODO(stage 12 — Smove): send the actual reminder email via Smove here.
     * Deliberately does nothing else beyond logging that a reminder was due
     * — no Mail::send()/HTTP call anywhere in this method.
     */
    private function sendReminder(MaterialDelivery $delivery, ActivityLogger $activityLogger): void
    {
        $activityLogger->log(
            'material_delivery.reminder_due',
            "תזכורת: חומרי הלימוד \"{$delivery->program?->name}\" למשלוח #{$delivery->id} עדיין לא נפתחו",
            [
                'customer_id' => $delivery->customer_id,
                'material_delivery_id' => $delivery->id,
                'user' => null,
            ],
        );
    }
}
