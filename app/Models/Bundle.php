<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A bundle is a one-off, independent purchase — not counted in a
 * subscription's 10-program offset (FR-3.8) — UNLESS it's the one bundle
 * flagged is_subscription_type (moved here from Program 2026-09-09, at most
 * one active at a time, enforced in the catalog Livewire component), in
 * which case purchasing it opens a real SUBSCRIPTION instead of a plain deal
 * — see Deal::createForCustomer()/openSubscriptionIfApplicable().
 */
#[Fillable(['name', 'description', 'price', 'is_subscription_type', 'is_active', 'contract_template_id'])]
class Bundle extends Model
{
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
            'is_subscription_type' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function programs(): BelongsToMany
    {
        return $this->belongsToMany(Program::class);
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
        if (! $this->is_subscription_type) {
            return;
        }

        $list = MailingList::forProgram($program);

        Subscription::with('customer', 'deliveries')
            ->whereHas('deal', fn ($q) => $q->where('bundle_id', $this->id))
            ->whereHas('status', fn ($q) => $q->where('name', Subscription::ACTIVE_STATUS_NAME))
            ->get()
            ->each(function (Subscription $subscription) use ($list, $program, $joined) {
                if ($joined) {
                    MailingMembership::addCustomer($list, $subscription->customer);

                    return;
                }

                $delivered = $subscription->deliveries->where('is_supplied', true)->pluck('program_id')->contains($program->id);
                $bought = Deal::where('customer_id', $subscription->customer_id)->where('program_id', $program->id)
                    ->whereHas('status', fn ($q) => $q->where('name', '!=', Deal::CANCELLED_STATUS_NAME))->exists();

                if (! $delivered && ! $bought) {
                    MailingMembership::removeCustomer($list, $subscription->customer);
                }
            });
    }
}
