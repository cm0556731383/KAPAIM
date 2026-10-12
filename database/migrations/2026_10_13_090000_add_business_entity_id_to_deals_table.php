<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The business entity (= SUMIT company) a deal is locked to once SUMIT
     * issued anything for it — every later SUMIT document/charge for the
     * deal must come from the same entity (Deal::lockToBusinessEntity()).
     * Backfilled from each deal's already-sent invoice.
     */
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->foreignId('business_entity_id')->nullable()->after('payment_method_id')->constrained('business_entities')->restrictOnDelete();
        });

        DB::table('deals')->update(['business_entity_id' => DB::raw(
            "(SELECT documents.business_entity_id FROM documents WHERE documents.deal_id = deals.id AND documents.document_type = 'invoice' AND documents.sent_at IS NOT NULL ORDER BY documents.id LIMIT 1)"
        )]);
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('business_entity_id');
        });
    }
};
