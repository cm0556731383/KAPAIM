<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `scope` is a plain string (lead/customer/deal/document/payment/subscription/
     * expense/material_delivery) rather than an FK — those entity tables don't exist
     * yet (later build-plan stages), matching the ERD.
     */
    public function up(): void
    {
        Schema::create('status_definitions', function (Blueprint $table) {
            $table->id();
            $table->string('scope');
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index('scope');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('status_definitions');
    }
};
