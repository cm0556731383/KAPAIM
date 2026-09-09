<?php

namespace App\Console\Commands;

use App\Models\Deal;
use App\Models\StatusDefinition;
use App\Models\Task;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Build-plan 08 — FR-4.35/FR-4.36/FR-4.37: the collections-automation "most
 * critical business core" per the build plan. Registered in routes/console.php
 * via Schedule::command(...)->daily(). Deliberately does NOT send any
 * email/notification anywhere in this file (FR-4.37: internal only) — a
 * collection task is a plain Task row (task_type = 'collection') that shows
 * up on ⚡collections.blade.php and on the deal's own card.
 *
 * Rules implemented, run once a day:
 *  - FR-4.35: for a deal whose status is "נשלחה חשבונית" and that is not yet
 *    fully paid, open a collection task once that status has held for more
 *    than a day and no open collection task already exists for the deal.
 *    "Held for more than a day" is approximated here by deals.updated_at —
 *    the same column updateStatusWithLock() touches on every status change
 *    — since this stage does not add a dedicated status-changed-at column;
 *    see this stage's report for that judgment call.
 *  - FR-4.36: the task recurs weekly while the deal stays unpaid — an open
 *    collection task older than 7 days is closed out and a fresh one opened
 *    (never silently mutated in place, matching this codebase's convention
 *    of never editing a business record's history after the fact).
 *  - Once a deal reaches "שולמה", any open collection task for it is closed
 *    out here — no stale open collection tasks are left lying around.
 */
class ProcessCollectionTasks extends Command
{
    public const TASK_TYPE = 'collection';

    protected $signature = 'collections:process';

    protected $description = 'Create/renew/close weekly collection tasks for unpaid invoiced deals (FR-4.35-FR-4.37)';

    public function handle(): int
    {
        $this->closeTasksForPaidDeals();
        $this->createOrRenewTasksForUnpaidInvoicedDeals();

        return self::SUCCESS;
    }

    private function closeTasksForPaidDeals(): void
    {
        $paidStatus = StatusDefinition::where('scope', 'deal')->where('name', Deal::PAID_STATUS_NAME)->first();

        if (! $paidStatus) {
            return;
        }

        Task::where('task_type', self::TASK_TYPE)
            ->where('status', 'open')
            ->whereHas('deal', fn ($q) => $q->where('status_id', $paidStatus->id))
            ->get()
            ->each(fn (Task $task) => $task->update(['status' => 'done', 'completed_at' => now()]));
    }

    private function createOrRenewTasksForUnpaidInvoicedDeals(): void
    {
        $invoiceSentStatus = StatusDefinition::where('scope', 'deal')->where('name', 'נשלחה חשבונית')->first();

        if (! $invoiceSentStatus) {
            return;
        }

        Deal::where('status_id', $invoiceSentStatus->id)
            ->with(['customer.lead', 'payments', 'status'])
            ->get()
            ->filter(fn (Deal $deal) => ! $deal->isFullyPaid())
            ->each(fn (Deal $deal) => $this->processDeal($deal));
    }

    private function processDeal(Deal $deal): void
    {
        $openTask = Task::where('task_type', self::TASK_TYPE)
            ->where('deal_id', $deal->id)
            ->where('status', 'open')
            ->latest('id')
            ->first();

        if ($openTask) {
            // FR-4.36: weekly recurrence — close the old task out and open a
            // fresh one once it's at least 7 days old, rather than mutating
            // it in place.
            if ($openTask->created_at->lte(now()->subDays(7))) {
                $openTask->update(['status' => 'done', 'completed_at' => now()]);
                $this->createTask($deal);
            }

            return;
        }

        // FR-4.35: the very first task appears one day after the deal
        // reached "נשלחה חשבונית" — not immediately.
        if ($deal->updated_at->lte(now()->subDay())) {
            $this->createTask($deal);
        }
    }

    private function createTask(Deal $deal): void
    {
        $userId = $deal->customer?->lead?->assigned_user_id ?? User::query()->orderBy('id')->value('id');

        if (! $userId) {
            return;
        }

        Task::create([
            'user_id' => $userId,
            'customer_id' => $deal->customer_id,
            'deal_id' => $deal->id,
            'task_type' => self::TASK_TYPE,
            'status' => 'open',
            'title' => 'משימת גבייה — '.($deal->program_name_snapshot ?? $deal->bundle_name_snapshot),
            'description' => 'העסקה נשלחה לחיוב וטרם שולמה במלואה. משימה פנימית בלבד — אין לשלוח הודעה אוטומטית ללקוחה (FR-4.37).',
            'due_at' => now()->addDays(7),
        ]);
    }
}
