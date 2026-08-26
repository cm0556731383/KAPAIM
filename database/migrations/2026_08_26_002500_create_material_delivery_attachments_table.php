<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Build-plan 10 — MATERIAL_DELIVERY_ATTACHMENT. Per the build-plan's own
     * clarifying note (docs/build-plan/10-materials-mailing.md), this table
     * stores METADATA ONLY, for activity-log/audit purposes — it is
     * deliberately NOT a real file-storage table:
     *   - file_name: the original uploaded file's display name.
     *   - file_reference: the temporary upload token/identifier the file
     *     briefly had in Livewire's temporary upload storage at attach time
     *     — NOT a permanent path into storage/app. The actual file bytes are
     *     forwarded to Smove at send time and then discarded (Livewire's
     *     temp file is explicitly deleted, never copied into permanent
     *     storage) — see MaterialDelivery::sendFor() and
     *     ⚡customer-detail.blade.php's sendMaterials(). This resolves what
     *     would otherwise look like a contradiction against docs/erd.md's
     *     bare MATERIAL_DELIVERY_ATTACHMENT block (FR-5.6/FR-5.20).
     */
    public function up(): void
    {
        Schema::create('material_delivery_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('material_delivery_id')->constrained()->cascadeOnDelete();
            $table->string('file_reference');
            $table->string('file_name');
            $table->timestamps();

            $table->index('material_delivery_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_delivery_attachments');
    }
};
