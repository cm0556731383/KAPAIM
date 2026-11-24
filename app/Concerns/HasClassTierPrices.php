<?php

namespace App\Concerns;

/**
 * A catalog item (Program / Bundle) priced by the school's number of classes
 * per grade: `price` is the 1–2 classes price, price_3_4 / price_5_6 are
 * optional — an empty one falls back to `price`.
 */
trait HasClassTierPrices
{
    public const CLASS_TIERS = [
        '1-2' => '1–2 כיתות',
        '3-4' => '3–4 כיתות',
        '5-6' => '5–6 כיתות',
    ];

    /** The tier for a number of classes per grade — null when unknown (empty / 0). More than 6 counts as 5–6. */
    public static function classTierFor(?int $classes): ?string
    {
        return match (true) {
            ! $classes || $classes < 1 => null,
            $classes <= 2 => '1-2',
            $classes <= 4 => '3-4',
            default => '5-6',
        };
    }

    public function priceForClassTier(?string $tier): float
    {
        $price = match ($tier) {
            '3-4' => $this->price_3_4,
            '5-6' => $this->price_5_6,
            default => null,
        };

        return (float) ($price ?? $this->price);
    }

    /** Whether any tier beyond 1–2 has its own price. */
    public function hasClassTierPrices(): bool
    {
        return $this->price_3_4 !== null || $this->price_5_6 !== null;
    }
}
