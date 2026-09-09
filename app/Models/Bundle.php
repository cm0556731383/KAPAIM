<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A bundle is a one-off, independent purchase — not counted in a
 * subscription's 10-program offset (FR-3.8) — UNLESS it's the one bundle
 * flagged is_subscription_type (moved here from Program 2026-09-09, at most
 * one active at a time, enforced in the catalog Livewire component), in
 * which case purchasing it opens a real SUBSCRIPTION instead of a plain deal
 * — see Deal::createForCustomer()/openSubscriptionIfApplicable().
 */
#[Fillable(['name', 'description', 'price', 'is_subscription_type', 'is_active'])]
class Bundle extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'is_subscription_type' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function programs(): BelongsToMany
    {
        return $this->belongsToMany(Program::class);
    }
}
