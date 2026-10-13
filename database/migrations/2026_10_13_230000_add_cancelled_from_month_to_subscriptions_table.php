<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The subscription month (1-10, month 1 = start_date's month) from which
     * a cancellation applies — months from it onward are credited, and their
     * delivery rows show as cancelled. Null on subscriptions cancelled before
     * this existed.
     */
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->unsignedTinyInteger('cancelled_from_month')->nullable()->after('cancelled_at');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('cancelled_from_month');
        });
    }
};
