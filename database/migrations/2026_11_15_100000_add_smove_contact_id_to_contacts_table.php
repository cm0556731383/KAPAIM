<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Smove contact a CONTACT row is linked to — set by the customer import
 * (App\Services\CustomerImport) when the contact's email is already on the
 * system's Smove list, so it's linked rather than re-subscribed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->unsignedBigInteger('smove_contact_id')->nullable()->after('is_accounting_contact');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn('smove_contact_id');
        });
    }
};
