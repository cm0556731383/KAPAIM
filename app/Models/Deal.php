<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Build-plan 06 — a DEAL is always tied to exactly one customer (FR-3.2) and
 * to exactly one of program/bundle, never both/neither (FR-3.3/FR-8.4) — see
 * createForCustomer() below, the only place a Deal row is created.
 * program_*_snapshot / bundle_*_snapshot freeze the sold item's name and
 * price at the moment of sale (FR-3.6) so a deal's history stays stable even
 * if the live program/bundle is later renamed, repriced, or disabled.
 *
 * A deal is never deleted, even when cancelled (FR-3.5): cancelling is just
 * a status change via updateStatusWithLock() below, which is guarded by
 * optimistic locking on `version` (FR-8.19).
 */
#[Fillable([
    'customer_id', 'program_id', 'bundle_id', 'status_id', 'agreed_amount',
    'program_price_snapshot', 'bundle_price_snapshot', 'program_name_snapshot', 'bundle_name_snapshot',
    'payment_method_id', 'special_request', 'purchased_at', 'completed_at', 'version',
])]
class Deal extends Model
{
    use HasFactory;

    public const DEFAULT_STATUS_NAME = 'פתוחה';

    public const CANCELLED_STATUS_NAME = 'מבוטלת';

    /** Build-plan 08 (FR-4.32/FR-4.33): a deal reaches this status only once fully paid. */
    public const PAID_STATUS_NAME = 'שולמה';

    /** Terminal statuses (build-plan 06 judgment call) mark `completed_at`. */
    private const TERMINAL_STATUS_NAMES = ['שולמה', 'מבוטלת'];

    private const BADGE_CLASSES = [
        'פתוחה' => 'badge-info',
        'נשלחה חשבונית' => 'badge-warning',
        'שולמה' => 'badge-success',
        'מבוטלת' => 'badge-error',
    ];

