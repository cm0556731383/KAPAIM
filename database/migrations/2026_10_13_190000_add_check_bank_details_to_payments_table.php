<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The bank, branch and account a check is drawn on — optional, and printed on its SUMIT receipt (Details_Cheque). */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->unsignedSmallInteger('check_bank_number')->nullable()->after('check_due_date');
            $table->string('check_branch_number', 10)->nullable()->after('check_bank_number');
            $table->string('check_account_number', 30)->nullable()->after('check_branch_number');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['check_bank_number', 'check_branch_number', 'check_account_number']);
        });
    }
};
