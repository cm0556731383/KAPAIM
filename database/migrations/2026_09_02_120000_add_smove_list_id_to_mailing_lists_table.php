<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Smove identifies a mailing list by numeric id (GET /v1/Lists), not by
     * name — this maps each local MAILING_LIST row to the matching Smove
     * list. Left null until the business owner fills it in via
     * ⚡mailing-lists.blade.php; MailingMembership::pushToSmove() treats a
     * null smove_list_id as "not linked yet" (same never-crash convention as
     * the rest of build-plan 12's external calls).
     */
    public function up(): void
    {
        Schema::table('mailing_lists', function (Blueprint $table) {
            $table->unsignedBigInteger('smove_list_id')->nullable()->after('list_type');
        });
    }

    public function down(): void
    {
        Schema::table('mailing_lists', function (Blueprint $table) {
            $table->dropColumn('smove_list_id');
        });
    }
};
