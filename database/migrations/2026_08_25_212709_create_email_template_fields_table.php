<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mirrors DOCUMENT_TEMPLATE_FIELD's shape (build-plan stage 7): field_type
     * distinguishes free-text vs. a field linked to existing business data
     * (linked_field), per FR-7.10's free-text/linked-field distinction.
     */
    public function up(): void
    {
        Schema::create('email_template_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_template_id')->constrained()->cascadeOnDelete();
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
        Schema::dropIfExists('email_template_fields');
    }
};
