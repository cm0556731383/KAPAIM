<?php

namespace App\Models;

use App\Services\ActivityLogger;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Personal reminder, optionally tied to a lead (FR-1.12); shown on the home
 * screen until completed. `customer_id`/`deal_id` exist now per docs/erd.md
 * for future stages to use later. Cancelling a task is a logical delete
 * (deleted_at) — never a real one — same convention as Contact (build-plan 04).
 *
 * Build-plan 08: `deal_id` starts being used here — a collection task
 * (task_type = 'collection') is just a plain Task row scoped to a deal, no
 * separate entity — see App\Console\Commands\ProcessCollectionTasks.
 *
 * Build-plan 19 (FR-8.22): restore() below reverses that logical delete —
 * see ⚡lead-detail.blade.php / ⚡customer-detail.blade.php's "פריטים
 * שהוסרו לאחרונה" panel, the only caller.
 */
#[Fillable([
    'user_id', 'lead_id', 'customer_id', 'deal_id', 'task_type', 'status',
    'title', 'description', 'due_at', 'completed_at',
])]
class Task extends Model
{
    use HasFactory, SoftDeletes {
        SoftDeletes::restore as private restoreSoftDelete;
    }

    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    /**
     * FR-8.22: reverses a logical delete (cancelTask() in
     * ⚡lead-detail.blade.php) and logs the restoration.
     */
    public function restore(ActivityLogger $activityLogger): bool
    {
        $result = $this->restoreSoftDelete();

        $activityLogger->log('task.restored', "שוחזרה משימה \"{$this->title}\" (FR-8.22)", [
            'lead_id' => $this->lead_id, 'customer_id' => $this->customer_id,
            'deal_id' => $this->deal_id, 'task_id' => $this->id,
        ]);

        return $result;
    }
}
