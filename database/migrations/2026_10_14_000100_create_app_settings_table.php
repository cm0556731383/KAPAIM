<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * General business settings edited on the settings page (not tied to an
     * external system like external_integration_settings) — first one:
     * the Hebrew month a subscription's month 1 falls on.
     *
     * subscriptions.first_month_date pins each subscription's month 1 (the
     * 1st of that Hebrew month, as a Gregorian date) when it opens, so a
     * later settings change never relabels an existing subscription's months
     * or its already-issued credit details. Null on subscriptions opened
     * before this — those follow the current setting.
     */
    public function up(): void
    {
        Schema::create('app_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->date('first_month_date')->nullable()->after('start_date');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('first_month_date');
        });

        Schema::dropIfExists('app_settings');
    }
};
