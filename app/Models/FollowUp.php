<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "Up Follow" — unlimited per lead (FR-1.10). `next_at` is nullable, set
 * "כאשר נדרש" (FR-1.11), and drives the "Up Follow הבא" column on the leads
 * list (build-plan 04).
 */
#[Fillable(['lead_id', 'user_id', 'occurred_at', 'summary', 'result', 'next_at'])]
class FollowUp extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'next_at' => 'datetime',
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
