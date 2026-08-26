<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Build-plan 07 — DOCUMENT. Every document belongs to a deal (FR-4.6).
     * preceding_document_id (self-referencing) builds the
     * quote -> order_form -> contract -> invoice chain that
     * Document::generateFor()'s business gate walks (FR-4.3/FR-4.4,
     * FR-8.7/FR-8.8). business_entity_id is only ever set for an invoice
     * (FR-4.15/FR-4.16).
     *
     * Two columns beyond the bare docs/erd.md DOCUMENT block, both needed for
     * FR-4.14 ("changing a template must not retroactively change documents
     * already generated from it"):
     *   - rendered_content: the template's content with every
     *     DOCUMENT_TEMPLATE_FIELD placeholder substituted, frozen at
     *     generation time — never re-rendered from the live template.
     *   - field_values: the digital-form field values captured for this
     *     document (JSON, keyed by document_template_field_id), snapshotted
     *     the same way. Backs FR-4.12 (linked-field values update the
     *     underlying business record when submitted) and FR-4.13 (a contract
     *     auto-fills from the immediately preceding order form's captured
     *     values for matching linked fields).
     *
     * expense_id is a plain nullable column without an FK yet, same
     * bridge-column pattern activity_logs used for not-yet-built tables
     * (EXPENSE is a later build-plan stage).
     *
     * A document is never deleted (matches every other business-record
     * convention in this codebase) — there is no soft-deletes column.
     */
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deal_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('expense_id')->nullable();
            $table->foreignId('document_template_id')->constrained()->restrictOnDelete();
            $table->foreignId('business_entity_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('preceding_document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->string('document_type');
            $table->foreignId('status_id')->constrained('status_definitions')->restrictOnDelete();
            $table->string('format')->default('digital');
            $table->string('file_reference')->nullable();
            $table->text('rendered_content')->nullable();
            $table->json('field_values')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->timestamps();

            $table->index('deal_id');
            $table->index('document_type');
            $table->index('status_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
