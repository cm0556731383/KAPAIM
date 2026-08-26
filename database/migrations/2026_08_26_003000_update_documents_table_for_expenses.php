<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Build-plan 11 — extends the build-plan 07 `documents` table now that
     * EXPENSE exists (docs/erd.md: an expense invoice document is linked via
     * EXPENSE.document_id to this same general DOCUMENT table, "כדי לנצל את
     * אותו מודל תבנית/עוסק פטור").
     *
     * Three changes, mirroring the deals-table CHECK-constraint precedent
     * (build-plan 06) for the same reasons:
     *   1. deal_id becomes nullable — an expense-invoice Document
     *      (Document::generateFor() called with an Expense — see that
     *      method's docblock) never belongs to a deal at all.
     *   2. expense_id (added ahead of time by the build-plan 07 migration as
     *      a bare unsigned bigint, per that migration's own docblock) gets
     *      its real FK constraint now that `expenses` exists.
     *   3. exactly one of deal_id/expense_id must be set on every row, never
     *      both/neither — enforced at the application layer
     *      (Document::generateFor()) and, on Postgres only (sqlite has no
     *      portable ALTER TABLE ... ADD CONSTRAINT, same as the deals-table
     *      precedent), backed by a real CHECK constraint here too.
     */
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->foreignId('deal_id')->nullable()->change();
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->foreign('expense_id')->references('id')->on('expenses')->restrictOnDelete();
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE documents ADD CONSTRAINT documents_exactly_one_deal_or_expense CHECK '
                .'((deal_id IS NOT NULL AND expense_id IS NULL) OR (deal_id IS NULL AND expense_id IS NOT NULL))'
            );
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE documents DROP CONSTRAINT IF EXISTS documents_exactly_one_deal_or_expense');
        }

        Schema::table('documents', function (Blueprint $table) {
            $table->dropForeign(['expense_id']);
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->foreignId('deal_id')->nullable(false)->change();
        });
    }
};
