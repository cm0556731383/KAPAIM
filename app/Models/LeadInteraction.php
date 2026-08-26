<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Free-text record of a call/contact with a lead (FR-1.7) — unlimited per
 * lead. Distinct from FollowUp, which additionally tracks a scheduled next
 * action via `next_at`.
 */
#[Fillable(['lead_id', 'user_id', 'interaction_type', 'summary', 'result', 'occurred_at'])]
class LeadInteraction extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
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
