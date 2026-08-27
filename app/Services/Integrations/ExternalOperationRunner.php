<?php

namespace App\Services\Integrations;

use App\Models\ExternalIntegrationSetting;
use App\Models\ExternalOperation;
use App\Models\User;
use App\Services\ActivityLogger;
use Closure;
use Throwable;

/**
 * Build-plan 12 — the single place every real Smove/Summit (and landing-page
 * webhook) attempt is recorded, per docs/erd.md's EXTERNAL_OPERATION ||--o{
 * ACTIVITY_LOG relationship and FR-8.16-FR-8.18: an EXTERNAL_OPERATION row is
 * written *before* $operation runs (status=pending) and updated once it
 * finishes — success with its external_reference, or failure with
 * error_message — and either outcome is always also logged to ACTIVITY_LOG,
 * linked back via external_operation_id.
 *
 * Deliberately never lets $operation's exception propagate: a failed
 * external call must never roll back or block the local business action that
 * triggered it (FR-8.17 — "לא מוצג כאילו הושלמה בהצלחה", not "המערכת
 * נתקעת") — the system is the source of truth (1.5), the external system is
 * always downstream of it. The caller inspects the returned ExternalOperation
 * (succeeded()/failed()) to decide whether to also notify the user (e.g. a
 * synchronous, user-triggered action like "סליקת אשראי") or to just let the
 * activity log carry it (a background push like a mailing-list sync).
 */
class ExternalOperationRunner
{
    public function __construct(private ActivityLogger $activityLogger) {}

    /**
     * @param  Closure(): string  $operation  Returns the external_reference on
     *                                        success; any thrown Throwable is
     *                                        caught and recorded as a failure.
     * @param  array<string, mixed>  $context  Optional: document_id/expense_id/
     *                                         material_delivery_id/lead_id/deal_id/
     *                                         payment_id (linked on both rows),
     *                                         user (User|null — defaults to the
     *                                         authenticated user, pass null
     *                                         explicitly for a webhook/console
     *                                         trigger), description (activity-log
     *                                         text — a sensible default is used
     *                                         when omitted).
     */
    public function run(string $system, string $operationType, string $triggerSource, Closure $operation, array $context = []): ExternalOperation
    {
        $entityKeys = ['document_id', 'expense_id', 'material_delivery_id', 'lead_id', 'deal_id', 'payment_id'];

        $user = array_key_exists('user', $context) ? $context['user'] : auth()->user();

        $record = ExternalOperation::create(array_merge(
            array_intersect_key($context, array_flip($entityKeys)),
            [
                'integration_setting_id' => ExternalIntegrationSetting::where('system', $system)->value('id'),
                'triggered_by_user_id' => $user instanceof User ? $user->id : null,
                'system' => $system,
                'operation_type' => $operationType,
                'trigger_source' => $triggerSource,
                'status' => ExternalOperation::STATUS_PENDING,
                'attempted_at' => now(),
            ],
        ));

        $description = $context['description'] ?? "פעולת {$operationType} מול {$system}";

        try {
            $reference = $operation();

            $record->update([
                'status' => ExternalOperation::STATUS_SUCCESS,
                'external_reference' => $reference,
                'completed_at' => now(),
            ]);

            $this->activityLogger->log(
                "{$system}.{$operationType}.succeeded",
                "{$description} — הושלמה בהצלחה",
                $this->logContext($record, $context, $user),
            );
        } catch (Throwable $e) {
            $record->update([
                'status' => ExternalOperation::STATUS_FAILED,
                'error_message' => $e->getMessage(),
                'completed_at' => now(),
            ]);

            $this->activityLogger->log(
                "{$system}.{$operationType}.failed",
                "{$description} — נכשלה: {$e->getMessage()}",
                $this->logContext($record, $context, $user),
            );
        }

        return $record->refresh();
    }

    private function logContext(ExternalOperation $record, array $context, mixed $user): array
    {
        $entityKeys = ['school_id', 'lead_id', 'customer_id', 'deal_id', 'subscription_id',
            'material_delivery_id', 'task_id', 'document_id', 'expense_id'];

        return array_merge(
            array_intersect_key($context, array_flip($entityKeys)),
            ['external_operation_id' => $record->id, 'user' => $user],
        );
    }
}
