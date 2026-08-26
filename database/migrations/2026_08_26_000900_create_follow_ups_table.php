<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Unlimited FOLLOW_UP ("Up Follow") rows per lead (FR-1.10). `next_at` is
     * nullable — required "כאשר נדרש" (FR-1.11), not on every follow-up.
     */
    public function up(): void
    {
        Schema::create('follow_ups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lead_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('occurred_at');
            $table->text('summary')->nullable();
            $table->text('result')->nullable();
            $table->timestamp('next_at')->nullable();
            $table->timestamps();

            $table->index('lead_id');
            $table->index('next_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('follow_ups');
    }
};
