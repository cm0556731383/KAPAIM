<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Build-plan 08 — RECEIPT. The only place a row here is ever created is
     * Receipt::issueFor() — mirrors Document::generateFor()'s "sole creation
     * point" convention, and enforces the same kind of mandatory gate
     * (FR-4.5): a receipt can only be issued for a deal that already has an
     * invoice Document, hence document_id is required and not nullable.
     *
     * payment_id is nullable — FR-4.24/FR-4.25's explicit exceptional path,
     * "קבלה לפני תשלום" (issued_before_payment = true), may have no payment
     * row at all yet. When it does reference a payment, Receipt::issueFor()
     * enforces at most one receipt per payment ever (FR-4.29) and, for a
     * check payment, that it only happens after the check has cleared
     * (FR-4.28).
     *
     * A receipt is never deleted or edited after the fact — same convention
     * as every other business record in this codebase.
     */
    public function up(): void
    {
        Schema::create('receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('document_id')->constrained()->restrictOnDelete();
            $table->string('status')->default('הופקה');
            $table->boolean('issued_before_payment')->default(false);
            $table->timestamp('issued_at')->nullable();
            $table->timestamps();

            $table->index('payment_id');
            $table->index('document_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receipts');
    }
};
