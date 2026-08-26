<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
    'check_status', 'payment_date', 'cleared_date',
])]
class Payment extends Model
{
    use HasFactory;

    public const CHECK_RECEIVED = 'received';

    public const CHECK_CLEARED = 'cleared';

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'payment_date' => 'datetime',
            'cleared_date' => 'datetime',
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

    public function isCheck(): bool
    {
        return $this->paymentMethod?->type === 'check';
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
    }
}
