<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A lead's confirmed quote is announced on the home screen (with whether
     * the order form + contract went out after it) until someone marks it
     * handled — this is when that happened.
     */
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->timestamp('confirmation_noticed_at')->nullable()->after('viewed_at');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn('confirmation_noticed_at');
        });
    }
};
