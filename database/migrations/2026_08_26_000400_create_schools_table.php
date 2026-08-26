<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * SCHOOL is its own entity separate from LEAD (build-plan 04) so a repeat
     * inquiry from the same school updates the existing lead instead of
     * creating a new one (FR-1.9). `city` is a pragmatic addition beyond the
     * bare docs/erd.md field list (id/name/phone/email/address) — the leads
     * list/edit storyboards (docs/storyboard/leads.html, lead-edit.html)
     * filter and edit city as its own field, and matching it reliably out of
     * a freeform `address` string would be fragile.
     */
    public function up(): void
    {
        Schema::create('schools', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('city')->nullable();
            $table->string('address')->nullable();
            $table->timestamps();

            $table->index('name');
            $table->index('city');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schools');
    }
};
