<?php

namespace App\Console\Commands;

use App\Models\ExternalIntegrationSetting;
use App\Models\MaterialDelivery;
use App\Services\ActivityLogger;
use App\Services\Integrations\ExternalOperationRunner;
use App\Services\Integrations\SmoveClient;
use Illuminate\Console\Command;

/**
 * Build-plan 10/12 — FR-5.16/FR-5.17: mirrors ProcessCollectionTasks's shape
 * (build-plan 08) and registration via Schedule::command(...)->daily() in
 * routes/console.php.
 *
 * The reminder WINDOW is configurable via ExternalIntegrationSetting
 * (system = 'smove', settings['material_reminder_hours']) rather than a new
 * generic settings table (build-plan 10's own instruction) — falls back to
 * DEFAULT_REMINDER_HOURS when unset.
 *
 * The eligibility query (MaterialDelivery::overdueForReminder()) is fully
 * real and exercised by tests. Build-plan 12: sendReminder() now really does
 * push the email via SmoveClient, wrapped in ExternalOperationRunner so a
 * failure (including Smove not being configured yet) is recorded and never
 * crashes the scheduled run — there's no live user session here to alert
 * synchronously (FR-8.16), so the activity-log entry IS the alert.
 */
class ProcessMaterialReminders extends Command
{
    public const DEFAULT_REMINDER_HOURS = 48;

    protected $signature = 'materials:process-reminders';

    protected $description = 'Send a reminder for material deliveries not opened within the configured window (FR-5.16/FR-5.17)';

    public function handle(ActivityLogger $activityLogger, ExternalOperationRunner $runner, SmoveClient $smove): int
    {
        $hours = $this->reminderWindowHours();

        MaterialDelivery::overdueForReminder($hours)
            ->with(['customer.school', 'program'])
            ->get()
            ->each(fn (MaterialDelivery $delivery) => $this->sendReminder($delivery, $activityLogger, $runner, $smove));

        return self::SUCCESS;
    }

    private function reminderWindowHours(): int
    {
        $settings = ExternalIntegrationSetting::where('system', 'smove')->first()?->settings ?? [];

        return (int) ($settings['material_reminder_hours'] ?? self::DEFAULT_REMINDER_HOURS);
    }

    private function sendReminder(MaterialDelivery $delivery, ActivityLogger $activityLogger, ExternalOperationRunner $runner, SmoveClient $smove): void
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

        $recipientEmail = $delivery->recipients()->value('recipient_email');

        if (! $recipientEmail) {
            return;
        }

        $runner->run(
            'smove',
            'material_reminder',
            'scheduled_job',
            fn () => $smove->sendTransactionalEmail(
                $recipientEmail,
                $delivery->recipients()->value('recipient_name') ?? $recipientEmail,
                'תזכורת: חומרי לימוד ממתינים',
                "שלום,\n\nחומרי הלימוד \"{$delivery->program?->name}\" עדיין לא נפתחו. נשמח אם תאשרו קבלתם.\n\nבברכה, כפיים",
            ),
            [
                'material_delivery_id' => $delivery->id,
                'customer_id' => $delivery->customer_id,
                'user' => null,
                'description' => "שליחת תזכורת חומרי לימוד למשלוח #{$delivery->id}",
            ],
        );
    }
}
