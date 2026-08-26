<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Build-plan 09 — SUBSCRIPTION_DELIVERY. All 10 rows for a subscription are
 * created up front by Deal::openSubscriptionIfApplicable() and are only ever
 * updated by Subscription::markDeliverySupplied() — never created or updated
 * anywhere else. FR-3.15: program_id/is_supplied/supplied_at/supplied_by are
 * set purely by that manual action; nothing in this model reacts to, or is
 * aware of, materials sending (build-plan 10) — the count of supplied
 * programs everywhere in the app comes only from is_supplied here.
 */
#[Fillable(['subscription_id', 'program_id', 'supplied_by', 'sequence_number', 'is_supplied', 'supplied_at'])]
class SubscriptionDelivery extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_supplied' => 'boolean',
            'supplied_at' => 'date',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function suppliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supplied_by');
    }
}
