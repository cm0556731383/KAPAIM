<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use RuntimeException;

/**
 * Build-plan 08 — PAYMENT. The only place a row here is ever created is
 * Deal::recordPayment() — never directly by UI/controller code — so every
 * business rule (FR-4.30-FR-4.34) and the FR-8.19 optimistic lock live in
 * exactly one place, the same convention as Document::generateFor().
 *
 * check_status/cleared_date are only meaningful when paymentMethod's type is
 * 'check' (FR-4.28/FR-4.29): a check payment starts CHECK_RECEIVED and is
 * marked CHECK_CLEARED only via markCleared() below, a separate explicit
 * action — never inferred from any other field.
 */
#[Fillable([
    'deal_id', 'payment_method_id', 'status_id', 'amount',
    'check_status', 'check_number', 'check_due_date', 'check_bank_number', 'check_branch_number', 'check_account_number', 'payment_date', 'cleared_date', 'sumit_payment_id', 'sumit_document_id',
])]
class Payment extends Model
{
    use HasFactory, SoftDeletes;

    public const CHECK_RECEIVED = 'received';

    public const CHECK_CLEARED = 'cleared';

    /** Israeli bank codes (בנק ישראל) for a check's bank — the number SUMIT's receipt takes. */
    public const BANKS = [
        4 => 'בנק יהב', 9 => 'בנק הדואר', 10 => 'בנק לאומי', 11 => 'בנק דיסקונט', 12 => 'בנק הפועלים',
        13 => 'בנק אגוד', 14 => 'בנק אוצר החייל', 17 => 'בנק מרכנתיל דיסקונט', 18 => 'וואן זירו',
        20 => 'בנק מזרחי טפחות', 26 => 'יובנק', 31 => 'הבנק הבינלאומי', 46 => 'בנק מסד',
        52 => 'בנק פועלי אגודת ישראל', 54 => 'בנק ירושלים',
    ];

    /** "בנק הפועלים (12) · סניף 600 · חשבון 123456" — whichever of the three are known. */
    public function checkBankLabel(): ?string
    {
        $parts = array_filter([
            $this->check_bank_number ? (self::BANKS[$this->check_bank_number] ?? 'בנק').' ('.$this->check_bank_number.')' : null,
            $this->check_branch_number ? "סניף {$this->check_branch_number}" : null,
            $this->check_account_number ? "חשבון {$this->check_account_number}" : null,
        ]);

        return $parts ? implode(' · ', $parts) : null;
    }

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'payment_date' => 'datetime',
            'cleared_date' => 'datetime',
            'check_due_date' => 'date',
        ];
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(StatusDefinition::class, 'status_id');
    }

    /** FR-4.29: at most one receipt is ever issued for a given payment. */
    public function receipt(): HasOne
    {
        return $this->hasOne(Receipt::class);
    }

    /**
     * A payment may be corrected or removed (Deal::updatePayment()/
     * deletePayment()) only while it's purely local: no receipt issued for
     * it, not reported by SUMIT (a payment link / card charge / standing
     * order), and — for a check — not yet cleared. Anything past that is
     * already in the books.
     */
    public function isEditable(): bool
    {
        return ! $this->receipt && ! $this->sumit_payment_id && ! $this->sumit_document_id && ! $this->cleared_date;
    }

    public function isCheck(): bool
    {
        return $this->paymentMethod?->type === 'check';
    }

    /**
     * Whether this payment counts toward what the deal has been paid. A check
     * counts only once it cleared AND a receipt covers it — its own, one
     * SUMIT issued, or the deal's receipt-before-payment. Until then it's on
     * its way, and the deal is not "שולמה" (business owner's call, 2026-10-13).
     */
    public function isSettled(): bool
    {
        if (! $this->isCheck()) {
            return true;
        }

        if (! $this->cleared_date) {
            return false;
        }

        return $this->sumit_document_id || $this->receipt()->exists()
            || Receipt::where('issued_before_payment', true)->whereHas('document', fn ($q) => $q->where('deal_id', $this->deal_id))->exists();
    }

    /** An uncleared check whose due date has come — the dashboard asks whether to issue its receipt. */
    public function isCheckDue(): bool
    {
        return $this->check_status === self::CHECK_RECEIVED && ! $this->cleared_date
            && $this->check_due_date && ! $this->check_due_date->isFuture();
    }

    /** Uncleared checks due by today, soonest first. */
    public function scopeChecksDue($query)
    {
        return $query->where('check_status', self::CHECK_RECEIVED)
            ->whereNull('cleared_date')
            ->whereNotNull('check_due_date')
            ->whereDate('check_due_date', '<=', today())
            ->orderBy('check_due_date');
    }

    /**
     * FR-4.28: clearing a check is a separate explicit action from
     * "received" — this is the only place cleared_date is ever set.
     *
     * @throws RuntimeException when this isn't a check payment, or it's
     *                          already been marked cleared.
     */
    public function markCleared(): void
    {
        if (! $this->isCheck()) {
            throw new RuntimeException('ניתן לסמן כנפרע רק תשלום שאמצעי התשלום שלו הוא צ\'ק.');
        }

        if ($this->cleared_date) {
            throw new RuntimeException('הצ\'ק כבר סומן כנפרע.');
        }

        $this->update(['check_status' => self::CHECK_CLEARED, 'cleared_date' => now()]);
        $this->deal->refreshPaidStatus();
    }
}
