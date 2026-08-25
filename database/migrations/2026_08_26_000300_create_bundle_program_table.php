<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * BUNDLE }o--o{ PROGRAM (docs/erd.md line ~44) — plain pivot, no extra
     * columns specified by the ERD. Membership rows can be freely
     * added/removed (attach/detach) while editing a bundle's contents;
     * unlike is_active on the catalog rows themselves, there is no
     * historical-snapshot concern here since DEAL (stage 6) doesn't exist
     * yet to have referenced a bundle's contents at time-of-purchase.
     */
    public function up(): void
    {
        Schema::create('bundle_program', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bundle_id')->constrained()->cascadeOnDelete();
            $table->foreignId('program_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['bundle_id', 'program_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bundle_program');
    }
};
