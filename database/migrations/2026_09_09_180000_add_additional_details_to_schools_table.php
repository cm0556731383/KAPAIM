<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "פרטים נוספים" card on the customer card (⚡customer-detail.blade.php)
     * — free-form institutional details the business owner tracks per
     * school that don't fit the core contact-info fields above them.
     */
    public function up(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->string('syllable')->nullable()->after('address');
            $table->unsignedSmallInteger('classes_per_grade')->nullable()->after('syllable');
            $table->string('logo_path')->nullable()->after('classes_per_grade');
            $table->text('notes')->nullable()->after('logo_path');
        });
    }

    public function down(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->dropColumn(['syllable', 'classes_per_grade', 'logo_path', 'notes']);
        });
    }
};
