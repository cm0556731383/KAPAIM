<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Each program/bundle names its default contract template (business
     * owner's call, 2026-10-27) — the one the order form + contract pair is
     * generated with (Document::generateOrderFormAndContract()), including
     * the automatic send after a lead's quote is confirmed. Existing items
     * start on "חוזה סטנדרטי" (DocumentTemplate::STANDARD_CONTRACT_NAME).
     */
    public function up(): void
    {
        foreach (['programs', 'bundles'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->foreignId('contract_template_id')->nullable()->constrained('document_templates')->nullOnDelete();
            });
        }

        $standardId = DB::table('document_templates')->where('document_type', 'contract')->where('name', 'חוזה סטנדרטי')->orderBy('id')->value('id');

        if ($standardId) {
            DB::table('programs')->update(['contract_template_id' => $standardId]);
            DB::table('bundles')->update(['contract_template_id' => $standardId]);
        }
    }

    public function down(): void
    {
        foreach (['programs', 'bundles'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropConstrainedForeignId('contract_template_id');
            });
        }
    }
};
