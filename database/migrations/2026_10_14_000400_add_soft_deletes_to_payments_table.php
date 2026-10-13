<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** A payment recorded by mistake (no receipt, not from SUMIT) can be removed — a logical delete. */
    public function up(): void
    {
        Schema::table('payments', fn (Blueprint $table) => $table->softDeletes());
    }

    public function down(): void
    {
        Schema::table('payments', fn (Blueprint $table) => $table->dropSoftDeletes());
    }
};
