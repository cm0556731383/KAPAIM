<?php

namespace App\Models;

use App\Concerns\HasClassTierPrices;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A bundle is a one-off, independent purchase — not counted in a
 * subscription's 10-program offset (FR-3.8) — UNLESS it's flagged
 * is_subscription_type (moved here from Program 2026-09-09). Several
 * subscription bundles may be active at once (2026-11-24, business owner:
 * one per school year — renewing = selling next year's bundle), in
 * which case purchasing it opens a real SUBSCRIPTION instead of a plain deal
 * — see Deal::createForCustomer()/openSubscriptionIfApplicable().
 */
#[Fillable(['name', 'description', 'price', 'price_3_4', 'price_5_6', 'is_subscription_type', 'school_year', 'is_active', 'contract_template_id'])]
class Bundle extends Model
{
    use HasClassTierPrices;
    use HasFactory;

    /** The contract its order form + contract pair is generated with (Document::generateOrderFormAndContract()). */
    public function contractTemplate(): BelongsTo
    {
        return $this->belongsTo(DocumentTemplate::class, 'contract_template_id');
    }

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'price_3_4' => 'decimal:2',
            'price_5_6' => 'decimal:2',
            'is_subscription_type' => 'boolean',
            'school_year' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * A subscription bundle's month 1 — the start of its school year
     * (HebrewCalendar::schoolYearStart()); null when no year is set (a
     * subscription then follows its sale date, as before 2026-11-24).
     */
    public function subscriptionFirstMonth(): ?\Carbon\CarbonImmutable
    {
        return $this->is_subscription_type && $this->school_year
            ? \App\Services\HebrewCalendar::schoolYearStart($this->school_year)
            : null;
    }

    /** The school year's 10 months are over — it can't be sold any more. */
    public function schoolYearHasEnded(): bool
    {
        $start = $this->subscriptionFirstMonth();

        return $start !== null
            && now('Asia/Jerusalem')->toDateString() >= \App\Services\HebrewCalendar::addMonths($start, Subscription::TOTAL_DELIVERIES)->toDateString();
    }

    /** e.g. "תשפ״ז (2026–2027)". */
    public function schoolYearLabel(): ?string
    {
        return $this->school_year ? \App\Services\HebrewCalendar::schoolYearLabel($this->school_year) : null;
    }

    public function programs(): BelongsToMany
    {
        return $this->belongsToMany(Program::class);
    }

    /** Bundles included in this (subscription) bundle — one level, see allPrograms(). */
    public function includedBundles(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'bundle_bundle', 'parent_bundle_id', 'child_bundle_id')->withTimestamps();
    }

    /** Subscription bundles this bundle is included in. */
    public function includedIn(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'bundle_bundle', 'child_bundle_id', 'parent_bundle_id')->withTimestamps();
    }

    /**
     * Every program the bundle gives: its own and those of the bundles
     * included in it — what a subscription through it covers (mailing
     * lists, materials, cancellation). One entry per program.
     *
     * @return \Illuminate\Support\Collection<int, Program>
     */
    public function allPrograms(): \Illuminate\Support\Collection
    {
        return $this->programs
            ->merge($this->includedBundles->flatMap->programs)
            ->unique('id')
            ->values();
    }

    /**
     * $bundleIds plus the ids of every bundle included in them — for "a
     * program bought inside a bundle" lookups.
     *
     * @param  iterable<int>  $bundleIds
     * @return \Illuminate\Support\Collection<int, int>
     */
    public static function withIncludedIds(iterable $bundleIds): \Illuminate\Support\Collection
    {
        $ids = collect($bundleIds)->map(fn ($id) => (int) $id)->filter()->unique();

        if ($ids->isEmpty()) {
            return $ids->values();
        }

        return $ids->merge(
            \Illuminate\Support\Facades\DB::table('bundle_bundle')->whereIn('parent_bundle_id', $ids)->pluck('child_bundle_id')->map(fn ($id) => (int) $id)
        )->unique()->values();
    }

    /**
     * A program added to / removed from the subscription bundle in the
     * catalog: every customer currently subscribed through this bundle joins
     * / leaves that program's list — subscribers are on the lists of exactly
     * the programs in the bundle (Deal::assignMailingListsForBundlePurchase()).
     * Leaving skips a customer who received that program under the
     * subscription or bought it on its own.
     */
    public function syncSubscriberProgramList(Program $program, bool $joined): void
    {
        // A program added to / removed from a bundle included in subscription
        // bundles reaches their subscribers too.
        if (! $this->is_subscription_type) {
            $this->includedIn->each(fn (Bundle $parent) => $parent->syncSubscriberProgramList($program, $joined));

            return;
        }

        $list = MailingList::forProgram($program);

        Subscription::with('customer', 'deliveries')
            ->whereHas('deal', fn ($q) => $q->withItem($this))
            ->whereHas('status', fn ($q) => $q->where('name', Subscription::ACTIVE_STATUS_NAME))
            ->get()
            ->each(function (Subscription $subscription) use ($list, $program, $joined) {
                if ($joined) {
                    MailingMembership::addCustomer($list, $subscription->customer);

                    return;
                }

                // Still in the subscription another way (its own programs, or another included bundle).
                if ($this->fresh()->allPrograms()->contains('id', $program->id)) {
                    return;
                }

                $delivered = $subscription->deliveries->where('is_supplied', true)->pluck('program_id')->contains($program->id);
                $bought = Deal::where('customer_id', $subscription->customer_id)->withItem($program)
                    ->whereHas('status', fn ($q) => $q->where('name', '!=', Deal::CANCELLED_STATUS_NAME))->exists();

                if (! $delivered && ! $bought) {
                    MailingMembership::removeCustomer($list, $subscription->customer);
                }
            });
    }
}
