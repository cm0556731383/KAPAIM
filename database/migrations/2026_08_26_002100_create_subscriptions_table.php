<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Build-plan 09 — SUBSCRIPTION. The only place a row here is ever created
     * is Deal::openSubscriptionIfApplicable() (called from
     * Deal::createForCustomer() when the purchased program is build-plan 03's
     * is_subscription_type discriminator) — never created directly by
     * UI/controller code, same "sole creation point" convention as every
     * other business record in this codebase.
     *
     * `version` backs the same optimistic-locking convention as
     * deals.version (FR-8.19, stage 6/8) — Subscription::markDeliverySupplied()
     * guards on `WHERE id = ? AND version = ?` so two concurrent "mark
     * supplied" actions on the same subscription can't both succeed against a
     * stale version (FR-8.19's continuation into this stage).
     *
     * agreed_price freezes the price actually agreed with this customer at
     * deal time — cancellation_credit is always computed from THIS column,
     * never from the live catalog price (FR-8.24/US-010).
     *
     * monthly_payment_override is this build's minimal-scope addition for
     * FR-3.19-FR-3.21: null means "use the computed default" (invoice total
     * or agreed_price / 10), a non-null value is the manual override.
     *
     * A subscription is never deleted, even when cancelled (US-010's
     * explicit acceptance criterion) — Subscription::cancel() is a status
     * change only, same convention as Deal.
     */
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('deal_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('status_id')->constrained('status_definitions')->restrictOnDelete();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->date('cancelled_at')->nullable();
            $table->decimal('agreed_price', 10, 2);
            $table->decimal('cancellation_credit', 10, 2)->nullable();
            $table->decimal('monthly_payment_override', 10, 2)->nullable();
            $table->unsignedInteger('version')->default(0);
            $table->timestamps();

            $table->index('customer_id');
            $table->index('status_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
