<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A document made outside the system — before it existed, on paper, in
 * SUMIT directly (2026-11-25): recorded with its dates and number only, never
 * sent or issued from here (Document::recordExternal()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->boolean('is_external')->default(false)->after('format');
            $table->string('external_number', 50)->nullable()->after('is_external');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn(['is_external', 'external_number']);
        });
    }
};
