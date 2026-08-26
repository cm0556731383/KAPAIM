<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Build-plan 05: a CUSTOMER is created only by converting a LEAD
     * (FR-2.3, FR-8.2) — `lead_id` is unique so a lead can convert at most
     * once (FR-1.15/FR-2.3/FR-8.2). `school_id` mirrors the lead's school at
     * conversion time (FR-2.1 — one customer card per school; FR-2.2 — a
     * branch of an education network still gets its own separate customer
     * card via its own lead/school row, enforced operationally rather than
     * in this schema). `status_id` is scoped 'customer' on STATUS_DEFINITION
     * (ReferenceDataSeeder already seeds "פעילה"/"לא פעילה"). Customers are
     * never deleted (FR-2.4/FR-8.3) — no soft-deletes column, no delete route.
     */
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->restrictOnDelete();
            $table->foreignId('lead_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('status_id')->constrained('status_definitions')->restrictOnDelete();
            $table->timestamp('converted_at');
            $table->timestamps();

            $table->index('school_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
