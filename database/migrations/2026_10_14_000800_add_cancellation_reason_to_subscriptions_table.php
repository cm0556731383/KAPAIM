<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Why a subscription was cancelled — asked in the cancellation window, shown on the cancelled deal. */
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->text('cancellation_reason')->nullable()->after('cancellation_credit');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('cancellation_reason');
        });
    }
};
