<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Config shell only — actually wired to real Smove/Summit calls in build-plan
     * stage 12.
     */
    public function up(): void
    {
        Schema::create('external_integration_settings', function (Blueprint $table) {
            $table->id();
            $table->string('system')->unique();
            $table->boolean('is_active')->default(false);
            $table->json('settings')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_integration_settings');
    }
};
