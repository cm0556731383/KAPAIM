<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'type', 'is_active'])]
class PaymentMethod extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    private const DEFAULT_NAMES = ['card' => 'אשראי', 'recurring' => 'הוראת קבע'];

    /**
     * The method a payment SUMIT took is recorded under — a card payment
     * (payment link, saved-card charge) or a standing-order collection. The
     * active one of that type, else any; created if the business has none.
     */
    public static function forType(string $type): self
    {
        return self::where('type', $type)->orderByDesc('is_active')->orderBy('id')->first()
            ?? self::create(['name' => self::DEFAULT_NAMES[$type] ?? $type, 'type' => $type, 'is_active' => true]);
    }
}
