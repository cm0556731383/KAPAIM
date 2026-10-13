<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 'payment' — a one-off payment; 'standing_order' — the first month of a
     * standing order: once it's paid (and her card is saved in SUMIT by it)
     * the standing order for the rest is registered (PaymentLink::complete()).
     */
    public function up(): void
    {
        Schema::table('payment_links', function (Blueprint $table) {
            $table->string('purpose')->default('payment')->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('payment_links', function (Blueprint $table) {
            $table->dropColumn('purpose');
        });
    }
};
