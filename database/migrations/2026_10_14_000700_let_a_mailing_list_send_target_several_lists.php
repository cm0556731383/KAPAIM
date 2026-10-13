<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** One email can go to several lists at once — each address still gets it once. */
    public function up(): void
    {
        Schema::create('mailing_list_mailing_list_send', function (Blueprint $table) {
            $table->foreignId('mailing_list_send_id')->constrained()->cascadeOnDelete();
            $table->foreignId('mailing_list_id')->constrained()->restrictOnDelete();
            $table->primary(['mailing_list_send_id', 'mailing_list_id']);
        });

        DB::table('mailing_list_sends')->orderBy('id')->each(fn ($send) => DB::table('mailing_list_mailing_list_send')->insert([
            'mailing_list_send_id' => $send->id, 'mailing_list_id' => $send->mailing_list_id,
        ]));

        Schema::table('mailing_list_sends', function (Blueprint $table) {
            $table->dropConstrainedForeignId('mailing_list_id');
        });
    }

    public function down(): void
    {
        Schema::table('mailing_list_sends', function (Blueprint $table) {
            $table->foreignId('mailing_list_id')->nullable()->constrained()->restrictOnDelete();
        });

        Schema::dropIfExists('mailing_list_mailing_list_send');
    }
};
