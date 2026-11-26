<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A payment imported with its receipt issued outside the system whose date
 * wasn't given (DealImport, 2026-11-26) — payment_date holds the invoice's
 * date so reports keep working, and screens show "לא ידוע".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->boolean('date_unknown')->default(false)->after('payment_date');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('date_unknown');
        });
    }
};
