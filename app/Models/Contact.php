<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Many contacts per school (US-001); `is_primary` allows multiple true rows
 * ("אחד או יותר"). Removal is a logical delete (deleted_at) — never a real
 * one — and must be logged to ACTIVITY_LOG by the caller (build-plan 04).
 * `customer_id` exists now per docs/erd.md for stage 5 to use later.
 */
#[Fillable([
    'school_id', 'customer_id', 'name', 'role', 'phone', 'phone_secondary',
    'email', 'email_secondary', 'is_primary', 'is_accounting_contact',
])]
class Contact extends Model
{
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'is_accounting_contact' => 'boolean',
        ];
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
