<?php

namespace App\Models;

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
 */
#[Fillable([
    'user_id', 'lead_id', 'customer_id', 'deal_id', 'task_type', 'status',
    'title', 'description', 'due_at', 'completed_at',
])]
class Task extends Model
{
    use HasFactory, SoftDeletes;

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
}
