<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prices by the school's number of classes per grade (2026-11-24): `price`
 * stays the 1–2 classes price, price_3_4 / price_5_6 are optional (empty =
 * same as `price`). A deal records which tier it was sold at.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['programs', 'bundles'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->decimal('price_3_4', 10, 2)->nullable()->after('price');
                $table->decimal('price_5_6', 10, 2)->nullable()->after('price_3_4');
            });
        }

        Schema::table('deals', function (Blueprint $table) {
            $table->string('class_tier', 8)->nullable()->after('bundle_price_snapshot');
        });
    }

    public function down(): void
    {
        foreach (['programs', 'bundles'] as $table) {
            Schema::table($table, fn (Blueprint $table) => $table->dropColumn(['price_3_4', 'price_5_6']));
        }

        Schema::table('deals', fn (Blueprint $table) => $table->dropColumn('class_tier'));
    }
};
