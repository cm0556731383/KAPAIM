<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Invoices, credit notes and expense invoices are created with no
     * template (DocumentTemplate::SYSTEM_TYPES) — SUMIT lays out the first
     * two from their lines, and an expense invoice is the supplier's own
     * document. Their templates had no effect, so they go; the documents
     * already made keep their rendered snapshot.
     */
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->foreignId('document_template_id')->nullable()->change();
        });

        $types = ['invoice', 'credit_note', 'expense_invoice'];
        $templateIds = DB::table('document_templates')->whereIn('document_type', $types)->pluck('id');

        DB::table('documents')->whereIn('document_template_id', $templateIds)->update(['document_template_id' => null]);
        DB::table('document_templates')->whereIn('id', $templateIds)->delete();
    }

    public function down(): void
    {
        // The deleted templates had no effect on any document; not restored.
        Schema::table('documents', function (Blueprint $table) {
            $table->foreignId('document_template_id')->nullable(false)->change();
        });
    }
};
