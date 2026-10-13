<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A check payment's number and due date — the due date drives the
     * "check due: issue its receipt?" reminder on the dashboard, and both go
     * on the SUMIT receipt (Details_Cheque).
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('check_number')->nullable()->after('check_status');
            $table->date('check_due_date')->nullable()->after('check_number');
            $table->index(['check_status', 'check_due_date']);
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['check_status', 'check_due_date']);
            $table->dropColumn(['check_number', 'check_due_date']);
        });
    }
};