    protected function casts(): array
    {
        return [
            'agreed_amount' => 'decimal:2',
            'program_price_snapshot' => 'decimal:2',
            'bundle_price_snapshot' => 'decimal:2',
            'purchased_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function bundle(): BelongsTo
    {
        return $this->belongsTo(Bundle::class);
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(StatusDefinition::class, 'status_id');
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    /**
     * Build-plan 07: the deal's document chain (quote -> order_form ->
     * contract -> invoice) — see Document::generateFor(), the only place a
     * Document row is ever created.
     */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class)->orderBy('id');
    }

    /**
     * Build-plan 08: every recorded payment against this deal — see
     * recordPayment() below, the only place a Payment row is ever created.
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderBy('id');
    }

    /**
     * Build-plan 09: at most one — see openSubscriptionIfApplicable() below,
     * the only place a Subscription row is ever created.
     */
    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class);
    }

    /** FR-4.32/FR-4.33: always computed from recorded payments — never a stored column. */
    public function totalPaid(): float
    {
        return (float) $this->payments()->sum('amount');
    }

    /** FR-4.33: the balance still owed — visible on the deal/customer card. */
    public function outstandingBalance(): float
    {
        return max(0.0, (float) $this->agreed_amount - $this->totalPaid());
    }

    public function isFullyPaid(): bool
    {
        return $this->totalPaid() >= (float) $this->agreed_amount;
    }

    public static function badgeClassForStatusName(?string $statusName): string
    {
        return self::BADGE_CLASSES[$statusName] ?? 'badge-neutral';
    }

    /**
     * FR-3.2/FR-3.3/FR-8.4: the only place a Deal row is ever created — a
     * customer is always required (never freely user-supplied — the caller
     * passes it from context) and exactly one of $programId/$bundleId must
     * be given. FR-8.5: the chosen program/bundle must be active. Snapshots
     * the chosen item's current name/price and defaults agreed_amount to
     * that price (editable by the caller before/at creation). FR-3.11: when
     * the chosen program is a premium program and the customer already has
     * an active subscription, the default instead becomes 10% off that
     * price — still fully overridable by an explicit $agreedAmount.
     *
     * @throws RuntimeException on a business-rule violation — the caller
     *                          shows the message as a friendly error.
     */
    public static function createForCustomer(
        Customer $customer,
        ?Program $program,
        ?Bundle $bundle,
        ?float $agreedAmount = null,
        ?string $specialRequest = null,
        ?int $paymentMethodId = null,
    ): self {
        if (($program === null) === ($bundle === null)) {
            throw new RuntimeException('יש לבחור תוכנית אחת או מארז אחד בלבד עבור העסקה — לא שניהם ולא אף אחד (FR-3.3).');
        }

        if ($program && ! $program->is_active) {
            throw new RuntimeException('לא ניתן ליצור עסקה עבור תוכנית שהושבתה (FR-8.5).');
        }

        if ($bundle && ! $bundle->is_active) {
            throw new RuntimeException('לא ניתן ליצור עסקה עבור מארז שהושבת (FR-8.5).');
        }

        $status = StatusDefinition::firstOrCreate(
            ['scope' => 'deal', 'name' => self::DEFAULT_STATUS_NAME],
            ['is_active' => true, 'sort_order' => 1],
        );

        $snapshotPrice = (float) ($program->price ?? $bundle->price);

        $defaultAgreedAmount = $snapshotPrice;

        if ($program && $program->is_premium && self::customerHasActiveSubscription($customer)) {
            $defaultAgreedAmount = round($snapshotPrice * 0.9, 2);
        }

        $deal = self::create([
            'customer_id' => $customer->id,
            'program_id' => $program?->id,
            'bundle_id' => $bundle?->id,
            'status_id' => $status->id,
            'agreed_amount' => $agreedAmount ?? $defaultAgreedAmount,
            'program_price_snapshot' => $program?->price,
            'bundle_price_snapshot' => $bundle?->price,
            'program_name_snapshot' => $program?->name,
            'bundle_name_snapshot' => $bundle?->name,
            'payment_method_id' => $paymentMethodId,
            'special_request' => $specialRequest,
            'purchased_at' => now(),
            'version' => 0,
        ]);

        if ($program) {
            $deal->openSubscriptionIfApplicable($program);
            $deal->assignMailingListsForProgramPurchase($program);
        }

        return $deal;
    }

    /**
     * Build-plan 10 (FR-5.22/FR-5.23): purchasing a plain program joins the
     * customer to that program's own mailing list. Purchasing the
     * subscription-type program additionally joins the "subscribers" list
     * plus every list in Program::scopeMonthlyCatalog() — see that scope's
     * docblock for exactly what "every program included in the subscription"
     * means here (this stage's judgment call, independent of build-plan 09's
     * per-slot delivery picks). Never touched for a bundle purchase — FR-5.22
     * only ever concerns a program.
     *
     * TODO(stage 12 — Smove): this membership change should also be pushed
     * to Smove (docs/erd.md: "קריאות ל-Smove, לא רק שינוי מקומי") — for now
     * it is 100% real and local only, same stub boundary as
     * ExternalIntegrationSetting elsewhere in this codebase.
     */
    private function assignMailingListsForProgramPurchase(Program $program): void
    {
        MailingMembership::addCustomer(MailingList::forProgram($program), $this->customer);

        if (! $program->is_subscription_type) {
            return;
        }

        MailingMembership::addCustomer(MailingList::subscribersList(), $this->customer);

        Program::monthlyCatalog()->get()->each(
            fn (Program $monthly) => MailingMembership::addCustomer(MailingList::forProgram($monthly), $this->customer)
        );
    }

    /**
     * FR-3.4/FR-3.12/FR-3.13: when the deal's program is the subscription-
     * type program (build-plan 03's is_subscription_type discriminator),
     * deal creation opens a real SUBSCRIPTION row plus exactly 10
     * SUBSCRIPTION_DELIVERY rows (one per future program slot) — the
     * specific catalog program for each slot is chosen later, at the moment
     * it's marked supplied (Subscription::markDeliverySupplied()), not
     * upfront. The deal itself is always created normally either way — this
     * only ever adds to it, never changes deal creation itself.
     */
    public function openSubscriptionIfApplicable(?Program $program = null): void
    {
        $program ??= $this->program;

        if (! $program?->is_subscription_type) {
            return;
        }

        $activeStatus = StatusDefinition::firstOrCreate(
            ['scope' => 'subscription', 'name' => Subscription::ACTIVE_STATUS_NAME],
            ['is_active' => true, 'sort_order' => 1],
        );

        $subscription = Subscription::create([
            'customer_id' => $this->customer_id,
            'deal_id' => $this->id,
            'status_id' => $activeStatus->id,
            'start_date' => now()->toDateString(),
            'agreed_price' => $this->agreed_amount,
            'version' => 0,
        ]);

        for ($sequenceNumber = 1; $sequenceNumber <= Subscription::TOTAL_DELIVERIES; $sequenceNumber++) {
            $subscription->deliveries()->create([
                'sequence_number' => $sequenceNumber,
                'program_id' => null,
                'is_supplied' => false,
            ]);
        }
    }

    /** FR-3.11's prerequisite: does $customer currently have an active subscription? */
    private static function customerHasActiveSubscription(Customer $customer): bool
    {
        return Subscription::where('customer_id', $customer->id)
            ->whereHas('status', fn ($q) => $q->where('name', Subscription::ACTIVE_STATUS_NAME))
            ->exists();
    }

    /**
     * FR-8.19 (this stage's slice): optimistic-locking status update — the
     * caller supplies the `version` it loaded the deal with; the UPDATE only
     * takes effect `WHERE id = ? AND version = ?`. A 0-row result means
     * another user changed the deal in the meantime, surfaced as a friendly
     * conflict error rather than silently overwritten.
     *
     * @throws RuntimeException on a version conflict.
     */
    public function updateStatusWithLock(int $expectedVersion, int $newStatusId): void
    {
        $newStatus = StatusDefinition::findOrFail($newStatusId);

        $attributes = ['status_id' => $newStatusId, 'version' => DB::raw('version + 1')];

        if (in_array($newStatus->name, self::TERMINAL_STATUS_NAMES, true) && ! $this->completed_at) {
            $attributes['completed_at'] = now();
        }

        $affected = self::where('id', $this->id)
            ->where('version', $expectedVersion)
            ->update($attributes);

        if ($affected === 0) {
            throw new RuntimeException('העסקה עודכנה על ידי משתמשת אחרת בינתיים — נא לרענן ולנסות שוב.');
        }

        $this->refresh();
    }

    /**
     * FR-4.30/FR-4.31: a deal's payment method may be changed freely only
     * until an actual payment has been recorded against it — once any
     * Payment row exists, further changes are blocked (the value itself is
     * unaffected — passing the current value back is always a no-op).
     *
     * @throws RuntimeException when payments already exist and a real change is attempted.
     */
    public function updatePaymentMethod(?int $paymentMethodId): void
    {
        if ($paymentMethodId === $this->payment_method_id) {
            return;
        }

        if ($this->payments()->exists()) {
            throw new RuntimeException('לא ניתן לשנות את אמצעי התשלום לאחר שהתקבל תשלום בפועל עבור העסקה (FR-4.30/FR-4.31).');
        }

        $this->update(['payment_method_id' => $paymentMethodId]);
    }

    /**
     * FR-8.19 (this stage's slice): the only place a Payment row is ever
     * created, guarded by the exact same optimistic-locking pattern as
     * updateStatusWithLock() above — the caller supplies the deal's
     * `version`; the atomic `WHERE id = ? AND version = ?` update is what
     * actually "reserves" this recording, so two users recording a payment
     * on the same deal at the same time can never both succeed against a
     * stale version (one gets the friendly conflict below and must reload).
     *
     * FR-4.32/FR-4.33: the deal only flips to PAID_STATUS_NAME once the sum
     * of its payments (including this one) reaches agreed_amount — a
     * partial payment leaves the deal's current status untouched.
     *
     * FR-4.39/FR-4.40: $amount is accepted as-is even when it differs from
     * agreed_amount (e.g. a network-institution branch paying a
     * network-negotiated amount) — never blocked here, only tracked.
     *
     * @throws RuntimeException on a version conflict, or when the deal has
     *                          no payment method set yet (FR-4.30).
     */
    public function recordPayment(int $expectedVersion, float $amount, ?string $paymentDate = null): Payment
    {
        if (! $this->payment_method_id) {
            throw new RuntimeException('יש לבחור אמצעי תשלום לעסקה לפני רישום תשלום (FR-4.30).');
        }

        if ($amount <= 0) {
            throw new RuntimeException('סכום התשלום חייב להיות גדול מאפס.');
        }

        return DB::transaction(function () use ($expectedVersion, $amount, $paymentDate) {
            $prospectiveTotal = $this->totalPaid() + $amount;
            $becomesFullyPaid = $prospectiveTotal >= (float) $this->agreed_amount;

            $attributes = ['version' => DB::raw('version + 1')];

            if ($becomesFullyPaid && $this->status?->name !== self::PAID_STATUS_NAME) {
                $paidStatus = StatusDefinition::firstOrCreate(
                    ['scope' => 'deal', 'name' => self::PAID_STATUS_NAME],
                    ['is_active' => true, 'sort_order' => 3],
                );
                $attributes['status_id'] = $paidStatus->id;
                $attributes['completed_at'] = $this->completed_at ?? now();
            }

            $affected = self::where('id', $this->id)
                ->where('version', $expectedVersion)
                ->update($attributes);

            if ($affected === 0) {
                throw new RuntimeException('העסקה עודכנה על ידי משתמשת אחרת בינתיים — נא לרענן ולנסות שוב.');
            }

            $paymentStatus = StatusDefinition::firstOrCreate(
                ['scope' => 'payment', 'name' => $becomesFullyPaid ? 'שולם' : 'חלקי'],
                ['is_active' => true, 'sort_order' => $becomesFullyPaid ? 2 : 3],
            );

            $isCheck = $this->paymentMethod?->type === 'check';

            $payment = $this->payments()->create([
                'payment_method_id' => $this->payment_method_id,
                'status_id' => $paymentStatus->id,
                'amount' => $amount,
                'check_status' => $isCheck ? Payment::CHECK_RECEIVED : null,
                'payment_date' => $paymentDate ?? now(),
                'cleared_date' => null,
            ]);

            $this->refresh();

            return $payment;
        });
    }
}
