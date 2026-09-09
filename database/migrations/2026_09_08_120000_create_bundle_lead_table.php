<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * LEAD }o--o{ BUNDLE "expresses interest" — same shape/convention as
     * lead_program (build-plan 04), extended so a lead can also express
     * interest in a bundle, not just individual programs. Table named
     * bundle_lead (not lead_bundle) to match Laravel's default alphabetical
     * pivot-naming convention, same as bundle_program.
     */
    public function up(): void
    {
        Schema::create('bundle_lead', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bundle_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['lead_id', 'bundle_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bundle_lead');
    }
};
