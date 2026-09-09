<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Build-plan 05: a CUSTOMER is created exclusively by converting a LEAD
 * (FR-2.3, FR-8.2) — see Lead::convertToCustomer() below, which is the only
 * place a Customer row is ever created. Customers are never deleted
 * (FR-2.4/FR-8.3): no SoftDeletes trait, no delete route/action anywhere.
 *
 * FR-2.2: a branch of an education network is its own customer card even
 * when billing is centralized at the network level — that's an operational
 * rule, not a schema one; each branch simply has its own School/Lead row
 * and therefore converts to its own Customer row here.
 */
#[Fillable(['school_id', 'lead_id', 'status_id', 'converted_at'])]
class Customer extends Model
{
    use HasFactory;

    public const DEFAULT_STATUS_NAME = 'פעילה';

    private const BADGE_CLASSES = [
        'פעילה' => 'badge-success',
        'לא פעילה' => 'badge-neutral',
    ];

    protected function casts(): array
    {
        return [
            'converted_at' => 'datetime',
        ];
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(StatusDefinition::class, 'status_id');
    }

    /**
     * FR-2.5: contacts are keyed by customer_id once a school becomes a
     * customer (backfilled at conversion time — see
     * Lead::convertToCustomer()).
     */
    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    /**
     * Build-plan 06: every purchase for this customer is its own DEAL row —
     * see Deal::createForCustomer(), the only place one is ever created.
     */
    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class);
    }

    /**
     * Build-plan 09: every subscription this customer has ever had — see
     * Deal::openSubscriptionIfApplicable(), the only place one is ever
     * created. A customer may have more than one over time (FR-3.18: a
     * renewal is a brand-new deal, hence a brand-new subscription).
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * FR-2.17: "מנוי פעיל" everywhere the customer is shown — a badge check
     * should always go through this (status-based), never through mere row
     * existence, so a cancelled/ended subscription correctly falls off.
     */
    public function activeSubscription(): ?Subscription
    {
        return $this->subscriptions()
            ->whereHas('status', fn ($q) => $q->where('name', Subscription::ACTIVE_STATUS_NAME))
            ->latest('id')
            ->first();
    }

    public function hasActiveSubscription(): bool
    {
        return $this->activeSubscription() !== null;
    }

    /**
     * FR-8.23's prerequisite: sum of agreed_amount across this customer's own
     * standalone program purchases — program-based deals only, excluding
     * bundle deals entirely (FR-3.8). A program deal can never itself be the
     * subscription-granting purchase any more (that discriminator moved to
     * Bundle::is_subscription_type, 2026-09-09), so every program deal here
     * is definitionally a standalone one.
     */
    public function standaloneProgramsTotal(): float
    {
        return (float) $this->deals()
            ->whereNotNull('program_id')
            ->sum('agreed_amount');
    }

    /**
     * FR-8.23: purely informational — compares the total above to the
     * current active subscription-type bundle's list price (build-plan 03's
     * at-most-one-active-at-a-time discriminator/uniqueness guarantee, moved
     * from Program to Bundle 2026-09-09).
     */
    public function exceedsSubscriptionPriceAlert(): bool
    {
        $subscriptionPrice = Bundle::where('is_subscription_type', true)->where('is_active', true)->first()?->price;

        if (! $subscriptionPrice) {
            return false;
        }

        return $this->standaloneProgramsTotal() > (float) $subscriptionPrice;
    }

    public static function badgeClassForStatusName(?string $statusName): string
    {
        return self::BADGE_CLASSES[$statusName] ?? 'badge-neutral';
    }
}

