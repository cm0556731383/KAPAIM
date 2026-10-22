<?php

namespace App\Console\Commands;

use App\Models\ExternalOperation;
use App\Models\Task;
use App\Models\User;
use App\Services\Integrations\ExternalOperationRunner;
use App\Services\Integrations\SmoveClient;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * The morning email of reminders (business owner's request, 2026-10-22),
 * registered in routes/console.php via Schedule::command(...)->dailyAt('08:00').
 * One email per user, listing all of that user's reminders for the day:
 *  - a personal reminder on a lead (task_type = 'reminder') whose due date
 *    is today — to the user who created it;
 *  - a collection task (task_type = 'collection', opened/renewed by
 *    ProcessCollectionTasks at midnight) — on the morning it opens, to the
 *    user it's assigned to (the lead's assigned user).
 * Whoever that user is can't take it — inactive, or (for collection) without
 * the payments permission — the email goes to the business owner instead.
 *
 * Each task is emailed once (reminder_sent_at), marked only once Smove
 * accepted the email; a failure is recorded by ExternalOperationRunner and
 * never crashes the scheduled run.
 */
class SendTaskReminders extends Command
{
    protected $signature = 'tasks:send-reminders';

    protected $description = "Email each user the morning list of her reminders for the day (lead reminders due today, new collection tasks)";

    public function handle(ExternalOperationRunner $runner, SmoveClient $smove): int
    {
        $tasks = Task::where('status', 'open')
            ->whereNull('reminder_sent_at')
            ->where(fn ($q) => $q
                ->where(fn ($r) => $r->where('task_type', 'reminder')->whereDate('due_at', today()))
                ->orWhere('task_type', ProcessCollectionTasks::TASK_TYPE))
            ->with(['user.role', 'lead.school', 'deal.customer.school'])
            ->orderBy('id')
            ->get();

        $owner = $this->businessOwner();

        $tasks->groupBy(fn (Task $task) => $this->recipientFor($task, $owner)?->id)
            ->each(function (Collection $userTasks, $userId) use ($runner, $smove) {
                $user = User::find($userId);

                if ($user) {
                    $this->sendTo($user, $userTasks, $runner, $smove);
                }
            });

        return self::SUCCESS;
    }

    /** The oldest active user with full access to payments — the business owner. */
    private function businessOwner(): ?User
    {
        return User::where('is_active', true)->with('role')->orderBy('id')->get()
            ->first(fn (User $user) => $user->hasPermission('payments', 'view'));
    }

    private function recipientFor(Task $task, ?User $owner): ?User
    {
        $user = $task->user;
        $canTakeIt = $user?->is_active
            && ($task->task_type !== ProcessCollectionTasks::TASK_TYPE || $user->hasPermission('payments', 'view'));

        return $canTakeIt ? $user : $owner;
    }

    private function sendTo(User $user, Collection $tasks, ExternalOperationRunner $runner, SmoveClient $smove): void
    {
        $rows = $tasks->map(fn (Task $task) => $task->task_type === ProcessCollectionTasks::TASK_TYPE
            ? [
                'title' => $task->title,
                'subtitle' => 'גבייה · '.($task->deal?->customer?->school?->name ?? '—'),
                'description' => null,
                'url' => $task->deal ? route('deal-detail', $task->deal) : route('collections'),
            ]
            : [
                'title' => $task->title,
                'subtitle' => 'תזכורת בליד · '.($task->lead?->school?->name ?? '—'),
                'description' => $task->description,
                'url' => $task->lead ? route('lead-detail', $task->lead) : route('leads'),
            ])->values()->all();

        $subject = $tasks->count() === 1
            ? 'תזכורת להיום: '.$tasks->first()->title
            : 'התזכורות שלך להיום ('.$tasks->count().')';

        $operation = $runner->run(
            'smove',
            'task_reminders',
            'scheduled_job',
            fn () => $smove->sendTaskRemindersEmail($user->email, $user->name, $subject, "בוקר טוב {$user->name}, אלה התזכורות שלך להיום:", $rows),
            [
                'user' => null,
                'description' => 'מייל תזכורות בוקר ל'.$user->name.' ('.$tasks->count().' תזכורות)',
            ],
        );

        if ($operation->status === ExternalOperation::STATUS_SUCCESS) {
            Task::whereIn('id', $tasks->pluck('id'))->update(['reminder_sent_at' => now()]);
        }
    }
}
