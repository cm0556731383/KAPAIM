<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->string('resource');
            $table->string('action');
            $table->boolean('is_allowed')->default(true);
            $table->timestamps();

            $table->unique(['role_id', 'resource', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_permissions');
    }
};
