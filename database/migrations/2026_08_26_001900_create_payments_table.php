<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Build-plan 08 — PAYMENT. The only place a row here is ever created is
     * Deal::recordPayment() (mirrors Document::generateFor()'s "sole
     * creation point" convention), guarded by the same optimistic lock on
     * deals.version as Deal::updateStatusWithLock() (FR-8.19, continued from
     * stage 6) so two concurrent payment recordings on the same deal can't
     * race into an inconsistent state.
     *
     * payment_method_id snapshots the deal's payment method at the moment of
     * recording — deals.payment_method_id itself is then frozen (FR-4.30/
     * FR-4.31: it may only change while a deal has zero payments).
     *
     * check_status/cleared_date are only ever meaningful when the payment's
     * method is of type 'check' (FR-4.28/FR-4.29): a check starts 'received'
     * and is separately marked 'cleared' via Payment::markCleared() — never
     * inferred any other way. A receipt can only ever be issued for a check
     * payment once cleared_date is set.
     *
     * A payment is never deleted or edited after the fact — same convention
     * as every other business record in this codebase.
     */
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deal_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_method_id')->constrained()->restrictOnDelete();
            $table->foreignId('status_id')->constrained('status_definitions')->restrictOnDelete();
            $table->decimal('amount', 10, 2);
            $table->string('check_status')->nullable();
            $table->timestamp('payment_date');
            $table->timestamp('cleared_date')->nullable();
            $table->timestamps();

            $table->index('deal_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
