<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `contacts.customer_id` was added back in build-plan 04
     * (2026_08_26_000700_create_contacts_table.php) as a plain nullable
     * column "for stage 5 to use later" — `customers` didn't exist yet so no
     * real FK constraint could be added at the time. Now that it does, wire
     * up the actual constraint. `nullOnDelete()`: customers are never
     * deleted in practice (FR-2.4/FR-8.3), but a contact must never become
     * un-savable just because its customer link is severed some other way.
     */
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->foreign('customer_id')->references('id')->on('customers')->nullOnDelete();
            $table->index('customer_id');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropForeign(['customer_id']);
            $table->dropIndex(['customer_id']);
        });
    }
};
