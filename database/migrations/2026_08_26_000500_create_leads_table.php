<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A lead has exactly one active status at a time (FR-1.4), modeled as a
     * single status_id FK rather than a history table — status *changes*
     * are still logged to activity_logs (FR-1.8). `sub_status` is a plain
     * string for the yellow-only "ממתינה לשיחה חוזרת" sub-status (FR-1.6).
     * `notes` is a pragmatic addition beyond the bare docs/erd.md field list —
     * FR-1.7 requires storing lead notes ("ההערות") and the ERD has no other
     * column for them. `converted_at` exists now per the ERD for stage 5
     * (lead → customer conversion) to set later; this stage never sets it.
     */
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('lead_source_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('status_id')->constrained('status_definitions')->restrictOnDelete();
            $table->string('email');
            $table->string('phone');
            $table->string('sub_status')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('converted_at')->nullable();
            $table->timestamps();

            $table->index('email');
            $table->index('phone');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
