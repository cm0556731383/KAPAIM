<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `customer_id` exists now per docs/erd.md for stage 5 (customers) to use
     * later — unused while only leads exist. `role`, `phone_secondary` and
     * `email_secondary` are pragmatic additions beyond the bare docs/erd.md
     * field list: US-001's acceptance criteria explicitly requires storing
     * "שם מלא, תפקיד, טלפון ראשי, טלפון נוסף, דוא"ל ראשי ודוא"ל נוסף" per
     * contact (docs/prd.md), which the ERD's single phone/email columns
     * don't cover. `is_primary` allows multiple true rows per school
     * ("אחד או יותר", US-001). Removing a contact is a logical delete
     * (deleted_at) — never a real one — and is logged to activity_logs.
     */
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable();
            $table->string('name');
            $table->string('role')->nullable();
            $table->string('phone')->nullable();
            $table->string('phone_secondary')->nullable();
            $table->string('email')->nullable();
            $table->string('email_secondary')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_accounting_contact')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index('school_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};
