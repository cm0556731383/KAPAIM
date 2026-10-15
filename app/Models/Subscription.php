<?php

namespace App\Models;

use App\Services\ActivityLogger;
use App\Services\HebrewCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Build-plan 09 — SUBSCRIPTION. Opened only by
 * Deal::openSubscriptionIfApplicable() (called from Deal::createForCustomer()
 * when the purchased bundle is build-plan 03's is_subscription_type
 * discriminator, moved from Program to Bundle 2026-09-09) — never created
 * directly by UI/controller code, the same
 * "sole creation point" convention as Deal::createForCustomer(),
 * Document::generateFor(), and Receipt::issueFor().
 *
 * `version` backs the same optimistic-locking convention as deals.version
 * (FR-8.19, stage 6/8) — see markDeliverySupplied() below: the caller
 * supplies the `version` it loaded the subscription with, and the atomic
 * `WHERE id = ? AND version = ?` update is what "reserves" a given marking,
 * so two concurrent marks on the same subscription can never both succeed
 * against a stale version.
 *
 * A subscription (and its delivery rows) is never deleted, even when
 * cancelled (US-010's explicit acceptance criterion) — cancel() below is a
 * status change only, same convention as Deal::updateStatusWithLock().
 */
#[Fillable([
    'customer_id', 'deal_id', 'status_id', 'start_date', 'first_month_date', 'end_date',
    'cancelled_at', 'cancelled_from_month', 'agreed_price', 'cancellation_credit', 'cancellation_reason', 'monthly_payment_override', 'version',
])]
class Subscription extends Model
{
    use HasFactory;

    public const ACTIVE_STATUS_NAME = 'פעיל';

    public const ENDED_STATUS_NAME = 'הסתיים';

    public const CANCELLED_STATUS_NAME = 'בוטל';

    /** FR-3.12: every subscription grants exactly 10 future program slots. */
    public const TOTAL_DELIVERIES = 10;

    private const BADGE_CLASSES = [
        'פעיל' => 'badge-success',
        'הסתיים' => 'badge-neutral',
        'בוטל' => 'badge-error',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'first_month_date' => 'date',
            'end_date' => 'date',
            'cancelled_at' => 'date',
            'cancelled_from_month' => 'integer',
            'agreed_price' => 'decimal:2',
            'cancellation_credit' => 'decimal:2',
            'monthly_payment_override' => 'decimal:2',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(StatusDefinition::class, 'status_id');
    }

    /**
     * FR-3.13: every subscription always has exactly 10 of these — see
     * Deal::openSubscriptionIfApplicable(), the only place they're created.
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(SubscriptionDelivery::class)->orderBy('sequence_number');
    }

    public function isActive(): bool
    {
        return $this->status?->name === self::ACTIVE_STATUS_NAME;
    }

    /**
     * The 1st of the Hebrew month that is this subscription's month 1 — see
     * HebrewCalendar. Pinned at opening (Deal::openSubscriptionIfApplicable());
     * a subscription opened before that existed follows the current setting.
     */
    public function firstMonthDate(): CarbonImmutable
    {
        return $this->first_month_date
            ? CarbonImmutable::parse($this->first_month_date->toDateString())
            : HebrewCalendar::cycleStartFor($this->start_date, HebrewCalendar::startMonth(), self::TOTAL_DELIVERIES);
    }

    /** The last day of the subscription's 10th month — after it, the subscription is over (see endExpired()). */
    public function periodEndDate(): CarbonImmutable
    {
        return HebrewCalendar::addMonths($this->firstMonthDate(), self::TOTAL_DELIVERIES)->subDay();
    }

    public function hasPeriodEnded(): bool
    {
        return now('Asia/Jerusalem')->toDateString() > $this->periodEndDate()->toDateString();
    }

