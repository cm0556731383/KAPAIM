<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** How many students the deal covers — entered on the deal, or filled in by the customer on a document's digital form. */
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->unsignedInteger('students_count')->nullable()->after('agreed_amount');
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->dropColumn('students_count');
        });
    }
};
