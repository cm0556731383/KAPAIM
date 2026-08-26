<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Unlimited LEAD_INTERACTION rows per lead (build-plan 04) — free-text
     * call/contact log, distinct from FOLLOW_UP which additionally tracks a
     * scheduled next action (`next_at`).
     */
    public function up(): void
    {
        Schema::create('lead_interactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('interaction_type');
            $table->text('summary')->nullable();
            $table->text('result')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index('lead_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_interactions');
    }
};
