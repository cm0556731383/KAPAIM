<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Customer card "פרטים נוספים": שם הבעלות, תקציב גפן (yes/no), וידאו או שקופיות. */
    public function up(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->string('ownership_name')->nullable()->after('classes_per_grade');
            $table->boolean('gefen_budget')->default(false)->after('ownership_name');
            $table->string('content_format')->nullable()->after('gefen_budget');
        });
    }

    public function down(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->dropColumn(['ownership_name', 'gefen_budget', 'content_format']);
        });
    }
};
