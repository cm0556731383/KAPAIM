<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mirrors email_template_fields' shape exactly (build-plan 02).
     * field_type distinguishes free_text vs. linked (FR-4.10); linked_field
     * is one of the fixed keys in App\Services\DocumentLinkedFields::OPTIONS
     * (a deliberately small, concrete set — not a generic field-mapping DSL).
     */
    public function up(): void
    {
        Schema::create('document_template_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_template_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('field_type');
            $table->string('linked_field')->nullable();
            $table->boolean('is_required')->default(false);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_template_fields');
    }
};