    /**
     * Ends every active subscription whose 10 months are over, even when not
     * every month was marked supplied (the business owner's 2026-10-16
     * decision — before this a subscription only ended on its 10th marked
     * delivery, so one unmarked month kept it active, and its subscriber
     * discount, forever). The deal and deliveries are left as history; the
     * customer leaves the subscribers list (leaveSubscribersList()), same as
     * ending on the 10th delivery. Run daily by `subscriptions:end-expired`.
     *
     * @return int how many subscriptions were ended
     */
    public static function endExpired(ActivityLogger $activityLogger): int
    {
        $ended = StatusDefinition::firstOrCreate(
            ['scope' => 'subscription', 'name' => self::ENDED_STATUS_NAME],
            ['is_active' => true, 'sort_order' => 2],
        );

        $count = 0;

        self::whereHas('status', fn ($q) => $q->where('name', self::ACTIVE_STATUS_NAME))
            ->with('customer.school')
            ->get()
            ->filter(fn (self $subscription) => $subscription->hasPeriodEnded())
            ->each(function (self $subscription) use ($ended, $activityLogger, &$count) {
                $subscription->update(['status_id' => $ended->id, 'end_date' => $subscription->periodEndDate()->toDateString()]);
                $subscription->leaveSubscribersList();
                $count++;

                $activityLogger->log('subscription.ended', "מנוי #{$subscription->id} של \"{$subscription->customer?->school?->name}\" הסתיים אוטומטית — חודשי המנוי ({$subscription->monthLabel(1)} – {$subscription->monthLabel(self::TOTAL_DELIVERIES)}) הסתיימו", [
                    'customer_id' => $subscription->customer_id,
                    'deal_id' => $subscription->deal_id,
                    'user' => null,
                ]);
            });

        return $count;
    }

    /** Hebrew month of subscription month $month, e.g. "טבת תשפ״ז". */
    public function monthLabel(int $month): string
    {
        return HebrewCalendar::monthLabel(HebrewCalendar::addMonths($this->firstMonthDate(), $month - 1));
    }

    /**
     * Earliest month a cancellation may start from: the month after the last
     * delivery row already marked supplied — a supplied program is never
     * credited back.
     */
    public function firstCancellableMonth(): int
    {
        return ((int) $this->deliveries()->where('is_supplied', true)->max('sequence_number')) + 1;
    }

    public function creditFor(int $fromMonth): float
    {
        return round(((float) $this->agreed_price / self::TOTAL_DELIVERIES) * (self::TOTAL_DELIVERIES - $fromMonth + 1), 2);
    }

    /** A delivery row falling in the cancelled months. */
    public function isDeliveryCancelled(SubscriptionDelivery $delivery): bool
    {
        return $this->cancelled_from_month !== null && $delivery->sequence_number >= $this->cancelled_from_month;
    }

    /** The credit note's line text — which months are credited, and at what monthly price. */
    public function creditDescription(): string
    {
        if ($this->cancelled_from_month === null) {
            return 'זיכוי בגין ביטול מנוי';
        }

        $months = self::TOTAL_DELIVERIES - $this->cancelled_from_month + 1;
        $range = $months === 1
            ? $this->monthLabel($this->cancelled_from_month)
            : $this->monthLabel($this->cancelled_from_month).' – '.$this->monthLabel(self::TOTAL_DELIVERIES);
        $monthly = number_format((float) $this->agreed_price / self::TOTAL_DELIVERIES, 2);

        // An amount set by hand in the cancellation window isn't months × monthly price.
        if (abs((float) $this->cancellation_credit - $this->creditFor($this->cancelled_from_month)) > 0.005) {
            return "זיכוי בגין ביטול מנוי החל מחודש {$this->cancelled_from_month} ({$range})";
        }

        return "זיכוי בגין ביטול מנוי החל מחודש {$this->cancelled_from_month} ({$range}): {$months} חודשים × ₪{$monthly}";
    }

    public static function badgeClassForStatusName(?string $statusName): string
    {
        return self::BADGE_CLASSES[$statusName] ?? 'badge-neutral';
    }

    /**
     * FR-3.15: the count of supplied programs comes only from the delivery
     * log's is_supplied rows — never from anything materials-sending related
     * (build-plan 10, deliberately disconnected).
     */
    public function suppliedCount(): int
    {
        return $this->deliveries()->where('is_supplied', true)->count();
    }

