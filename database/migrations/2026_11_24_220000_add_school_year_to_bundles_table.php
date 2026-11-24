<?php

use App\Models\Bundle;
use App\Models\Subscription;
use App\Services\HebrewCalendar;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A subscription bundle's school year (Hebrew year, תשפ״ז = 5787): the
 * subscriptions sold through it run that year's months (2026-11-24). An
 * existing subscription bundle takes the year of the subscriptions already
 * sold through it, when there are any.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bundles', function (Blueprint $table) {
            $table->unsignedSmallInteger('school_year')->nullable()->after('is_subscription_type');
        });

        Bundle::where('is_subscription_type', true)->get()->each(function (Bundle $bundle) {
            $subscription = Subscription::whereHas('deal', fn ($q) => $q->withItem($bundle))
                ->whereNotNull('first_month_date')->latest('id')->first();

            if ($subscription) {
                $bundle->forceFill(['school_year' => HebrewCalendar::schoolYearFor($subscription->first_month_date)])->saveQuietly();
            }
        });
    }

    public function down(): void
    {
        Schema::table('bundles', function (Blueprint $table) {
            $table->dropColumn('school_year');
        });
    }
};
