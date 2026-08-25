<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_entities', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->enum('classification', ['עוסק פטור', 'עוסק מורשה', 'חברה בעמ']);
            $table->string('company_number');
            $table->string('email');
            $table->string('phone');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_entities');
    }
};
