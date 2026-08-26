<?php

namespace App\Models;

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
 * when the purchased program is build-plan 03's is_subscription_type
 * discriminator) — never created directly by UI/controller code, the same
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
    'customer_id', 'deal_id', 'status_id', 'start_date', 'end_date',
    'cancelled_at', 'agreed_price', 'cancellation_credit', 'monthly_payment_override', 'version',
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
            'end_date' => 'date',
            'cancelled_at' => 'date',
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

        $invoice = $this->deal->documents()->where('document_type', 'invoice')->latest('id')->first();
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
        if (! $this->isActive()) {
            throw new RuntimeException('ניתן לסמן אספקה רק עבור מנוי פעיל.');
        }

        $delivery = $this->deliveries()->whereKey($deliveryId)->firstOrFail();

        if ($delivery->is_supplied) {
            throw new RuntimeException('שורת אספקה זו כבר סומנה כסופקה.');
        }

        $program = Program::find($programId);

        if (! $program || ! $program->is_active || $program->is_subscription_type) {
            throw new RuntimeException('יש לבחור תוכנית קטלוג פעילה (שאינה תוכנית מנוי) עבור שורת האספקה (FR-3.14).');
        }

        return DB::transaction(function () use ($expectedVersion, $delivery, $program, $user) {
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

            if ($this->suppliedCount() >= self::TOTAL_DELIVERIES) {
                $ended = StatusDefinition::firstOrCreate(
                    ['scope' => 'subscription', 'name' => self::ENDED_STATUS_NAME],
                    ['is_active' => true, 'sort_order' => 2],
                );

                $this->update(['status_id' => $ended->id, 'end_date' => now()->toDateString()]);
            }

            return $delivery->refresh();
        });
    }

    /**
     * US-010/FR-8.24: computes the cancellation credit dynamically from this
     * subscription's OWN delivery log and OWN agreed_price only —
     * agreed_price/10 per program, times the number of programs NOT yet
     * supplied. Bundles and any other deal are never part of this
     * calculation (FR-3.8) since neither is ever referenced here.
     *
     * Marks the subscription cancelled (status change only — never deletes
     * the deal, this row, the delivery history, or any activity log, per
     * US-010's explicit acceptance criterion). Generating an actual credit
     * note is a deliberately separate, explicit step — see
     * generateCreditNote() below — not auto-triggered here (same convention
     * as issuing a receipt being separate from recording a payment, stage 8).
     *
     * @throws RuntimeException when the subscription isn't currently active.
     */
    public function cancel(): self
    {
        if (! $this->isActive()) {
            throw new RuntimeException('ניתן לבטל רק מנוי פעיל.');
        }

        $suppliedCount = $this->suppliedCount();
        $undeliveredCount = self::TOTAL_DELIVERIES - $suppliedCount;
        $credit = round(((float) $this->agreed_price / self::TOTAL_DELIVERIES) * $undeliveredCount, 2);

        $cancelledStatus = StatusDefinition::firstOrCreate(
            ['scope' => 'subscription', 'name' => self::CANCELLED_STATUS_NAME],
            ['is_active' => true, 'sort_order' => 3],
        );

        $this->update([
            'status_id' => $cancelledStatus->id,
            'cancelled_at' => now()->toDateString(),
            'cancellation_credit' => $credit,
        ]);

        return $this->refresh();
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
        if ($this->status?->name !== self::CANCELLED_STATUS_NAME) {
            throw new RuntimeException('ניתן להפיק חשבונית זיכוי רק עבור מנוי שבוטל.');
        }

        return Document::generateCreditNoteFor($this->deal, (float) $this->cancellation_credit);
    }
}
