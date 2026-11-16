<?php

namespace App\Models;

use App\Services\ActivityLogger;
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
 *
 * Build-plan 19 (FR-8.22): restore() below reverses that logical delete —
 * see ⚡lead-detail.blade.php / ⚡customer-detail.blade.php's "פריטים
 * שהוסרו לאחרונה" panel, the only callers.
 */
#[Fillable([
    'school_id', 'customer_id', 'name', 'role', 'phone', 'phone_secondary',
    'email', 'email_secondary', 'is_primary', 'is_accounting_contact', 'smove_contact_id',
])]
class Contact extends Model
{
    use HasFactory, SoftDeletes {
        SoftDeletes::restore as private restoreSoftDelete;
    }

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'is_accounting_contact' => 'boolean',
        ];
    }

    /**
     * A contact flagged only as the accounting contact (גורם חשבונאי, not
     * also primary) receives accounting mail only — invoices, receipts,
     * payment links — never mailing-list sends, study materials or quotes.
     * A contact who is both primary and accounting gets everything.
     */
    public function isAccountingOnly(): bool
    {
        return $this->is_accounting_contact && ! $this->is_primary;
    }

    /** Contacts that may receive general (non-accounting) mail — see isAccountingOnly(). */
    public function scopeReceivesGeneralMail($query)
    {
        return $query->where(fn ($q) => $q->where('is_accounting_contact', false)->orWhere('is_primary', true));
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * FR-8.22: reverses a logical delete (removeContact() in
     * ⚡lead-detail.blade.php / ⚡customer-detail.blade.php) and logs the
     * restoration. Purely additive — restoring a contact can only ever add
     * a candidate for "primary" back, never remove one, so it cannot
     * violate FR-2.8/FR-2.9's "at least one primary contact" invariant
     * (that invariant is only ever checked when unmarking/removing a
     * primary, both blocked elsewhere already).
     */
    public function restore(ActivityLogger $activityLogger): bool
    {
        $result = $this->restoreSoftDelete();

        $activityLogger->log('contact.restored', "שוחזר איש קשר \"{$this->name}\"", [
            'customer_id' => $this->customer_id, 'school_id' => $this->school_id,
        ]);

        return $result;
    }
}
