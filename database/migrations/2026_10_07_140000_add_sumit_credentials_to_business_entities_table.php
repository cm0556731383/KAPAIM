<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Each business entity (עוסק) is its own SUMIT company: an invoice is
     * issued in the SUMIT account of the entity it was generated from
     * (App\Services\Integrations\SummitClient). The API key is stored
     * encrypted (BusinessEntity casts it), hence text.
     */
    public function up(): void
    {
        Schema::table('business_entities', function (Blueprint $table) {
            $table->string('sumit_company_id', 20)->nullable()->after('phone');
            $table->text('sumit_api_key')->nullable()->after('sumit_company_id');
        });
    }

    public function down(): void
    {
        Schema::table('business_entities', function (Blueprint $table) {
            $table->dropColumn(['sumit_company_id', 'sumit_api_key']);
        });
    }
};
