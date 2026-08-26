<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Build-plan 10 — MATERIAL_DELIVERY_RECIPIENT. A per-send snapshot only
     * ever created through MaterialDelivery::sendFor() — the exact same
     * "permanent, never re-derived live from contacts.is_primary" convention
     * as document_recipients (build-plan 07/FR-2.12/FR-2.13): recipients
     * default to the customer's primary contacts with a non-empty email
     * (FR-5.4) when the caller doesn't override them, but a later change to
     * contacts.is_primary can never alter a row already created here
     * (FR-5.5). contact_id is nullable — an ad-hoc recipient added for one
     * send has no contact row at all (FR-2.13/FR-5.5 by extension).
     */
    public function up(): void
    {
        Schema::create('material_delivery_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('material_delivery_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->string('recipient_name');
            $table->string('recipient_email')->nullable();
            $table->timestamps();

            $table->index('material_delivery_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_delivery_recipients');
    }
};
