<?php

namespace App\Support;

class Money
{
    /** Every amount column is decimal(10,2) — a larger value fails in the database instead of in validation. */
    public const MAX_AMOUNT = 99999999;

    /** Validation rule capping an amount field at what the database can store. */
    public static function maxRule(): string
    {
        return 'max:'.self::MAX_AMOUNT;
    }
}
