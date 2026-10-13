<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** An expense recorded by mistake (no invoice attached yet) can be removed — a logical delete. */
    public function up(): void
    {
        Schema::table('expenses', fn (Blueprint $table) => $table->softDeletes());
    }

    public function down(): void
    {
        Schema::table('expenses', fn (Blueprint $table) => $table->dropSoftDeletes());
    }
};
