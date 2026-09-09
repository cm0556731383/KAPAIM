<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The "grants an annual subscription" discriminator (build-plan 03) moves
     * from PROGRAM to BUNDLE — going forward a subscription is opened by
     * purchasing the one active is_subscription_type BUNDLE, never a program
     * (see Deal::openSubscriptionIfApplicable()). Any existing data tying a
     * program to this flag, and any deal/subscription built on it, must be
     * migrated by hand (see this migration's date's session notes) BEFORE
     * this runs in an environment with real data — down() restores the
     * programs column but cannot un-migrate such data.
     */
    public function up(): void
    {
        Schema::table('bundles', function (Blueprint $table) {
            $table->boolean('is_subscription_type')->default(false)->after('price');
        });

        Schema::table('programs', function (Blueprint $table) {
            $table->dropColumn('is_subscription_type');
        });
    }

    public function down(): void
    {
        Schema::table('programs', function (Blueprint $table) {
            $table->boolean('is_subscription_type')->default(false)->after('is_premium');
        });

        Schema::table('bundles', function (Blueprint $table) {
            $table->dropColumn('is_subscription_type');
        });
    }
};
