<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Build-plan 12 — EXTERNAL_OPERATION. Every real call to/from Smove or
     * Summit (plus the landing-page webhook, which reuses this same audit
     * infrastructure even though FR-8.16-FR-8.18 name only Smove/Summit)
     * goes through App\Services\Integrations\ExternalOperationRunner, which
     * is the sole place a row here is ever created — same "sole creation
     * point" convention as every other table in this codebase.
     *
     * document_id/expense_id/material_delivery_id match docs/erd.md's bare
     * EXTERNAL_OPERATION block exactly (invoice/receipt/credit-note issuance,
     * material-reminder and expense-reminder sends). lead_id/deal_id/
     * payment_id are this build's minimal-scope addition beyond that block —
     * the same kind of pragmatic extension as MATERIAL_DELIVERY.handled_at
     * (build-plan 10) or SUBSCRIPTION.monthly_payment_override (build-plan
     * 09) — needed here for the landing-page lead intake, card-charge/
     * standing-order-registration, and standing-order-collection operations,
     * none of which touch a document/expense/material_delivery row. A
     * mailing-list membership push (MailingMembership::addLead/addCustomer/
     * addSupplier/removeCustomer) deliberately leaves every one of these FKs
     * null — its full entity context (lead_id/customer_id/etc.) already
     * lives on the paired ACTIVITY_LOG row via external_operation_id below,
     * so it isn't duplicated here too.
     *
     * A row is never deleted or edited after completion (matches every other
     * business-record convention in this codebase) — status/external_reference/
     * error_message/completed_at are set exactly once, when the attempt finishes.
     */
    public function up(): void
    {
        Schema::create('external_operations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('expense_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('material_delivery_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('deal_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('integration_setting_id')->nullable()->constrained('external_integration_settings')->nullOnDelete();
            $table->foreignId('triggered_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('system');
            $table->string('operation_type');
            $table->string('trigger_source');
            $table->string('status');
            $table->string('external_reference')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('attempted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['system', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_operations');
    }
};
