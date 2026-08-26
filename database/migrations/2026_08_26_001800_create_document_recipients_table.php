<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Build-plan 07 — DOCUMENT_RECIPIENT. A per-send snapshot, deliberately
     * NOT derived live from contacts.is_primary at read time: recipients
     * default to the deal's customer's primary contacts when a send is being
     * prepared (FR-2.11/FR-4.8), but the rows created here are what actually
     * got sent and must stay stable even if contacts.is_primary later
     * changes (FR-2.12) or the contact itself is edited/removed
     * (contact_id is nullable — an ad-hoc recipient, FR-2.13/FR-4.9, has no
     * contact row at all; recipient_name/recipient_email are always copied
     * in, never looked up live through contact_id).
     */
    public function up(): void
    {
        Schema::create('document_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->string('recipient_name');
            $table->string('recipient_email')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index('document_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_recipients');
    }
};
