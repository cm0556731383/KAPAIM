<?php

namespace App\Models;

use App\Services\Integrations\ExternalOperationRunner;
use App\Services\Integrations\SmoveClient;
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
 * Build-plan 12: every join/removal below also pushes to Smove via
 * pushToSmove() — a real HTTP call, but never blocking or rolling back the
 * local membership row above it, which is always written first and stays
 * the source of truth (1.5) regardless of whether the push succeeds (see
 * ExternalOperationRunner's docblock for why).
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

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * FR-1.17/FR-5.21: a brand-new lead joining the primary list, before any
     * customer exists.
     *
     * Only pushes to Smove when the membership is actually new/reactivated
     * (never for an already-active one) — Smove's own /Contacts endpoint
     * treats a repeated `lists_ToSubscribe` on an already-subscribed contact
     * as a fresh subscription request, which resets `canReceiveEmails` back
     * to false pending re-confirmation (confirmed empirically 2026-09-06:
     * re-posting the exact same join payload for an already-confirmed real
     * Smove contact flipped its canReceiveEmails from true to false). A
     * caller that just wants Smove's display name refreshed (e.g. after a
     * school rename) piggybacks on this method — that's a much smaller
     * tradeoff (a stale name in Smove) than silently revoking a lead's
     * mailing consent on every unrelated edit.
     */
    public static function addLead(MailingList $list, Lead $lead): self
    {
        $membership = self::firstOrNew(['mailing_list_id' => $list->id, 'lead_id' => $lead->id]);
        $wasActive = $membership->exists && $membership->membership_status === self::STATUS_ACTIVE;

        if (! $membership->exists) {
            $membership->joined_at = now();
        }

        $membership->membership_status = self::STATUS_ACTIVE;
        $membership->removed_at = null;
        $membership->save();

        if (! $wasActive) {
            self::pushToSmove('join', $list, [
                'email' => $lead->email,
                'name' => $lead->school?->name ?? $lead->email,
            ], ['lead_id' => $lead->id]);
        }

        return $membership;
    }

    /**
     * FR-5.21-FR-5.23: joins (or re-activates a previously removed
     * membership for) $customer on $list — safe to call repeatedly for the
     * same customer/list pair (see addLead()'s docblock above for why the
     * Smove push itself is skipped when the membership was already active —
     * same reasoning applies here for the several "re-push to refresh the
     * Smove display name" callers in ⚡customer-detail.blade.php).
     */
    public static function addCustomer(MailingList $list, Customer $customer): self
    {
        $membership = self::firstOrNew(['mailing_list_id' => $list->id, 'customer_id' => $customer->id]);
        $wasActive = $membership->exists && $membership->membership_status === self::STATUS_ACTIVE;

        if (! $membership->exists) {
            $membership->joined_at = now();
        }

        $membership->membership_status = self::STATUS_ACTIVE;
        $membership->removed_at = null;
        $membership->save();

        if ($wasActive) {
            return $membership;
        }

        $primaryContact = $customer->contacts()->where('is_primary', true)->first();

        self::pushToSmove('join', $list, [
            'email' => $primaryContact?->email,
            'name' => $customer->school?->name ?? $primaryContact?->name,
        ], ['customer_id' => $customer->id]);

        return $membership;
    }

    /**
     * Build-plan 11 (FR-5.26/FR-6.14-15): a new supplier joining the
     * singleton "ספקים" list — same idempotent shape as addCustomer() above,
     * keyed by (mailing_list_id, supplier_id).
     */
    public static function addSupplier(MailingList $list, Supplier $supplier): self
    {
        $membership = self::firstOrNew(['mailing_list_id' => $list->id, 'supplier_id' => $supplier->id]);
        $wasActive = $membership->exists && $membership->membership_status === self::STATUS_ACTIVE;

        if (! $membership->exists) {
            $membership->joined_at = now();
        }

        $membership->membership_status = self::STATUS_ACTIVE;
        $membership->removed_at = null;
        $membership->save();

        if (! $wasActive) {
            self::pushToSmove('join', $list, [
                'email' => $supplier->email,
                'name' => $supplier->name,
            ], []);
        }

        return $membership;
    }

    /**
     * FR-5.24: a logical removal only — flips membership_status, never
     * deletes the row. A no-op if $customer has no active membership on
     * $list to begin with.
     */
    public static function removeCustomer(MailingList $list, Customer $customer): void
    {
        $affected = self::where('mailing_list_id', $list->id)
            ->where('customer_id', $customer->id)
            ->where('membership_status', self::STATUS_ACTIVE)
            ->update(['membership_status' => self::STATUS_REMOVED, 'removed_at' => now()]);

        if ($affected > 0) {
            $primaryContact = $customer->contacts()->where('is_primary', true)->first();

            self::pushToSmove('remove', $list, [
                'email' => $primaryContact?->email,
                'name' => $customer->school?->name ?? $primaryContact?->name,
            ], ['customer_id' => $customer->id]);
        }
    }

    /**
     * Build-plan 12: fire-and-forget best-effort Smove push, shared by every
     * factory method above — never throws (ExternalOperationRunner swallows
     * SmoveClient's RuntimeException, including "not configured yet", into a
     * failed EXTERNAL_OPERATION + ACTIVITY_LOG row) and never returns
     * anything the caller needs, since the local membership row above it is
     * already the durable fact.
     */
    /**
     * A contact with no email yet (e.g. no primary Contact set on the
     * customer) is a normal, unremarkable state — not a Smove failure worth
     * recording — so it's skipped here entirely, same as ProcessMaterial
     * Reminders/ProcessExpenseReminders skip a reminder for the same reason.
     *
     * 'join' additionally never touches Smove at all once the contact
     * already shows confirmed consent there (SmoveClient::hasConfirmedConsent())
     * — see that method's docblock for why. The local membership row is
     * already saved by the caller before pushToSmove() runs (addLead()/
     * addCustomer()/addSupplier() above), so skipping the network call here
     * still leaves this list membership correctly recorded — it just isn't
     * mirrored to Smove for an already-confirmed contact, on purpose.
     *
     * While NOT yet confirmed, a 'join' is broadened to include every
     * mailing list this app currently has linked to Smove (not just $list)
     * — confirmed empirically (2026-09-06) that every list included in the
     * same request as a contact's first confirmation stays confirmed
     * together, one click for all of them. This is what makes the guard
     * above actually work in practice: a lead's very first join (still
     * unconfirmed) pre-clears every future list a deal/subscription might
     * add them to later, so those later joins hit the already-confirmed
     * guard and never touch Smove again — instead of every single deal
     * needing its own separate confirmation email.
     */
    private static function pushToSmove(string $action, MailingList $list, array $contact, array $context): void
    {
        if (empty($contact['email'])) {
            return;
        }

        $smove = app(SmoveClient::class);

        if ($action === 'join' && $smove->isConfigured() && $smove->hasConfirmedConsent($contact['email'])) {
            return;
        }

        $listIds = $action === 'join'
            ? MailingList::query()->whereNotNull('smove_list_id')->pluck('smove_list_id')->push($list->smove_list_id)->filter()->unique()->values()->all()
            : $list->smove_list_id;

        app(ExternalOperationRunner::class)->run(
            'smove',
            'mailing_list_'.$action,
            'app_action',
            fn () => $smove->syncMailingListMembership($listIds, $action, $contact),
            array_merge($context, ['description' => ($action === 'join' ? 'הצטרפות' : 'הסרה')." מרשימת תפוצה \"{$list->name}\""]),
        );
    }
}