    /**
     * FR-3.19/FR-3.20: defaults to the subscription's deal's invoice
     * line-item total divided by 10, falling back to agreed_price / 10 when
     * no invoice exists yet for that deal — a manual override
     * (monthly_payment_override) always wins once set.
     */
    public function monthlyPayment(): float
    {
        if ($this->monthly_payment_override !== null) {
            return (float) $this->monthly_payment_override;
        }

        $invoice = $this->deal->issuedInvoice();
        $base = $invoice ? $invoice->totalAmount() : (float) $this->agreed_price;

        return round($base / self::TOTAL_DELIVERIES, 2);
    }

    /**
     * FR-3.20: sets (or, with null, clears) the manual override for the
     * computed monthly-payment default above.
     */
    public function setMonthlyPaymentOverride(?float $amount): void
    {
        $this->update(['monthly_payment_override' => $amount]);
    }

    /**
     * FR-3.14/FR-8.19: the only place a delivery row is ever marked
     * supplied — guarded by the exact optimistic-locking pattern used by
     * Deal::updateStatusWithLock()/recordPayment(): the caller supplies the
     * subscription's `version`; the atomic `WHERE id = ? AND version = ?`
     * update is what "reserves" this marking, so a double, simultaneous mark
     * of the same program slot can never create an inconsistent supplied
     * count.
     *
     * FR-3.15: purely manual — this method has no awareness of, and is never
     * called from, anything materials-sending related (build-plan 10).
     * FR-3.16/FR-3.17: once this is the 10th delivery marked supplied, the
     * subscription auto-closes (status -> "הסתיים", end_date = today) — it
     * never auto-renews (FR-3.18: renewal is always a brand-new deal).
     *
     * @throws RuntimeException on a version conflict or a rule violation.
     */
    public function markDeliverySupplied(int $expectedVersion, int $deliveryId, int $programId, User $user): SubscriptionDelivery
    {
        $delivery = $this->deliveries()->whereKey($deliveryId)->firstOrFail();

        // A cancelled subscription still owes the months before its
        // cancellation month — only those rows may be marked after it.
        $suppliableAfterCancel = $this->status?->name === self::CANCELLED_STATUS_NAME
            && $this->cancelled_from_month !== null
            && ! $this->isDeliveryCancelled($delivery);

        // One ended by date (endExpired()) may still have a supplied month recorded late.
        $suppliableAfterEnd = $this->status?->name === self::ENDED_STATUS_NAME;

        if (! $this->isActive() && ! $suppliableAfterCancel && ! $suppliableAfterEnd) {
            throw new RuntimeException('ניתן לסמן אספקה רק עבור מנוי פעיל.');
        }

        if ($delivery->is_supplied) {
            throw new RuntimeException('שורת אספקה זו כבר סומנה כסופקה.');
        }

        $program = Program::find($programId);

        if (! $program || ! $program->is_active) {
            throw new RuntimeException('יש לבחור תוכנית קטלוג פעילה עבור שורת האספקה.');
        }

        $wasActive = $this->isActive();

        $supplied = DB::transaction(function () use ($expectedVersion, $delivery, $program, $user) {
            $affected = self::where('id', $this->id)
                ->where('version', $expectedVersion)
                ->update(['version' => DB::raw('version + 1')]);

            if ($affected === 0) {
                throw new RuntimeException('המנוי עודכן על ידי משתמשת אחרת בינתיים — נא לרענן ולנסות שוב.');
            }

            $delivery->update([
                'program_id' => $program->id,
                'is_supplied' => true,
                'supplied_at' => now(),
                'supplied_by' => $user->id,
            ]);

            $this->refresh();

            if ($this->isActive() && $this->suppliedCount() >= self::TOTAL_DELIVERIES) {
                $ended = StatusDefinition::firstOrCreate(
                    ['scope' => 'subscription', 'name' => self::ENDED_STATUS_NAME],
                    ['is_active' => true, 'sort_order' => 2],
                );

                $this->update(['status_id' => $ended->id, 'end_date' => now()->toDateString()]);
            }

            return $delivery->refresh();
        });

        if ($wasActive && $this->refresh()->status?->name === self::ENDED_STATUS_NAME) {
            $this->leaveSubscribersList();
        }

        return $supplied;
    }

