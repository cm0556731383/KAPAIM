<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An invoice/credit note issued while SUMIT is in test mode is only a draft
 * there, and SUMIT never emails a draft — so it is recorded here as "a SUMIT
 * draft was made" instead of as sent, and stays issuable again
 * (Document::sendToRecipients(), business owner's call 2026-11-25).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->timestamp('summit_drafted_at')->nullable()->after('sent_at');
            $table->string('summit_draft_reference', 50)->nullable()->after('summit_drafted_at');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn(['summit_drafted_at', 'summit_draft_reference']);
        });
    }
};
