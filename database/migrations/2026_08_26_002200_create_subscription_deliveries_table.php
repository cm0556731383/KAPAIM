<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Build-plan 09 — SUBSCRIPTION_DELIVERY. Exactly 10 rows per subscription
     * are created up front by Deal::openSubscriptionIfApplicable() (one per
     * future program slot, FR-3.12), each starting with program_id null and
     * is_supplied false — which catalog program fills a given slot is chosen
     * later, at the moment it is marked supplied
     * (Subscription::markDeliverySupplied(), FR-3.14), not upfront.
     *
     * FR-3.15: is_supplied/program_id/supplied_at/supplied_by are set purely
     * by that manual action — nothing here is derived from, or coupled to,
     * materials sending (build-plan 10).
     *
     * A delivery row's parent subscription is never deleted (see
     * subscriptions migration), so cascadeOnDelete here mirrors
     * document_lines -> documents (a "detail line" child table) rather than
     * ever actually firing in practice.
     */
    public function up(): void
    {
        Schema::create('subscription_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->foreignId('program_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('supplied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('sequence_number');
            $table->boolean('is_supplied')->default(false);
            $table->date('supplied_at')->nullable();
            $table->timestamps();

            $table->index('subscription_id');
            $table->unique(['subscription_id', 'sequence_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_deliveries');
    }
};
