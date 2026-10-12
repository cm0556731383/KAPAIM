<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A business-owner label for each entity's SUMIT account (e.g. "סאמיט —
     * חשבון ראשי"), so the right connection is recognizable in settings and
     * in SUMIT error messages, independently of the entity's legal name.
     */
    public function up(): void
    {
        Schema::table('business_entities', function (Blueprint $table) {
            $table->string('sumit_connection_name')->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('business_entities', function (Blueprint $table) {
            $table->dropColumn('sumit_connection_name');
        });
    }
};
