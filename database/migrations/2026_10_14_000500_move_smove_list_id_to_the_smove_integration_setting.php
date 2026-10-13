<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One Smove list for everyone (owner's call, 2026-10-14): Smove resets a
     * confirmed contact's consent on any later list join, so separate Smove
     * lists per local list never actually segmented anyone. The single list
     * id now lives in the Smove integration setting (settings.list_id); the
     * local lists stay for counting and for sending to a chosen audience.
     * The primary list's id carries over.
     */
    public function up(): void
    {
        $listId = DB::table('mailing_lists')->where('list_type', 'primary')->value('smove_list_id')
            ?? DB::table('mailing_lists')->whereNotNull('smove_list_id')->orderBy('id')->value('smove_list_id');

        if ($listId) {
            $row = DB::table('external_integration_settings')->where('system', 'smove')->first();

            if ($row) {
                $settings = json_decode($row->settings ?? '[]', true) ?: [];
                $settings['list_id'] = (int) $listId;
                DB::table('external_integration_settings')->where('id', $row->id)->update(['settings' => json_encode($settings)]);
            } else {
                DB::table('external_integration_settings')->insert([
                    'system' => 'smove', 'is_active' => false, 'settings' => json_encode(['list_id' => (int) $listId]),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        Schema::table('mailing_lists', fn (Blueprint $table) => $table->dropColumn('smove_list_id'));
    }

    public function down(): void
    {
        Schema::table('mailing_lists', fn (Blueprint $table) => $table->unsignedBigInteger('smove_list_id')->nullable());
    }
};
