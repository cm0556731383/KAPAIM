<?php

namespace App\Services;

use App\Models\Deal;
use App\Models\ExternalOperation;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\MaterialDelivery;
use App\Models\Payment;
use App\Models\Program;
use App\Models\Receipt;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Build-plan 15 — US-019/FR-2.16. Home-screen aggregation over everything
 * built in stages 4-11: read-only, no new business rules, just the right
 * queries. Kept as a plain, unit-testable service (mirrors
 * App\Services\RevenueReport's style from stage 11) rather than folded into
 * ⚡home.blade.php's Livewire class.
 *
 * Every method takes the current User and applies build-plan 13/14's exact
 * permission scoping internally (FR-7.3): a `leads.view`-only user (future
 * "עובדת מכירות") gets numbers/items for their own not-yet-converted leads
 * only, and zero/empty for every financial/collections/materials section —
 * those stay visible only to `payments.manage`/`materials.manage`/
 * `customers.manage` holders (today's owner/secretary, via the wildcard
 * role). This mirrors ⚡leads.blade.php's leads() and
 * ⚡global-search.blade.php's results() scoping exactly, rather than
 * inventing a new pattern.
 *
 * Judgment call (see this stage's report): US-019 says "משימות ה-Up Follow"
 * — per build-plan 04's ERD that is specifically FOLLOW_UP.next_at, never
 * TASK rows. A personal reminder (TASK, task_type='reminder') is a distinct
 * concept also surfaced in attentionItems() below, but never counted by
 * followUpsDueTodayCount().
 */
class DashboardSummary
{
    /** FR-1.6 (build-plan 04): the only sub_status value meaning "ממתינה לשיחה חוזרת". */
    private const AWAITING_CALLBACK_SUB_STATUS = 'ממתינה לשיחה חוזרת';

    public function newLeadsCount(User $user): int
    {
        if (! $this->canSeeAnyLeads($user)) {
            return 0;
        }

        return $this->scopeLeadsToUser(
            Lead::whereHas('status', fn (Builder $q) => $q->where('name', Lead::NEW_STATUS_NAME)),
            $user,
        )->count();
    }

    public function awaitingCallbackCount(User $user): int
    {
        if (! $this->canSeeAnyLeads($user)) {
            return 0;
        }

        return $this->scopeLeadsToUser(
            Lead::where('sub_status', self::AWAITING_CALLBACK_SUB_STATUS),
            $user,
        )->count();
    }

    /**
     * US-019's "Up Follow להיום" — FOLLOW_UP.next_at falling today, not TASK
     * rows (see class docblock).
     */
    public function followUpsDueTodayCount(User $user): int
    {
        if (! $this->canSeeAnyLeads($user)) {
            return 0;
        }

        return $this->scopeFollowUpsToUser(
            FollowUp::whereDate('next_at', Carbon::today()),
            $user,
        )->count();
    }

    /**
     * FR-4.35-FR-4.37: only meaningful for a `payments.manage` holder — a
     * scoped-out user gets zeros, never a filtered/partial figure.
     *
     * @return array{amount: float, customerCount: int}
     */
    public function openCollectionsTotal(User $user): array
    {
        if (! $user->hasPermission('payments', 'manage')) {
            return ['amount' => 0.0, 'customerCount' => 0];
        }

        $deals = $this->dealsWithOpenCollectionTasks();

        return [
            'amount' => $deals->sum(fn (Deal $deal) => $deal->outstandingBalance()),
            'customerCount' => $deals->pluck('customer_id')->unique()->count(),
        ];
    }

    /**
     * FR-4.28/FR-4.29: a check payment not yet cleared still needs
     * depositing. Same query as ⚡collections.blade.php's pendingChecks().
     *
     * @return array{count: int, amount: float}
     */
    public function checksToDeposit(User $user): array
    {
        if (! $user->hasPermission('payments', 'manage')) {
            return ['count' => 0, 'amount' => 0.0];
        }

        $checks = Payment::whereHas('paymentMethod', fn (Builder $q) => $q->where('type', 'check'))
            ->whereNull('cleared_date')
            ->get();

        return [
            'count' => $checks->count(),
            'amount' => (float) $checks->sum('amount'),
        ];
    }

    /**
     * The merged "משימות ופריטי טיפול" list — every section below only
     * contributes when the user holds the permission that section's own
     * screen requires, and every query only ever selects still-open state
     * (FR-2.16's "פריט שהושלם נעלם מהרשימה" falls out naturally, never a
     * separate hide flag).
     */
    public function attentionItems(User $user): Collection
    {
        return collect()
            ->merge($this->upFollowItems($user))
            ->merge($this->personalTaskItems($user))
            ->merge($this->overdueCollectionItems($user))
            ->merge($this->awaitingReceiptItems($user))
            ->merge($this->materialsNotSentItems($user))
            ->merge($this->failedIntegrationItems($user))
            ->values();
    }

    /**
     * FR-2.16/US-019's last acceptance criterion: which schools bought the
     * most-recently-added catalog program vs. only expressed interest in it.
     * Each half is independently gated (customers.manage for "purchased",
     * leads.manage/leads.view for "interested", scoped to the user's own
     * leads for a view-only holder) — null when the user can see neither
     * half at all.
     *
     * @return array{program: Program, purchased: Collection, interested: Collection}|null
     */
    public function latestProgramSummary(User $user): ?array
    {
        $canSeeCustomers = $user->hasPermission('customers', 'manage');
        $canSeeAnyLeads = $this->canSeeAnyLeads($user);

        if (! $canSeeCustomers && ! $canSeeAnyLeads) {
            return null;
        }

        $program = Program::latest('id')->first();

        if (! $program) {
            return null;
        }

        $purchased = collect();

        if ($canSeeCustomers) {
            $purchased = Deal::where('program_id', $program->id)
                ->whereHas('status', fn (Builder $q) => $q->where('name', '!=', Deal::CANCELLED_STATUS_NAME))
                ->with('customer.school')
                ->get()
                ->map(fn (Deal $deal) => $deal->customer)
                ->filter()
                ->unique('id')
                ->values();
        }

        $purchasedSchoolIds = $purchased->pluck('school_id')->filter()->all();

        $interested = collect();

        if ($canSeeAnyLeads) {
            $interested = $this->scopeLeadsToUser(
                Lead::whereHas('interestedPrograms', fn (Builder $q) => $q->where('programs.id', $program->id))->with('school'),
                $user,
            )
                ->get()
                ->filter(fn (Lead $lead) => ! in_array($lead->school_id, $purchasedSchoolIds, true))
                ->unique('school_id')
                ->values();
        }

        return ['program' => $program, 'purchased' => $purchased, 'interested' => $interested];
    }

    // ----- attentionItems() sections -----

    /** Up Follow due today or overdue (FOLLOW_UP.next_at) — leads-permission scoped. */
    private function upFollowItems(User $user): Collection
    {
        if (! $this->canSeeAnyLeads($user)) {
            return collect();
        }

        return $this->scopeFollowUpsToUser(
            FollowUp::whereDate('next_at', '<=', Carbon::today())->with(['lead.school', 'lead.assignedUser']),
            $user,
        )
            ->get()
            ->filter(fn (FollowUp $followUp) => $followUp->lead !== null)
            ->map(function (FollowUp $followUp) {
                $lead = $followUp->lead;
                $badge = $this->badgeForDate($followUp->next_at);

                return [
                    'label' => 'לחזור ל'.($lead->school?->name ?? $lead->email).' — Up Follow',
                    'subtitle' => 'ליד · '.($lead->assignedUser?->name ?? '—'),
                    'badge' => $badge['badge'],
                    'badgeClass' => $badge['badgeClass'],
                    'url' => route('lead-detail', $lead),
                ];
            })
            ->values();
    }

    /**
     * Personal reminder TASK rows (task_type='reminder', build-plan 04's
     * FR-1.12) — always this user's own, and gated on the same leads
     * permission that lead-detail (where they're created) itself requires.
     */
    private function personalTaskItems(User $user): Collection
    {
        if (! $this->canSeeAnyLeads($user)) {
            return collect();
        }

        return Task::where('task_type', 'reminder')
            ->where('status', 'open')
            ->where('user_id', $user->id)
            ->whereNotNull('due_at')
            ->where('due_at', '<=', Carbon::tomorrow()->endOfDay())
            ->with('lead.school')
            ->get()
            ->map(function (Task $task) {
                $badge = $this->badgeForDate($task->due_at);

                return [
                    'label' => 'תזכורת אישית — '.$task->title,
                    'subtitle' => $task->lead ? 'ליד · '.($task->lead->school?->name ?? $user->name) : $user->name,
                    'badge' => $badge['badge'],
                    'badgeClass' => $badge['badgeClass'],
                    'url' => $task->lead ? route('lead-detail', $task->lead) : route('leads'),
                ];
            })
            ->values();
    }

    /** Overdue collection TASK rows (task_type='collection') — payments.manage gated. */
    private function overdueCollectionItems(User $user): Collection
    {
        if (! $user->hasPermission('payments', 'manage')) {
            return collect();
        }

        return Task::where('task_type', 'collection')
            ->where('status', 'open')
            ->whereNotNull('due_at')
            ->where('due_at', '<', Carbon::today())
            ->with('deal.customer.school')
            ->get()
            ->filter(fn (Task $task) => $task->deal !== null)
            ->map(fn (Task $task) => [
                'label' => ($task->deal->customer->school?->name ?? '—').' — נשלחה חשבונית, ממתינה לתשלום',
                'subtitle' => ($task->deal->program_name_snapshot ?? $task->deal->bundle_name_snapshot).' · משימת גבייה',
                'badge' => 'באיחור',
                'badgeClass' => 'badge-error',
                'url' => route('deal-detail', $task->deal),
            ])
            ->values();
    }

    /** A fully-paid deal whose invoice has no Receipt yet — payments.manage gated. */
    private function awaitingReceiptItems(User $user): Collection
    {
        if (! $user->hasPermission('payments', 'manage')) {
            return collect();
        }

        return $this->paidDeals()
            ->get()
            ->filter(function (Deal $deal) {
                $invoice = $deal->documents->firstWhere('document_type', 'invoice');

                return $invoice && ! Receipt::where('document_id', $invoice->id)->exists();
            })
            ->map(fn (Deal $deal) => [
                'label' => ($deal->customer->school?->name ?? '—').' — התקבל תשלום, ממתינה להפקת קבלה',
                'subtitle' => $deal->program_name_snapshot ?? $deal->bundle_name_snapshot,
                'badge' => 'לביצוע',
                'badgeClass' => 'badge-info',
                'url' => route('deal-detail', $deal),
            ])
            ->values();
    }

    /**
     * A fully-paid deal against a catalog program whose customer+program
     * combination has no MaterialDelivery row at all yet (never sent) —
     * distinct from MaterialDelivery::needingAttention(), which only covers
     * a delivery that WAS sent but nobody has acted on. materials.manage gated.
     */
    private function materialsNotSentItems(User $user): Collection
    {
        if (! $user->hasPermission('materials', 'manage')) {
            return collect();
        }

        return $this->paidDeals()
            ->whereNotNull('program_id')
            ->get()
            ->filter(fn (Deal $deal) => ! MaterialDelivery::where('customer_id', $deal->customer_id)
                ->where('program_id', $deal->program_id)
                ->exists())
            ->map(fn (Deal $deal) => [
                'label' => ($deal->customer->school?->name ?? '—').' — עסקה הושלמה, טרם נשלחו חומרים',
                'subtitle' => $deal->program_name_snapshot ?? $deal->bundle_name_snapshot,
                'badge' => 'לשליחה',
                'badgeClass' => 'badge-primary',
                'url' => route('customer-detail', $deal->customer),
            ])
            ->values();
    }

    /**
     * Build-plan 12 — FR-8.16's stand-in for every trigger_source that has
     * no live user session to toast synchronously (a scheduled_job/webhook
     * failure — an app_action failure already gets a same-request banner
     * from the component that triggered it, see e.g. ⚡deal-detail.blade.php's
     * issueReceipt()). Gated on settings.manage (today: owner/secretary) —
     * this is business-system health, not a sales-rep concern. A 3-day
     * window keeps this list from growing unbounded; a resolved-then-retried
     * failure still shows until it ages out, since there's no "dismiss" here.
     */
    private function failedIntegrationItems(User $user): Collection
    {
        if (! $user->hasPermission('settings', 'manage')) {
            return collect();
        }

        return ExternalOperation::where('status', ExternalOperation::STATUS_FAILED)
            ->where('trigger_source', '!=', 'app_action')
            ->where('completed_at', '>=', Carbon::now()->subDays(3))
            ->orderByDesc('completed_at')
            ->limit(10)
            ->get()
            ->map(fn (ExternalOperation $operation) => [
                'label' => "כשל אינטגרציה: {$operation->system} — {$operation->operation_type}",
                'subtitle' => Str::limit((string) $operation->error_message, 70) ?: '—',
                'badge' => 'כשל',
                'badgeClass' => 'badge-error',
                'url' => route('activity-log'),
            ])
            ->values();
    }

    // ----- shared helpers -----

    private function canSeeAnyLeads(User $user): bool
    {
        return $user->hasPermission('leads', 'manage') || $user->hasPermission('leads', 'view');
    }

    /** Build-plan 13 scoping (FR-7.2/FR-7.4) — identical to ⚡leads.blade.php's leads(). */
    private function scopeLeadsToUser(Builder $query, User $user): Builder
    {
        return $query->when(
            ! $user->hasPermission('leads', 'manage'),
            fn (Builder $q) => $q->where('assigned_user_id', $user->id)->whereNull('converted_at'),
        );
    }

    private function scopeFollowUpsToUser(Builder $query, User $user): Builder
    {
        return $query->when(
            ! $user->hasPermission('leads', 'manage'),
            fn (Builder $q) => $q->whereHas(
                'lead', fn (Builder $lq) => $lq->where('assigned_user_id', $user->id)->whereNull('converted_at'),
            ),
        );
    }

    /** Every deal with a currently-open collection task (mirrors ⚡collections.blade.php's totalOutstanding). */
    private function dealsWithOpenCollectionTasks(): Collection
    {
        return Task::where('task_type', 'collection')
            ->where('status', 'open')
            ->with('deal')
            ->get()
            ->map(fn (Task $task) => $task->deal)
            ->filter()
            ->unique('id')
            ->values();
    }

    private function paidDeals(): Builder
    {
        return Deal::whereHas('status', fn (Builder $q) => $q->where('name', Deal::PAID_STATUS_NAME))
            ->with(['customer.school', 'documents']);
    }

    /** @return array{badge: string, badgeClass: string} */
    private function badgeForDate(Carbon|\DateTimeInterface $date): array
    {
        $today = Carbon::today();
        $due = Carbon::parse($date)->startOfDay();

        if ($due->lt($today)) {
            return ['badge' => 'באיחור', 'badgeClass' => 'badge-error'];
        }

        if ($due->eq($today)) {
            return ['badge' => 'היום', 'badgeClass' => 'badge-warning'];
        }

        if ($due->eq($today->copy()->addDay())) {
            return ['badge' => 'מחר', 'badgeClass' => 'badge-info'];
        }

        return ['badge' => 'לביצוע', 'badgeClass' => 'badge-info'];
    }
}