    /**
     * Undoes a delivery row marked supplied by mistake — same optimistic lock
     * as markDeliverySupplied(). A subscription that auto-ended on its 10th
     * delivery goes back to active — unless its months are already over.
     *
     * @throws RuntimeException on a version conflict, or when the row isn't supplied.
     */
    public function unmarkDeliverySupplied(int $expectedVersion, int $deliveryId): SubscriptionDelivery
    {
        $delivery = $this->deliveries()->whereKey($deliveryId)->firstOrFail();

        if (! $delivery->is_supplied) {
            throw new RuntimeException('שורת אספקה זו אינה מסומנת כסופקה.');
        }

        $wasEnded = $this->status?->name === self::ENDED_STATUS_NAME;

        $unsupplied = DB::transaction(function () use ($expectedVersion, $delivery) {
            $affected = self::where('id', $this->id)
                ->where('version', $expectedVersion)
                ->update(['version' => DB::raw('version + 1')]);

            if ($affected === 0) {
                throw new RuntimeException('המנוי עודכן על ידי משתמשת אחרת בינתיים — נא לרענן ולנסות שוב.');
            }

            $delivery->update(['program_id' => null, 'is_supplied' => false, 'supplied_at' => null, 'supplied_by' => null]);

            // Only an end caused by the 10th delivery is undone — never one whose months are over.
            if ($this->status?->name === self::ENDED_STATUS_NAME && ! $this->hasPeriodEnded()) {
                $active = StatusDefinition::firstOrCreate(
                    ['scope' => 'subscription', 'name' => self::ACTIVE_STATUS_NAME],
                    ['is_active' => true, 'sort_order' => 1],
                );
                $this->update(['status_id' => $active->id, 'end_date' => null]);
            }

            $this->refresh();

            return $delivery->refresh();
        });

        // Reopened — a subscriber again.
        if ($wasEnded && $this->refresh()->isActive()) {
            MailingMembership::addCustomer(MailingList::subscribersList(), $this->customer);
        }

        return $unsupplied;
    }

    /**
     * US-010/FR-8.24: cancels the subscription from subscription month
     * $fromMonth (default: the first month not yet supplied). The credit is
     * computed from this subscription's OWN agreed_price only —
     * agreed_price/10 per month, for every month from $fromMonth through
     * month 10. Bundles and any other deal are never part of this
     * calculation (FR-3.8) since neither is ever referenced here.
     *
     * Marks the subscription and its deal cancelled (status changes only —
     * never deletes the deal, this row, the delivery history, or any
     * activity log, per US-010's explicit acceptance criterion). Generating
     * an actual credit note is a deliberately separate, explicit step — see
     * generateCreditNote() below — not auto-triggered here (same convention
     * as issuing a receipt being separate from recording a payment, stage 8).
     *
     * @throws RuntimeException when the subscription isn't currently active,
     *                          or $fromMonth is before a supplied month.
     */
    /**
     * $credit defaults to creditFor($fromMonth); the user may set another
     * amount in the cancellation window (0 up to the deal's agreed amount).
     */
    public function cancel(?int $fromMonth = null, ?float $credit = null, ?string $reason = null): self
    {
        if (! $this->isActive()) {
            throw new RuntimeException('ניתן לבטל רק מנוי פעיל.');
        }

        $firstCancellable = $this->firstCancellableMonth();
        $fromMonth ??= $firstCancellable;

        if ($fromMonth < $firstCancellable || $fromMonth > self::TOTAL_DELIVERIES) {
            throw new RuntimeException($firstCancellable > self::TOTAL_DELIVERIES
                ? 'כל התוכניות במנוי כבר סופקו — אין חודשים לביטול.'
                : "ניתן לבטל את המנוי החל מחודש {$firstCancellable} ועד חודש ".self::TOTAL_DELIVERIES.' — חודשים קודמים כבר סופקו.');
        }

        $credit ??= $this->creditFor($fromMonth);
        $maxCredit = (float) $this->deal->agreed_amount;

        if ($credit < 0 || $credit > $maxCredit + 0.005) {
            throw new RuntimeException('סכום הזיכוי חייב להיות בין ₪0 לסכום העסקה (₪'.number_format($maxCredit, 0).').');
        }

        $cancelledStatus = StatusDefinition::firstOrCreate(
            ['scope' => 'subscription', 'name' => self::CANCELLED_STATUS_NAME],
            ['is_active' => true, 'sort_order' => 3],
        );

        DB::transaction(function () use ($cancelledStatus, $fromMonth, $credit, $reason) {
            $this->update([
                'status_id' => $cancelledStatus->id,
                'cancelled_at' => now()->toDateString(),
                'cancelled_from_month' => $fromMonth,
                'cancellation_credit' => round($credit, 2),
                'cancellation_reason' => filled($reason) ? trim($reason) : null,
            ]);

            $this->deal->cancelForSubscription();
        });

        $this->removeSubscriptionMailingListMemberships();

        return $this->refresh();
    }

