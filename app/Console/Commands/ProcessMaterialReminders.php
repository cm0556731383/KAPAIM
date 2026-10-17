<?php

namespace App\Console\Commands;

use App\Models\EmailTemplate;
use App\Models\ExternalIntegrationSetting;
use App\Models\ExternalOperation;
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
 * real and exercised by tests. One reminder per delivery (reminder_sent_at),
 * in the editable material_reminder template. sendReminder() really does
 * push the email via SmoveClient, wrapped in ExternalOperationRunner so a
 * failure (including Smove not being configured yet) is recorded and never
 * crashes the scheduled run — there's no live user session here to alert
 * synchronously (FR-8.16), so the activity-log entry IS the alert.
 */
class ProcessMaterialReminders extends Command
{
    public const DEFAULT_REMINDER_HOURS = 48;

    protected $signature = 'materials:process-reminders';

    protected $description = 'Send the one reminder for material deliveries not confirmed within the configured window (FR-5.16/FR-5.17)';

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

    /**
     * One reminder per delivery, to every recipient with an email, in the
     * business owner's 'material_reminder' wording with the "received"
     * button. Marked sent only once Smove accepted it — a failed send is
     * retried on the next run. A disabled template sends nothing.
     */
    private function sendReminder(MaterialDelivery $delivery, ActivityLogger $activityLogger, ExternalOperationRunner $runner, SmoveClient $smove): void
    {
        $template = EmailTemplate::materialReminder();

        if (! $template?->is_active) {
            return;
        }

        $recipients = $delivery->recipients()->whereNotNull('recipient_email')->get()
            ->map(fn ($r) => ['email' => $r->recipient_email, 'name' => $r->recipient_name ?: $r->recipient_email])
            ->unique('email')->values()->all();

        $activityLogger->log('material_delivery.reminder_due', "תזכורת: חומרי הלימוד \"{$delivery->program?->name}\" למשלוח #{$delivery->id} עדיין לא אושרו", [
            'customer_id' => $delivery->customer_id,
            'material_delivery_id' => $delivery->id,
            'user' => null,
        ]);

        // Nobody to remind — nothing to retry either.
        if ($recipients === []) {
            $delivery->update(['reminder_sent_at' => now()]);

            return;
        }

        $email = $template->render([
            'school_name' => (string) $delivery->customer?->school?->name,
            'program_name' => (string) $delivery->program?->name,
        ]);

        $operation = $runner->run(
            'smove',
            'material_reminder',
            'scheduled_job',
            fn () => $smove->sendMaterialReminderEmail($recipients, $email['subject'], $email['content'], $delivery->acknowledgeUrl()),
            [
                'material_delivery_id' => $delivery->id,
                'customer_id' => $delivery->customer_id,
                'user' => null,
                'description' => "שליחת תזכורת על חומרי הלימוד \"{$delivery->program?->name}\" (משלוח #{$delivery->id}) ל-".count($recipients).' נמענים',
            ],
        );

        if ($operation->status === ExternalOperation::STATUS_SUCCESS) {
            $delivery->update(['reminder_sent_at' => now()]);
        }
    }
}
