<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

/**
 * Central sink every module streams events into (build-plan 01). Call via
 * app(ActivityLogger::class)->log(...) — see docs/build-plan/01-core-users-roles-activity-log.md.
 */
class ActivityLogger
{
    private const RELATED_ENTITY_KEYS = [
        'school_id', 'lead_id', 'customer_id', 'deal_id', 'subscription_id',
        'material_delivery_id', 'task_id', 'document_id', 'expense_id', 'external_operation_id',
    ];

    /**
     * @param  array<string, mixed>  $context  Optional keys: metadata (array), occurred_at
     *                                         (Carbon|string), user (User|null — defaults to
     *                                         the authenticated user; pass null explicitly for
     *                                         a system/integration-triggered event), plus any of
     *                                         self::RELATED_ENTITY_KEYS to link the entry.
     */
    public function log(string $activityType, string $description, array $context = []): ActivityLog
    {
        $user = array_key_exists('user', $context)
            ? $context['user']
            : auth()->user();

        return ActivityLog::create(array_merge(
            [
                'activity_type' => $activityType,
                'description' => $description,
                'metadata' => $context['metadata'] ?? null,
                'occurred_at' => $context['occurred_at'] ?? Carbon::now(),
                'user_id' => $user instanceof User ? $user->id : null,
            ],
            Arr::only($context, self::RELATED_ENTITY_KEYS),
        ));
    }
}
