<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Chronological, append-only record of significant system events (FR-7.17–FR-7.20).
 * Entries are never edited or deleted — booted() below enforces that at the model
 * layer regardless of what any future controller/UI tries to do.
 */
#[Fillable([
    'user_id', 'school_id', 'lead_id', 'customer_id', 'deal_id', 'subscription_id',
    'material_delivery_id', 'task_id', 'document_id', 'expense_id', 'external_operation_id',
    'activity_type', 'description', 'metadata', 'occurred_at',
])]
class ActivityLog extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new RuntimeException('Activity log entries are immutable and cannot be updated.');
        });

        static::deleting(function () {
            throw new RuntimeException('Activity log entries are immutable and cannot be deleted.');
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * True when a human performed the action; false means it was recorded by an
     * external system/integration (Smove, Summit, ...) on the user's behalf (FR-7.20).
     */
    public function isManual(): bool
    {
        return $this->user_id !== null;
    }
}
