<?php

namespace App\Models;

use App\Services\Integrations\ExternalOperationRunner;
use App\Services\Integrations\SummitClient;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Build-plan 08 — RECEIPT. issueFor() below is the only place a row here is
 * ever created — same "sole creation point" convention as
 * Document::generateFor()/Deal::createForCustomer() — and enforces every
 * receipt-related business rule (FR-4.5, FR-4.23-FR-4.29) in one place.
 *
 * Build-plan 12: issueFor() also pushes the receipt to Summit right here —
 * unlike an invoice/credit note (Document::sendTo(), pushed at "sent"),
 * generating a receipt IS the single explicit action for this document type
 * (there's no separate later send step), so this is its one wiring point.
 * Non-blocking (see ExternalOperationRunner's docblock) — the local receipt
 * row is created and returned regardless of whether Summit succeeds.
 */
#[Fillable(['payment_id', 'document_id', 'status', 'issued_before_payment', 'issued_at'])]
class Receipt extends Model
{
    use HasFactory;

    public const STATUS_ISSUED = 'הופקה';

    protected function casts(): array
    {
        return [
            'issued_before_payment' => 'boolean',
            'issued_at' => 'datetime',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * The only place a Receipt row is ever created.
     *
     * $payment omitted (null) is FR-4.24/FR-4.25's explicit exceptional
     * path — "קבלה לפני תשלום" — which never touches the deal's paid status
     * (that only ever changes inside Deal::recordPayment()).
     *
     * @throws RuntimeException on a business-rule violation.
     */
    public static function issueFor(Deal $deal, ?Payment $payment, ExternalOperationRunner $runner, SummitClient $summit): self
    {
        $invoice = $deal->documents()->where('document_type', 'invoice')->latest('id')->first();

        if (! $invoice) {
            throw new RuntimeException('לא ניתן להפיק קבלה לעסקה שאין לה חשבונית.');
        }

        if ($payment) {
            if ($payment->deal_id !== $deal->id) {
                throw new RuntimeException('התשלום שנבחר אינו שייך לעסקה זו.');
            }

            if ($payment->isCheck() && ! $payment->cleared_date) {
                throw new RuntimeException('לא ניתן להפיק קבלה עבור צ\'ק שטרם נפרע — קבלה מופקת רק בעת פירעון בפועל.');
            }

            if (self::where('payment_id', $payment->id)->exists()) {
                throw new RuntimeException('כבר הופקה קבלה עבור תשלום זה — לא ניתן להפיק יותר מקבלה אחת לאותו תשלום.');
            }
        }

        $receipt = self::create([
            'payment_id' => $payment?->id,
            'document_id' => $invoice->id,
            'status' => self::STATUS_ISSUED,
            'issued_before_payment' => $payment === null,
            'issued_at' => now(),
        ]);

        $runner->run(
            'summit',
            'issue_receipt',
            'app_action',
            fn () => $summit->issueReceipt($receipt),
            [
                'document_id' => $invoice->id,
                'payment_id' => $payment?->id,
                'deal_id' => $deal->id,
                'description' => "הפקת קבלה מול Summit עבור עסקה #{$deal->id}",
            ],
        );

        return $receipt;
    }
}
