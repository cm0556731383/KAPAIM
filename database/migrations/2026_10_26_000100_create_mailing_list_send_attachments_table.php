<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Materials attached to a mailing-list email (files kept here and
     * linked, or plain links) — same shape as material_delivery_attachments.
     * mailing_list_send_id stays null while the email is only previewed or
     * test-sent, and is set once it is really sent.
     */
    public function up(): void
    {
        Schema::create('mailing_list_send_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mailing_list_send_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('file_reference')->nullable();
            $table->string('file_name');
            $table->string('url', 2048)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mailing_list_send_attachments');
    }
};
