<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `customer_id`/`deal_id` exist now per docs/erd.md for future stages
     * (5/6) to use later — unused while only leads exist. TASK gets
     * `deleted_at` too: the build-plan note on CONTACT's logical-delete
     * convention (removal never a real delete, always logged) explicitly
     * says it applies to future entities like TASK as well.
     */
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable();
            $table->foreignId('deal_id')->nullable();
            $table->string('task_type')->default('reminder');
            $table->string('status')->default('open');
            $table->string('title');
            $table->text('description')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('lead_id');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
