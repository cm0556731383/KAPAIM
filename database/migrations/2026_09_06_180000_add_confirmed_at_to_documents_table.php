<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Build-plan 12 follow-up — the online sign/confirm form (routes
     * documents.sign) needs one generic "the recipient clicked confirm"
     * timestamp that applies to every document_type, including quote/
     * invoice/credit_note which have no dedicated status column of their
     * own (unlike order_form's received_at / contract's signed_at, which
     * Document::confirm() still sets in addition to this for the types that
     * have them, so the existing FR-4.3/FR-4.4 gates are untouched).
     */
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->timestamp('confirmed_at')->nullable()->after('signed_at');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn('confirmed_at');
        });
    }
};
