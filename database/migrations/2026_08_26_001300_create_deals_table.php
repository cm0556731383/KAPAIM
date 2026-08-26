<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Build-plan 06 — DEAL. Every purchase is its own deal row (FR-3.1),
     * always tied to exactly one customer (FR-3.2) and to exactly one of
     * program_id/bundle_id, never both/neither (FR-3.3/FR-8.4) — enforced in
     * Deal::createForCustomer() at the application layer, and backed here by
     * a Postgres CHECK constraint on the dev/prod driver (sqlite, used by the
     * test suite, has no portable ALTER TABLE ... ADD CONSTRAINT, so the
     * check is skipped there and the application-layer rule alone applies).
     *
     * program_name_snapshot/program_price_snapshot (and the bundle
     * equivalents) freeze the program/bundle's name and price at the moment
     * of sale so a deal's history never changes if the live catalog item is
     * later renamed, repriced, or disabled (FR-3.6).
     *
     * `version` backs optimistic locking on status updates (FR-8.19) — every
     * update goes through `WHERE id = ? AND version = ?`, incrementing
     * version; a 0-row update means someone else changed the deal first.
     *
     * A deal is never deleted, not even when cancelled (FR-3.5) — cancelling
     * is just a status change — so there is no soft-deletes column either.
     */
    public function up(): void
    {
        Schema::create('deals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('program_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('bundle_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('status_id')->constrained('status_definitions')->restrictOnDelete();
            $table->decimal('agreed_amount', 10, 2);
            $table->decimal('program_price_snapshot', 10, 2)->nullable();
            $table->decimal('bundle_price_snapshot', 10, 2)->nullable();
            $table->string('program_name_snapshot')->nullable();
            $table->string('bundle_name_snapshot')->nullable();
            $table->foreignId('payment_method_id')->nullable()->constrained()->nullOnDelete();
            $table->text('special_request')->nullable();
            $table->timestamp('purchased_at');
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('version')->default(0);
            $table->timestamps();

            $table->index('customer_id');
            $table->index('status_id');
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE deals ADD CONSTRAINT deals_exactly_one_program_or_bundle CHECK '
                .'((program_id IS NOT NULL AND bundle_id IS NULL) OR (program_id IS NULL AND bundle_id IS NOT NULL))'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('deals');
    }
};
