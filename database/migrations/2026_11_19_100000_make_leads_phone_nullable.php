<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The customer Excel import opens a lead from the primary contact, whose
     * phone is optional there (2026-11-19: email-only contacts) — so a lead's
     * phone may be empty. The lead form still requires it.
     */
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('phone')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('phone')->nullable(false)->change();
        });
    }
};
