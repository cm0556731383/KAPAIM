<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `mailing_memberships.supplier_id` was added back in build-plan 10
     * (2026_08_26_002700_create_mailing_memberships_table.php) as a plain
     * nullable column ahead of SUPPLIER existing — same "add the column
     * ahead of its constraint" pattern as contacts.customer_id (build-plan
     * 05) and documents.expense_id (build-plan 07, wired up alongside this
     * one — see 2026_08_26_003000_update_documents_table_for_expenses.php).
     * Now that `suppliers` exists, wire up the real FK constraint.
     * nullOnDelete(): suppliers are never deleted in practice (no delete
     * route anywhere in this codebase), but a membership row must never
     * become un-savable just because its supplier link is severed some
     * other way.
     */
    public function up(): void
    {
        Schema::table('mailing_memberships', function (Blueprint $table) {
            $table->foreign('supplier_id')->references('id')->on('suppliers')->nullOnDelete();
            $table->index('supplier_id');
        });
    }

    public function down(): void
    {
        Schema::table('mailing_memberships', function (Blueprint $table) {
            $table->dropForeign(['supplier_id']);
            $table->dropIndex(['supplier_id']);
        });
    }
};
