<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Build-plan 07 — DOCUMENT_LINE. Only meaningful for document_type =
     * invoice in this stage (Document::addLine() rejects lines on any other
     * document type). FR-4.18/FR-8.10: description + amount are always
     * required. FR-4.19: an invoice's total is always sum(amount) over its
     * lines, computed on read (Document::totalAmount()) — never stored as a
     * column, so it can never drift out of sync with the line-item detail.
     */
    public function up(): void
    {
        Schema::create('document_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->string('description');
            $table->decimal('quantity', 10, 2)->default(1);
            $table->decimal('unit_price', 10, 2)->nullable();
            $table->decimal('amount', 10, 2);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index('document_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_lines');
    }
};
