<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** A phone number: at least 7 digits, plus only the characters people write between them (+ - ( ) . / spaces). */
class Phone implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! preg_match('/^[0-9+\-\s().\/]+$/', $value) || preg_match_all('/\d/', $value) < 7) {
            $fail('מספר הטלפון אינו תקין — יש להזין לפחות 7 ספרות.');
        }
    }
}
