<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * is_subscription_type moved to Bundle 2026-09-09 — see Bundle's own
 * docblock and Deal::openSubscriptionIfApplicable().
 */
#[Fillable(['name', 'description', 'price', 'is_premium', 'is_active'])]
class Program extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'is_premium' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function bundles(): BelongsToMany
    {
        return $this->belongsToMany(Bundle::class);
    }

    /**
     * Build-plan 10 (FR-5.23) judgment call — see Deal::assignMailingListsForProgramPurchase()
     * and Subscription::removeSubscriptionMailingListMemberships(): "every
     * program included in the subscription" is read here as the FIXED set of
     * currently-active, non-premium catalog programs (the "monthly"
     * programs) — a purchase-time/cancellation-time snapshot of the catalog,
     * deliberately independent of build-plan 09's per-slot delivery picks
     * (SubscriptionDelivery), which are chosen manually later and vary per
     * subscription. Whatever this count actually is in the seeded catalog is
     * what "every program" means here — this stage does not hard-require
     * exactly 10. See this stage's report for the reasoning.
     */
    public function scopeMonthlyCatalog(Builder $query): Builder
    {
        return $query->where('is_active', true)->where('is_premium', false);
    }
}
