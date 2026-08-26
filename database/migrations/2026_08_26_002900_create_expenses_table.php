<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Build-plan 11 — EXPENSE (FR-6.5/FR-6.6). program_id is nullable — not
     * every expense is attributable to a specific catalog program (FR-6.13's
     * profit-by-program calculation simply skips a program-less expense at
     * the by-program level; it still counts at the month-level total — see
     * RevenueReport's docblock). document_id points at the general DOCUMENT
     * table built in build-plan 07 (an 'expense_invoice'-typed row,
     * Expense::attachInvoice()) rather than a separate file column, per this
     * stage's own instruction to reuse DOCUMENT's template/file machinery.
     *
     * status_id is expense-scoped (STATUS_DEFINITION scope='expense',
     * already seeded by ReferenceDataSeeder: "ממתינה לחשבונית" / "תועדה") —
     * Expense::createForSupplier() starts a row in the former;
     * Expense::attachInvoice() flips it to the latter (FR-6.10).
     *
     * An expense is never deleted (matches every other business-record
     * convention in this codebase) — there is no soft-deletes column.
     */
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('program_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->foreignId('status_id')->constrained('status_definitions')->restrictOnDelete();
            $table->decimal('amount', 10, 2);
            $table->date('expense_date');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('supplier_id');
            $table->index('program_id');
            $table->index('status_id');
            $table->index('expense_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
