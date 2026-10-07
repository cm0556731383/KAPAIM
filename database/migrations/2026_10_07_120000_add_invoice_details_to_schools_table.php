<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Billing details on the customer card's "פרטי בית ספר" card
     * (⚡customer-detail.blade.php): the name an invoice is issued to,
     * when it differs from the school's display name, and its ח.פ.
     */
    public function up(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->string('invoice_name')->nullable()->after('address');
            $table->string('business_number', 20)->nullable()->after('invoice_name');
        });
    }

    public function down(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->dropColumn(['invoice_name', 'business_number']);
        });
    }
};
