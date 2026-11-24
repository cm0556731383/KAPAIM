<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A small folder of files per deal (2026-11-24): PDFs and images the team
 * uploads on the deal page (a signed form, a purchase order, a photo of a
 * check...) to view or download later. The file itself is on the private
 * 'local' disk under DealFile::DIRECTORY.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deal_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deal_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['deal_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deal_files');
    }
};
