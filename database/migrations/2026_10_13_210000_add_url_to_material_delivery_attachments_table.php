<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A materials attachment can be a link instead of a stored file — a video
     * on YouTube/Vimeo/Drive, too big to upload (Cloudflare refuses over
     * 100MB). Exactly one of file_reference/url is set.
     */
    public function up(): void
    {
        Schema::table('material_delivery_attachments', function (Blueprint $table) {
            $table->string('file_reference')->nullable()->change();
            $table->string('url', 2048)->nullable()->after('file_name');
        });
    }

    public function down(): void
    {
        Schema::table('material_delivery_attachments', function (Blueprint $table) {
            $table->dropColumn('url');
            $table->string('file_reference')->nullable(false)->change();
        });
    }
};