    /**
     * Build-plan 10 (FR-5.24/FR-5.25): cancellation removes the customer
     * from the "subscribers" list and from the list of every program in the
     * subscription bundle (Deal::assignMailingListsForBundlePurchase()) NOT
     * already delivered under THIS subscription's own delivery log — a
     * program already marked supplied keeps its membership, and so does one
     * the customer also bought on its own; the primary list is never touched
     * here at all (FR-5.25).
     *
     * Build-plan 12: MailingMembership::removeCustomer() below now also
     * pushes this removal to Smove for real — nothing to change here.
     */
    /**
     * An ended or cancelled subscription's customer leaves the subscribers
     * list — unless another subscription of theirs is still active (a
     * renewal opened before this one's months ran out).
     */
    private function leaveSubscribersList(): void
    {
        $stillSubscribed = self::where('customer_id', $this->customer_id)
            ->whereKeyNot($this->id)
            ->whereHas('status', fn ($q) => $q->where('name', self::ACTIVE_STATUS_NAME))
            ->exists();

        if (! $stillSubscribed) {
            MailingMembership::removeCustomer(MailingList::subscribersList(), $this->customer);
        }
    }

    private function removeSubscriptionMailingListMemberships(): void
    {
        $this->leaveSubscribersList();

        $deliveredProgramIds = $this->deliveries()->where('is_supplied', true)->pluck('program_id')->all();

        $boughtProgramIds = Deal::where('customer_id', $this->customer_id)->whereNotNull('program_id')
            ->whereHas('status', fn ($q) => $q->where('name', '!=', Deal::CANCELLED_STATUS_NAME))
            ->pluck('program_id')->all();

        ($this->deal->bundle?->programs ?? collect())
            ->reject(fn (Program $program) => in_array($program->id, $deliveredProgramIds, true) || in_array($program->id, $boughtProgramIds, true))
            ->each(fn (Program $program) => MailingMembership::removeCustomer(MailingList::forProgram($program), $this->customer));
    }

    /**
     * FR-4.5/FR-8.12: a credit note may only be generated once the
     * subscription's own deal already has an invoice document — reuses
     * Document::assertCanGenerate()'s gate (via generateCreditNoteFor())
     * rather than a parallel check. Left as a separate explicit action from
     * cancel(), never auto-generated.
     *
     * @throws RuntimeException when the subscription isn't cancelled yet, or
     *                          the gate above isn't met.
     */
    public function generateCreditNote(): Document
    {
        $this->assertCancelledForCredit();

        return Document::generateCreditNoteFor($this->deal, (float) $this->cancellation_credit, $this->creditDescription());
    }

    /** generateCreditNote(), issued in SUMIT and sent to the customer in the same step (Document::issueCreditNoteFor()). */
    public function issueCreditNote(\App\Services\ActivityLogger $logger, \App\Services\Integrations\ExternalOperationRunner $runner, \App\Services\Integrations\SummitClient $summit, \App\Services\Integrations\SmoveClient $smove): array
    {
        $this->assertCancelledForCredit();

        return Document::issueCreditNoteFor($this->deal, (float) $this->cancellation_credit, $this->creditDescription(), $logger, $runner, $summit, $smove);
    }

    private function assertCancelledForCredit(): void
    {
        if ($this->status?->name !== self::CANCELLED_STATUS_NAME) {
            throw new RuntimeException('ניתן להפיק חשבונית זיכוי רק עבור מנוי שבוטל.');
        }
    }
}

