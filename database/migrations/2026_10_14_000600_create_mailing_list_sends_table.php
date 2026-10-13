<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** An email sent to a local mailing list from ⚡mailing-lists (via Smove, queued) — its history row. */
    public function up(): void
    {
        Schema::create('mailing_list_sends', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mailing_list_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('subject');
            $table->string('content_type'); // text | html
            $table->longText('body_html');
            $table->unsignedInteger('recipient_count')->default(0);
            $table->string('status'); // queued | sent | failed
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mailing_list_sends');
    }
};
