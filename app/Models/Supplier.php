<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Build-plan 11 — SUPPLIER (FR-6.14/FR-6.15). classification reuses the
 * exact same three values as BusinessEntity.classification — see
 * CLASSIFICATIONS below and ⚡settings.blade.php's
 * $businessEntityClassifications. No is_active/soft-delete column — see the
 * suppliers-table migration's docblock; a supplier is edited in place,
 * never disabled or deleted (project-wide "no delete route" convention).
 *
 * A supplier is never created directly by app code outside
 * ⚡suppliers.blade.php's createSupplier(), which always follows a create()
 * call with joinSuppliersMailingList() below — the exact same two-step
 * shape as Lead::joinPrimaryMailingList() (build-plan 10), not folded into
 * a single factory method since there is no other business rule to guard
 * here (unlike Deal::createForCustomer()/Document::generateFor(), a new
 * supplier has no rule that could fail and need a RuntimeException).
 */
#[Fillable(['name', 'company_number', 'classification', 'phone', 'email', 'notes'])]
class Supplier extends Model
{
    use HasFactory;

    /** FR-6.15 — identical set to BusinessEntity's classification values. */
    public const CLASSIFICATIONS = ['עוסק פטור', 'עוסק מורשה', 'חברה בעמ'];

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    public function mailingMemberships(): HasMany
    {
        return $this->hasMany(MailingMembership::class);
    }

    /**
     * FR-5.26/FR-6.14-15: every new supplier joins the singleton "ספקים"
     * mailing list automatically — mirrors Lead::joinPrimaryMailingList()'s
     * shape exactly (build-plan 10).
     *
     * TODO(stage 12 — Smove): this membership should also be pushed to
     * Smove — for now it is 100% real and local only, same stub boundary as
     * ExternalIntegrationSetting elsewhere in this codebase.
     */
    public function joinSuppliersMailingList(): void
    {
        MailingMembership::addSupplier(MailingList::suppliersList(), $this);
    }
}
