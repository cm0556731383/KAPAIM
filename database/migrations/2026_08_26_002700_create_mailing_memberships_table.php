<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Build-plan 10 — MAILING_MEMBERSHIP. supplier_id is a bare nullable
     * column with no FK constraint yet (SUPPLIER doesn't exist until
     * build-plan 11) — the exact same "add the column ahead of its
     * constraint" pattern as contacts.customer_id (stage 4/5) and
     * documents.expense_id (stage 07).
     *
     * lead_id is this stage's own judgment call, ADDED BEYOND docs/erd.md's
     * bare MAILING_MEMBERSHIP block (which only shows customer_id/
     * supplier_id): FR-1.17/FR-5.21 require a brand-new LEAD — not yet a
     * CUSTOMER — to join the primary list the moment it's created
     * (Lead::joinPrimaryMailingList()), so a real FK is needed to hang that
     * row on before a Customer exists at all. Since `leads` already exists
     * as a real table (build-plan 04), this gets a real FK constraint rather
     * than a bridge-column stub. Lead::convertToCustomer() then backfills
     * customer_id onto that same row (keeping lead_id for the audit trail) —
     * the exact same backfill spirit as the contacts.customer_id backfill in
     * that method — so a converted lead's membership becomes a real
     * "CUSTOMER joins MAILING_LIST" row exactly as docs/erd.md models it.
     * See this stage's report for this judgment call.
     *
     * membership_status never hard-deletes a row (this codebase's universal
     * no-hard-delete convention) — cancellation/removal always flips
     * membership_status to 'removed' and stamps removed_at instead
     * (FR-5.24/FR-5.25, see Subscription::cancel()).
     */
    public function up(): void
    {
        Schema::create('mailing_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mailing_list_id')->constrained()->restrictOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->string('membership_status')->default('active');
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();

            $table->index('mailing_list_id');
            $table->index('customer_id');
            $table->index('lead_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mailing_memberships');
    }
};
