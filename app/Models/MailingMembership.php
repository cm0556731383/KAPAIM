<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Build-plan 10 — MAILING_MEMBERSHIP. Every membership change in this app
 * flows through addLead()/addCustomer()/removeCustomer() below — nothing
 * else should touch this table directly. Removal NEVER hard-deletes a row
 * (this codebase's universal convention) — membership_status flips to
 * 'removed' with removed_at stamped instead, and a later re-join reuses
 * (rather than duplicates) the same row, keyed by (mailing_list_id,
 * customer_id) or (mailing_list_id, lead_id).
 *
 * lead_id exists ahead of docs/erd.md's bare block — see the
 * mailing_memberships migration's docblock for why (FR-1.17's "a lead, not
 * yet a customer, joins the primary list" needs a real FK to hang that row
 * on) — Lead::convertToCustomer() backfills customer_id onto the same row.
 *
 * TODO(stage 12 — Smove): every join/removal here represents a real Smove
 * mailing-list API call per docs/erd.md's own note ("קריאות ל-Smove, לא רק
 * שינוי מקומי") — not implemented until build-plan 12 (same stub boundary as
 * ExternalIntegrationSetting throughout this codebase). The membership state
 * itself is 100% real and local today.
 */
#[Fillable(['mailing_list_id', 'lead_id', 'customer_id', 'supplier_id', 'membership_status', 'joined_at', 'removed_at'])]
class MailingMembership extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_REMOVED = 'removed';

    protected function casts(): array
    {
        return [
            'joined_at' => 'datetime',
            'removed_at' => 'datetime',
        ];
    }

    public function mailingList(): BelongsTo
    {
        return $this->belongsTo(MailingList::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** FR-1.17/FR-5.21: a brand-new lead joining the primary list, before any customer exists. */
    public static function addLead(MailingList $list, Lead $lead): self
    {
        $membership = self::firstOrNew(['mailing_list_id' => $list->id, 'lead_id' => $lead->id]);

        if (! $membership->exists) {
            $membership->joined_at = now();
        }

        $membership->membership_status = self::STATUS_ACTIVE;
        $membership->removed_at = null;
        $membership->save();

        return $membership;
    }

    /**
     * FR-5.21-FR-5.23: joins (or re-activates a previously removed
     * membership for) $customer on $list — idempotent, safe to call
     * repeatedly for the same customer/list pair.
     */
    public static function addCustomer(MailingList $list, Customer $customer): self
    {
        $membership = self::firstOrNew(['mailing_list_id' => $list->id, 'customer_id' => $customer->id]);

        if (! $membership->exists) {
            $membership->joined_at = now();
        }

        $membership->membership_status = self::STATUS_ACTIVE;
        $membership->removed_at = null;
        $membership->save();

        return $membership;
    }

    /**
     * FR-5.24: a logical removal only — flips membership_status, never
     * deletes the row. A no-op if $customer has no active membership on
     * $list to begin with.
     */
    public static function removeCustomer(MailingList $list, Customer $customer): void
    {
        self::where('mailing_list_id', $list->id)
            ->where('customer_id', $customer->id)
            ->where('membership_status', self::STATUS_ACTIVE)
            ->update(['membership_status' => self::STATUS_REMOVED, 'removed_at' => now()]);
    }
}
