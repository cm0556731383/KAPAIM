<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A quote can be sent from the lead card, before the lead is a customer
     * (business owner's call, 2026-10-27 — the specs had it on the deal
     * only). Such a quote belongs to the lead (lead_id) and carries what it
     * offers in lead_offer (program_id/bundle_id, agreed_amount,
     * students_count) — there is no deal yet. When the recipient confirms it
     * (Document::confirm()), the lead converts, a deal is opened from the
     * offer, and the quote gets that deal_id; lead_id stays as its origin.
     *
     * So a row is now either an expense invoice (expense_id only) or a sales
     * document with a deal_id, a lead_id, or both.
     */
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->foreignId('lead_id')->nullable()->after('expense_id')->constrained()->restrictOnDelete();
            $table->json('lead_offer')->nullable()->after('lead_id');
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE documents DROP CONSTRAINT IF EXISTS documents_exactly_one_deal_or_expense');
            DB::statement(
                'ALTER TABLE documents ADD CONSTRAINT documents_expense_or_sale CHECK '
                .'((expense_id IS NOT NULL AND deal_id IS NULL AND lead_id IS NULL) '
                .'OR (expense_id IS NULL AND (deal_id IS NOT NULL OR lead_id IS NOT NULL)))'
            );
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE documents DROP CONSTRAINT IF EXISTS documents_expense_or_sale');
            DB::statement(
                'ALTER TABLE documents ADD CONSTRAINT documents_exactly_one_deal_or_expense CHECK '
                .'((deal_id IS NOT NULL AND expense_id IS NULL) OR (deal_id IS NULL AND expense_id IS NOT NULL))'
            );
        }

        Schema::table('documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('lead_id');
            $table->dropColumn('lead_offer');
        });
    }
};
