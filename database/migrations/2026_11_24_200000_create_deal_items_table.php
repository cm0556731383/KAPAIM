<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Several programs / bundles in one deal (2026-11-24): each is a line with
 * its own name/list-price snapshot and its own agreed amount; the deal's
 * agreed_amount is their sum. deals.program_id/bundle_id and the name/price
 * snapshots stay as the deal's first line (kept in sync by Deal) for the
 * code that only ever needs one item. Every existing deal becomes one line.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deal_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('program_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('bundle_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('name_snapshot');
            $table->decimal('list_price', 10, 2)->nullable();
            $table->decimal('amount', 10, 2);
            $table->unsignedSmallInteger('sort_order')->default(1);
            $table->timestamps();

            $table->index(['deal_id', 'sort_order']);
        });

        DB::statement(<<<'SQL'
            INSERT INTO deal_items (deal_id, program_id, bundle_id, name_snapshot, list_price, amount, sort_order, created_at, updated_at)
            SELECT d.id, d.program_id, d.bundle_id,
                   COALESCE(d.program_name_snapshot, d.bundle_name_snapshot, p.name, b.name, 'עסקה #' || d.id),
                   COALESCE(d.program_price_snapshot, d.bundle_price_snapshot),
                   COALESCE(d.agreed_amount, 0), 1, d.created_at, d.updated_at
            FROM deals d
            LEFT JOIN programs p ON p.id = d.program_id
            LEFT JOIN bundles b ON b.id = d.bundle_id
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('deal_items');
    }
};
