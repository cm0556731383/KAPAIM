<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Build-plan 07 — DOCUMENT_TEMPLATE. document_type is a plain string
     * (quote/order_form/contract/invoice) rather than an enum column so a new
     * document type never needs a migration. FR-4.14: editing/deactivating a
     * template must never retroactively change a document already generated
     * from it — Document::generateFor() snapshots the rendered content (and
     * the field values used) onto the DOCUMENT row at generation time, so
     * this table is safe to edit freely. Like every other reference entity in
     * this codebase (programs, bundles, email templates, ...), a template is
     * never deleted — only deactivated via is_active.
     */
    public function up(): void
    {
        Schema::create('document_templates', function (Blueprint $table) {
            $table->id();
            $table->string('document_type');
            $table->string('name');
            $table->text('content');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('document_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_templates');
    }
};
