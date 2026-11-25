<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A receipt issued outside the system (Receipt::recordExternal(),
 * 2026-11-25) — like an external document: recorded with its date and
 * number, never pushed to SUMIT or emailed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('receipts', function (Blueprint $table) {
            $table->boolean('is_external')->default(false)->after('issued_before_payment');
            $table->string('external_number', 50)->nullable()->after('is_external');
        });
    }

    public function down(): void
    {
        Schema::table('receipts', function (Blueprint $table) {
            $table->dropColumn(['is_external', 'external_number']);
        });
    }
};
