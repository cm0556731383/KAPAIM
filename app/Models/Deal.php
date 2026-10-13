<?php

namespace App\Models;

use App\Services\Integrations\ExternalOperationRunner;
use App\Services\Integrations\SummitClient;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Cache;
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
    'customer_id', 'program_id', 'bundle_id', 'status_id', 'agreed_amount', 'students_count',
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

    /** The SUMIT company this deal is locked to — see lockToBusinessEntity(). */
    public function businessEntity(): BelongsTo
    {
        return $this->belongsTo(BusinessEntity::class);
    }

    /**
     * Once SUMIT issued anything for this deal (invoice, receipt, credit
     * note, charge, standing order — SummitClient calls this on success),
     * the deal is locked to that entity: every later SUMIT document for it
     * must come from the same one. The first lock wins (a plain conditional
     * UPDATE, outside the `version` optimistic lock — it's not a user edit).
     */
    public function lockToBusinessEntity(BusinessEntity $entity): void
    {
        if ($this->business_entity_id) {
            return;
        }

        self::whereKey($this->id)->whereNull('business_entity_id')->update(['business_entity_id' => $entity->id]);
        $this->business_entity_id = self::whereKey($this->id)->value('business_entity_id');
        $this->syncOriginalAttribute('business_entity_id');
        $this->unsetRelation('businessEntity');
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
     * The deal's latest invoice that was actually issued — sent (which,
     * for an invoice, only happens once Summit accepted it; see
     * Document::sendTo()) and with a real amount. A draft, an empty invoice,
     * or one whose Summit issuance failed doesn't count as invoiced anywhere:
     * monthly payment, receipts, credit notes, the dashboard.
     */
    public function issuedInvoice(): ?Document
    {
        return $this->documents()
            ->where('document_type', 'invoice')
            ->whereNotNull('sent_at')
            ->get()
            ->filter(fn (Document $invoice) => $invoice->totalAmount() > 0)
            ->last();
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

    /**
     * What credit notes issued in SUMIT took off the deal (e.g. a cancelled
     * subscription's credit). A draft credit note, or one SUMIT refused,
     * doesn't count — only a sent one (Document::sendTo()).
     */
    public function totalCredited(): float
    {
        return (float) DocumentLine::whereHas('document', fn ($q) => $q
            ->where('deal_id', $this->id)
            ->where('document_type', 'credit_note')
            ->whereNotNull('sent_at'))
            ->sum('amount');
    }

    /** What the customer owes in all: the agreed amount less what was credited. */
    public function amountDue(): float
    {
        return max(0.0, (float) $this->agreed_amount - $this->totalCredited());
    }

    /** FR-4.33: the balance still owed — visible on the deal/customer card. */
    public function outstandingBalance(): float
    {
        return max(0.0, $this->amountDue() - $this->totalPaid());
    }

    public function isFullyPaid(): bool
    {
        return $this->totalPaid() >= $this->amountDue();
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
     * @param  ?\DateTimeInterface  $purchasedAt  Build-plan 18 (FR-8.21): lets the
     *                                            legacy-data importer backdate a
     *                                            historical deal's purchase date —
     *                                            every other caller omits this and
     *                                            gets today, exactly as before.
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
        ?\DateTimeInterface $purchasedAt = null,
    ): self {
        if (($program === null) === ($bundle === null)) {
            throw new RuntimeException('יש לבחור תוכנית אחת או מארז אחד בלבד עבור העסקה — לא שניהם ולא אף אחד.');
        }

        if ($program && ! $program->is_active) {
            throw new RuntimeException('לא ניתן ליצור עסקה עבור תוכנית שהושבתה.');
        }

        if ($bundle && ! $bundle->is_active) {
            throw new RuntimeException('לא ניתן ליצור עסקה עבור מארז שהושבת.');
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
            'purchased_at' => $purchasedAt ?? now(),
            'version' => 0,
        ]);

        if ($program) {
            $deal->assignMailingListsForProgramPurchase($program);
        } elseif ($bundle) {
            $deal->openSubscriptionIfApplicable($bundle);
            $deal->assignMailingListsForBundlePurchase($bundle);
        }

        return $deal;
    }

    /**
     * Build-plan 10 (FR-5.22): purchasing a plain program joins the customer
     * to that program's own mailing list. Never touched for a bundle
     * purchase — FR-5.22 only ever concerns a program.
     *
     * Build-plan 12: MailingMembership::addCustomer() below now also pushes
     * each membership change to Smove for real (docs/erd.md: "קריאות
     * ל-Smove, לא רק שינוי מקומי") — nothing to change here, the push lives
     * entirely inside MailingMembership.
     */
    private function assignMailingListsForProgramPurchase(Program $program): void
    {
        MailingMembership::addCustomer(MailingList::forProgram($program), $this->customer);
    }

    /**
     * Build-plan 10 (FR-5.23), moved here 2026-09-09 along with
     * is_subscription_type itself (Program -> Bundle): purchasing the
     * subscription-type bundle joins the "subscribers" list plus every list
     * in Program::scopeMonthlyCatalog() — see that scope's docblock for
     * exactly what "every program included in the subscription" means here.
     * A plain (non-subscription-type) bundle purchase never joins any list
     * (FR-5.22 only ever concerns a program, or now the subscription bundle).
     */
    private function assignMailingListsForBundlePurchase(Bundle $bundle): void
    {
        if (! $bundle->is_subscription_type) {
            return;
        }

        MailingMembership::addCustomer(MailingList::subscribersList(), $this->customer);

        Program::monthlyCatalog()->get()->each(
            fn (Program $monthly) => MailingMembership::addCustomer(MailingList::forProgram($monthly), $this->customer)
        );
    }

    /**
     * FR-3.4/FR-3.12/FR-3.13: when the deal's bundle is the subscription-
     * type bundle (build-plan 03's is_subscription_type discriminator, moved
     * from Program to Bundle 2026-09-09), deal creation opens a real
     * SUBSCRIPTION row plus exactly 10 SUBSCRIPTION_DELIVERY rows (one per
     * future program slot) — the specific catalog program for each slot is
     * chosen later, at the moment it's marked supplied
     * (Subscription::markDeliverySupplied()), not upfront. The deal itself
     * is always created normally either way — this only ever adds to it,
     * never changes deal creation itself.
     */
    public function openSubscriptionIfApplicable(?Bundle $bundle = null): void
    {
        $bundle ??= $this->bundle;

        if (! $bundle?->is_subscription_type) {
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
     * The deal's planned payment method — information for the business owner
     * only (owner's call, 2026-10-13, replacing FR-4.30/FR-4.31's "one method,
     * locked after a payment"). Each payment carries its own method, chosen
     * when it's recorded, so a deal can be paid in several ways.
     */
    public function updatePaymentMethod(?int $paymentMethodId): void
    {
        if ($paymentMethodId !== $this->payment_method_id) {
            $this->update(['payment_method_id' => $paymentMethodId]);
        }
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
     * @throws RuntimeException on a version conflict, or when no payment
     *                          method is given and the deal has none.
     */
    public function recordPayment(int $expectedVersion, float $amount, ?string $paymentDate = null, ?int $paymentMethodId = null, ?string $checkNumber = null, ?string $checkDueDate = null): Payment
    {
        // The payment's own method; the deal's planned one when none is given.
        $method = PaymentMethod::find($paymentMethodId ?? $this->payment_method_id);

        if (! $method) {
            throw new RuntimeException('יש לבחור אמצעי תשלום לפני רישום תשלום.');
        }

        if ($amount <= 0) {
            throw new RuntimeException('סכום התשלום חייב להיות גדול מאפס.');
        }

        return DB::transaction(function () use ($expectedVersion, $amount, $paymentDate, $method, $checkNumber, $checkDueDate) {
            $prospectiveTotal = $this->totalPaid() + $amount;
            $becomesFullyPaid = $prospectiveTotal >= $this->amountDue();

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

            $isCheck = $method->type === 'check';

            $payment = $this->payments()->create([
                'payment_method_id' => $method->id,
                'status_id' => $paymentStatus->id,
                'amount' => $amount,
                'check_status' => $isCheck ? Payment::CHECK_RECEIVED : null,
                'check_number' => $isCheck ? ($checkNumber ?: null) : null,
                'check_due_date' => $isCheck ? ($checkDueDate ?: null) : null,
                'payment_date' => $paymentDate ?? now(),
                'cleared_date' => null,
            ]);

            $this->refresh();

            return $payment;
        });
    }

    /**
     * Several checks received together (often post-dated, one per month) —
     * each a check payment of its own with its number and due date; its
     * receipt is issued when it clears. All or none: a failure records none.
     *
     * @param  array<int, array{number: string, due_date: string, amount: float|string}>  $checks
     * @return array<int, Payment>
     *
     * @throws RuntimeException on a version conflict or when there's no check.
     */
    public function recordChecks(int $expectedVersion, array $checks, ?int $paymentMethodId = null): array
    {
        $method = PaymentMethod::find($paymentMethodId) ?? PaymentMethod::forType('check');

        if ($method->type !== 'check') {
            throw new RuntimeException("אמצעי התשלום שנבחר אינו צ'קים.");
        }

        if ($checks === []) {
            throw new RuntimeException("יש להזין לפחות צ'ק אחד.");
        }

        return DB::transaction(function () use ($expectedVersion, $checks, $method) {
            $version = $expectedVersion;
            $payments = [];

            foreach ($checks as $check) {
                $payments[] = $this->recordPayment($version, (float) $check['amount'], null, $method->id, (string) $check['number'], (string) $check['due_date']);
                $version = $this->version;
            }

            return $payments;
        });
    }

    /** Payment pages sent to the customer for this deal (PaymentLink). */
    public function paymentLinks(): HasMany
    {
        return $this->hasMany(PaymentLink::class)->orderByDesc('id');
    }

    /**
     * Records a payment SUMIT already took — a paid payment page
     * (PaymentLink::complete()) or a standing-order collection found by the
     * payment sync (sumit:sync-payments). $sumitPayment is SUMIT's Payment
     * object. Recorded once per SUMIT payment ID, however many times it's
     * seen (the customer's return from the payment page and the sync can
     * both report it). SUMIT already issued the receipt for these
     * (DocumentID) and emailed it, so none is issued here — it's linked.
     *
     * Returns null for a payment SUMIT reports as not valid.
     */
    public function recordSumitPayment(array $sumitPayment, ExternalOperationRunner $runner, string $methodType = 'card'): ?Payment
    {
        $sumitId = (string) ($sumitPayment['ID'] ?? '');

        if ($sumitId === '' || ! ($sumitPayment['ValidPayment'] ?? false)) {
            return null;
        }

        $lock = Cache::lock("sumit-payment-{$sumitId}", 60);
        $lock->block(30);

        try {
            if ($existing = Payment::where('sumit_payment_id', $sumitId)->first()) {
                return $existing;
            }

            $this->refresh();
            $documentId = (string) ($sumitPayment['DocumentID'] ?? '');
            $documentId = $documentId !== '' && $documentId !== '0' ? $documentId : null;

            $payment = $this->recordPayment($this->version, (float) $sumitPayment['Amount'], $sumitPayment['Date'] ?? null, PaymentMethod::forType($methodType)->id);
            $payment->update(['sumit_payment_id' => $sumitId, 'sumit_document_id' => $documentId]);

            if ($documentId) {
                Receipt::linkIssuedBySumit($this, $payment, $documentId, $runner);
            }

            return $payment;
        } finally {
            $lock->release();
        }
    }

    /**
     * Build-plan 12 — PRD §1.4 "סליקת אשראי": an explicit, user-triggered
     * action (never automatic on recordPayment() — the secretary decides
     * when to actually run the card) that charges via Summit FIRST, and only
     * records the local Payment once Summit confirms it (unlike every other
     * payment method, where the money already changed hands before the
     * secretary opens this screen). recordPayment() still enforces every one
     * of its own rules (FR-4.30 payment method, FR-8.19 lock) exactly as for
     * any other payment.
     *
     * @throws RuntimeException when the deal's payment method isn't a card,
     *                          or Summit declines/fails the charge.
     */
    public function chargeCardViaSummit(int $expectedVersion, float $amount, ExternalOperationRunner $runner, SummitClient $summit): Payment
    {
        // The version check must come before the card is charged: once SUMIT
        // took the money, the payment is recorded no matter what (below).
        if ((int) self::whereKey($this->id)->value('version') !== $expectedVersion) {
            throw new RuntimeException('העסקה עודכנה על ידי משתמשת אחרת בינתיים — נא לרענן ולנסות שוב.');
        }

        $operation = $runner->run(
            'summit',
            'card_charge',
            'user_action',
            fn () => $summit->chargeCard($this, $amount),
            [
                'deal_id' => $this->id,
                'description' => "סליקת אשראי בסך ₪{$amount} עבור עסקה #{$this->id}",
            ],
        );

        if ($operation->failed()) {
            throw new RuntimeException("הסליקה מול Summit נכשלה: {$operation->error_message}");
        }

        // The card was charged — record it against the current version even if
        // the deal changed meanwhile, so a charge is never left unrecorded.
        return $this->recordPayment((int) self::whereKey($this->id)->value('version'), $amount, null, PaymentMethod::forType('card')->id);
    }

    /**
     * Build-plan 12 — PRD §1.4 "ניהול הוראות קבע": sets up the monthly
     * recurring charge in SUMIT (SummitClient::registerStandingOrder()).
     * SUMIT charges the first month at once — that payment and its receipt
     * are recorded right here — and every later month by itself; the payment
     * sync (sumit:sync-payments) records those as they're collected, finding
     * this deal by the recurring item IDs kept as the operation's reference
     * (standingOrderItemIds()). SUMIT issues and emails each month's receipt
     * (FR-4.27 — automatic, no staff action).
     *
     * @throws RuntimeException when one is already registered, the deal is
     *                          paid or its subscription cancelled, or SUMIT
     *                          fails the request.
     */
    public function registerStandingOrderWithSummit(ExternalOperationRunner $runner, SummitClient $summit, ?float $monthly = null): ?Payment
    {
        if ($this->standingOrderItemIds() !== []) {
            throw new RuntimeException('לעסקה זו כבר נרשמה הוראת קבע ב-SUMIT — רישום נוסף יחייב את הלקוחה פעמיים.');
        }

        if ($this->outstandingBalance() <= 0) {
            throw new RuntimeException('העסקה שולמה במלואה — אין מה לגבות בהוראת קבע.');
        }

        if ($this->subscription && ! $this->subscription->isActive()) {
            throw new RuntimeException('המנוי של העסקה אינו פעיל — לא ניתן לרשום עבורו הוראת קבע.');
        }

        $result = [];
        $plan = $this->standingOrderPlan($monthly);

        $operation = $runner->run(
            'summit',
            'standing_order_registered',
            'user_action',
            function () use ($summit, $plan, &$result) {
                $result = $summit->registerStandingOrder($this, $plan);

                return implode(',', (array) ($result['RecurringCustomerItemIDs'] ?? []));
            },
            [
                'deal_id' => $this->id,
                'description' => "רישום הוראת קבע מול Summit עבור עסקה #{$this->id}",
            ],
        );

        if ($operation->failed()) {
            throw new RuntimeException("רישום הוראת הקבע מול Summit נכשל: {$operation->error_message}");
        }

        $firstCharge = (array) ($result['Payment'] ?? []);

        return $firstCharge
            ? $this->recordSumitPayment($firstCharge + ['DocumentID' => $result['DocumentID'] ?? null, 'Amount' => $plan['monthly']], $runner, 'recurring')
            : null;
    }

    /**
     * What the standing order charges: $monthly (or, by default, the
     * subscription's monthly payment — the whole balance with no
     * subscription) for as many months as the balance still needs. When the
     * deal was already paid in part — typically the first month, paid through
     * a standing-order payment link, which is also how the customer's card
     * gets saved in SUMIT — it starts next month, so that month isn't charged
     * twice.
     *
     * @return array{monthly: float, months: int, startDate: ?string}
     */
    public function standingOrderPlan(?float $monthly = null): array
    {
        $balance = $this->outstandingBalance();
        $monthly = min($monthly ?? ($this->subscription?->monthlyPayment() ?? $balance), $balance);

        return [
            'monthly' => round($monthly, 2),
            'months' => $monthly > 0 ? max(1, (int) ceil(round($balance / $monthly, 2))) : 1,
            'startDate' => $this->payments()->exists() ? now()->addMonthNoOverflow()->toDateString() : null,
        ];
    }

    /** SUMIT's recurring item IDs of this deal's registered standing order (empty when none). */
    public function standingOrderItemIds(): array
    {
        return ExternalOperation::where('deal_id', $this->id)
            ->where('system', 'summit')
            ->where('operation_type', 'standing_order_registered')
            ->where('status', ExternalOperation::STATUS_SUCCESS)
            ->pluck('external_reference')
            ->flatMap(fn ($reference) => array_filter(explode(',', (string) $reference)))
            ->values()
            ->all();
    }
}

