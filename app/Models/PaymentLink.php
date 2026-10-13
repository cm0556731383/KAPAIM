<?php

namespace App\Models;

use App\Services\ActivityLogger;
use App\Services\Integrations\ExternalOperationRunner;
use App\Services\Integrations\SmoveClient;
use App\Services\Integrations\SummitClient;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * A SUMIT payment page sent to the customer for a deal — the customer
 * pays by herself, instead of the secretary charging a card SUMIT already
 * holds (Deal::chargeCardViaSummit()).
 *
 * Lifecycle: createFor() opens the page in SUMIT; send() emails the link
 * (through Smove, like every business email); complete() records the payment
 * once SUMIT confirms it — called when the customer comes back from the page
 * (routes/web.php's payment-links.return) and by the payment sync
 * (sumit:sync-payments) for one who closed the page before returning. SUMIT
 * itself issues the receipt for the payment and emails it to her, so the
 * local payment is linked to it rather than issuing another
 * (Deal::recordSumitPayment()).
 */
#[Fillable([
    'deal_id', 'payment_id', 'created_by_user_id', 'token', 'amount', 'purpose', 'url', 'status',
    'sent_to_email', 'sent_at', 'expires_at', 'paid_at',
])]
class PaymentLink extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    /** A one-off payment. */
    public const PURPOSE_PAYMENT = 'payment';

    /**
     * The first month of a standing order: SUMIT's payment page takes one-off
     * payments only, so she pays the first month there — which saves her card
     * in SUMIT — and the standing order for the rest of the balance, at the
     * same monthly amount, is registered on that card once it's paid.
     */
    public const PURPOSE_STANDING_ORDER = 'standing_order';

    /** SUMIT's maximum (10 days). */
    public const EXPIRATION_HOURS = 240;

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'sent_at' => 'datetime',
            'expires_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /** What SUMIT echoes back on the payment, matching it to this link. */
    public function externalIdentifier(): string
    {
        return "kapaim-paylink-{$this->id}";
    }

    public static function idFromExternalIdentifier(?string $identifier): ?int
    {
        return preg_match('/^kapaim-paylink-(\d+)$/', (string) $identifier, $m) ? (int) $m[1] : null;
    }

    public function isStandingOrder(): bool
    {
        return $this->purpose === self::PURPOSE_STANDING_ORDER;
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    public function isExpired(): bool
    {
        return ! $this->isPaid() && $this->expires_at?->isPast();
    }

    public function statusLabel(): string
    {
        return match (true) {
            $this->isPaid() => 'שולם',
            $this->isExpired() => 'פג תוקף',
            default => 'ממתין לתשלום',
        };
    }

    /**
     * Opens a SUMIT payment page for $amount of $deal.
     *
     * @throws RuntimeException on a business-rule violation or a SUMIT failure.
     */
    public static function createFor(Deal $deal, float $amount, ExternalOperationRunner $runner, SummitClient $summit, string $purpose = self::PURPOSE_PAYMENT): self
    {
        if ($purpose === self::PURPOSE_STANDING_ORDER) {
            if ($deal->standingOrderItemIds() !== []) {
                throw new RuntimeException('לעסקה זו כבר רשומה הוראת קבע ב-SUMIT.');
            }

            if ($deal->subscription && ! $deal->subscription->isActive()) {
                throw new RuntimeException('המנוי של העסקה אינו פעיל — לא ניתן להקים עבורו הוראת קבע.');
            }
        }

        if (! $deal->issuedInvoice()) {
            throw new RuntimeException('יש להפיק חשבונית לעסקה לפני שליחת קישור לתשלום — הקבלה שתופק עם התשלום נשענת עליה.');
        }

        if (Receipt::whereHas('document', fn ($q) => $q->where('deal_id', $deal->id))->where('issued_before_payment', true)->exists()) {
            throw new RuntimeException('לעסקה הופקה קבלה לפני תשלום על מלוא הסכום — תשלום בקישור יפיק קבלה נוספת. יש לרשום את התשלום ידנית.');
        }

        if ($amount <= 0) {
            throw new RuntimeException('סכום לתשלום חייב להיות גדול מאפס.');
        }

        if ($amount > $deal->outstandingBalance() + 0.001) {
            throw new RuntimeException('הסכום גבוה מיתרת החוב של העסקה (₪'.number_format($deal->outstandingBalance(), 2).').');
        }

        $link = self::create([
            'deal_id' => $deal->id,
            'created_by_user_id' => auth()->id(),
            'token' => Str::random(40),
            'amount' => $amount,
            'purpose' => $purpose,
            'status' => self::STATUS_PENDING,
            'expires_at' => now()->addHours(self::EXPIRATION_HOURS),
        ]);

        $operation = $runner->run('summit', 'payment_link_created', 'user_action', function () use ($link, $summit) {
            $link->update(['url' => $summit->createPaymentPage($link)]);

            return $link->externalIdentifier();
        }, [
            'deal_id' => $deal->id,
            'description' => "יצירת קישור לתשלום בסך ₪{$amount} עבור עסקה #{$deal->id}",
        ]);

        if ($operation->failed()) {
            $link->delete();

            throw new RuntimeException("יצירת דף התשלום ב-SUMIT נכשלה: {$operation->error_message}");
        }

        return $link->refresh();
    }

    /**
     * Emails the link to $email through Smove.
     *
     * @throws RuntimeException when the email couldn't be sent.
     */
    public function send(string $email, string $name, ExternalOperationRunner $runner, SmoveClient $smove): void
    {
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('כתובת הדוא"ל של הנמענת אינה תקינה.');
        }

        $deal = $this->deal;
        $item = $deal->program_name_snapshot ?? $deal->bundle_name_snapshot ?? "עסקה #{$deal->id}";
        $amount = number_format((float) $this->amount, 2);

        if ($this->isStandingOrder()) {
            $plan = $deal->standingOrderPlan((float) $this->amount);
            $subject = "הקמת הוראת קבע — {$item}";
            $intro = "להקמת הוראת קבע עבור \"{$item}\": ₪{$amount} לחודש, {$plan['months']} חודשים. "
                ."התשלום הראשון מתבצע עכשיו בכרטיס אשראי בדף התשלום המאובטח של SUMIT, והתשלומים הבאים יחויבו אוטומטית מאותו כרטיס בכל חודש. "
                .'על כל תשלום תישלח אלייך קבלה במייל.';
        } else {
            $subject = "קישור לתשלום — {$item}";
            $intro = "לתשלום עבור \"{$item}\" בסך ₪{$amount}, בכרטיס אשראי בדף התשלום המאובטח של SUMIT. הקבלה תישלח אלייך במייל מיד עם התשלום.";
        }

        $operation = $runner->run('smove', 'payment_link_send', 'app_action',
            fn () => $smove->sendPaymentLinkEmail(
                $email,
                $name,
                $subject,
                $intro,
                $this->url,
                'הקישור בתוקף עד '.$this->expires_at->format('d/m/Y').'.',
            ),
            ['deal_id' => $deal->id, 'description' => "שליחת קישור לתשלום במייל עבור עסקה #{$deal->id}"],
        );

        if ($operation->failed()) {
            throw new RuntimeException("שליחת המייל נכשלה: {$operation->error_message}. ניתן להעתיק את הקישור ולשלוח אותו ידנית.");
        }

        $this->update(['sent_to_email' => $email, 'sent_at' => now()]);
    }

    /**
     * Records the payment once SUMIT confirms $sumitPaymentId belongs to this
     * link and went through. Idempotent — returning from the page, a page
     * refresh and the sync can all call it. Returns whether the link is paid.
     */
    public function complete(array $sumitPayment, ExternalOperationRunner $runner, ActivityLogger $logger): bool
    {
        if (self::idFromExternalIdentifier($sumitPayment['ExternalIdentifier'] ?? null) !== $this->id || ! ($sumitPayment['ValidPayment'] ?? false)) {
            return $this->isPaid();
        }

        $payment = $this->deal->recordSumitPayment($sumitPayment, $runner, $this->isStandingOrder() ? 'recurring' : 'card');

        if (! $payment) {
            return false;
        }

        if (! $this->isPaid()) {
            $this->update(['status' => self::STATUS_PAID, 'payment_id' => $payment->id, 'paid_at' => now()]);

            $logger->log('payment.recorded', "התקבל תשלום בקישור SUMIT בסך ₪{$payment->amount} עבור עסקה #{$this->deal_id}", [
                'deal_id' => $this->deal_id,
                'customer_id' => $this->deal->customer_id,
                'user' => null,
                'metadata' => ['amount' => (float) $payment->amount, 'payment_id' => $payment->id, 'payment_link_id' => $this->id],
            ]);

            if ($this->isStandingOrder()) {
                $this->registerStandingOrder($runner);
            }
        }

        return true;
    }

    /**
     * The first month is paid and her card is saved in SUMIT: registers the
     * standing order for the rest of the balance at the same monthly amount,
     * from next month. A failure is recorded by the runner (the deal screen
     * still offers registering it from the saved card by hand).
     */
    private function registerStandingOrder(ExternalOperationRunner $runner): void
    {
        $deal = $this->deal->fresh();

        if ($deal->standingOrderItemIds() !== [] || $deal->outstandingBalance() <= 0) {
            return;
        }

        try {
            $deal->registerStandingOrderWithSummit($runner, app(SummitClient::class), (float) $this->amount);
        } catch (RuntimeException) {
        }
    }
}
