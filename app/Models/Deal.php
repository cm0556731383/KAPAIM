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
     * that price (editable by the caller before/at creation — FR-3.11's
     * premium-subscriber discount is out of scope for this stage).
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

        $deal = self::create([
            'customer_id' => $customer->id,
            'program_id' => $program?->id,
            'bundle_id' => $bundle?->id,
            'status_id' => $status->id,
            'agreed_amount' => $agreedAmount ?? $snapshotPrice,
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
        }

        return $deal;
    }

    /**
     * FR-3.4 stub: when the deal's program is the subscription-type program
     * (build-plan 03's is_subscription_type discriminator), deal creation
     * should route into the subscription-opening flow instead of behaving
     * like a plain one-off sale. Subscriptions (SUBSCRIPTION,
     * SUBSCRIPTION_PROGRAM_DELIVERY) are build-plan stage 9, which doesn't
     * exist yet — same stub pattern as Lead::joinPrimaryMailingList()
     * (FR-1.17, stage 10). Deliberately a no-op: the deal itself is still
     * created normally either way.
     */
    public function openSubscriptionIfApplicable(?Program $program = null): void
    {
        $program ??= $this->program;

        if (! $program?->is_subscription_type) {
            return;
        }

        // TODO(stage 9 — subscriptions): open a SUBSCRIPTION row for this
        // deal here instead of treating it as a single-program sale.
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
}
