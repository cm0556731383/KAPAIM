<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * LEAD }o--o{ PROGRAM "expresses interest" (docs/erd.md) — plain pivot,
     * same convention as bundle_program (build-plan 03). Rows can be freely
     * attached/detached from the lead-detail screen; there is no
     * historical-snapshot concern here since DEAL (stage 6) doesn't exist
     * yet to have referenced a lead's expressed interest at time-of-sale.
     */
    public function up(): void
    {
        Schema::create('lead_program', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('program_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['lead_id', 'program_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_program');
    }
};
