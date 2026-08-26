<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Build-plan 10 — MATERIAL_DELIVERY. The only place a row here is ever
     * created is MaterialDelivery::sendFor() (FR-5.1/FR-5.9) — every send,
     * including a resend of the same program's materials, is its own new
     * row, never an update-in-place.
     *
     * program_id (FR-5.19) is the specific catalog PROGRAM row (build-plan
     * 03) whose materials are being sent — the program "library" already
     * exists as the `programs` table, so no separate materials-library table
     * is added here.
     *
     * Deliberately NOT in this table (see docs/build-plan/10-materials-mailing.md's
     * clarifying note and this stage's report):
     *   - deal_id: FR-5.8 ("associated with the relevant deal") is satisfied
     *     by logging deal_id onto the ACTIVITY_LOG entry MaterialDelivery::sendFor()
     *     writes (best-effort lookup by customer+program — some sends, e.g. a
     *     subscription-program delivery, may have no matching deal at all),
     *     not by a column here — matches the bare docs/erd.md MATERIAL_DELIVERY
     *     block exactly, no schema deviation needed.
     *   - the sent files themselves: FR-5.6/FR-5.20 — see
     *     material_delivery_attachments below for the metadata-only design.
     *
     * handled_at is this build's minimal-scope addition beyond the bare ERD
     * block, explicitly called for by FR-5.17's "or a manual mark that
     * handling is complete" half — stage 15 (dashboard) doesn't exist yet, so
     * this is the only way today to dismiss a stale "needs attention" item
     * (see MaterialDelivery::needingAttention()/markHandled()).
     *
     * opened_at (FR-5.10/FR-5.11) is set by MaterialDelivery::markOpened() —
     * real email-open tracking requires an actual Smove-sent email plus a
     * tracking pixel/webhook, which doesn't exist until build-plan 12, so
     * nothing in this stage calls markOpened() automatically yet (see this
     * stage's report). acknowledged_at (FR-5.12/FR-5.13) IS fully real today
     * — see the public signed /materials/{materialDelivery}/acknowledge route.
     *
     * A delivery is never deleted (matches every other business-record
     * convention in this codebase) — no soft-deletes column.
     */
    public function up(): void
    {
        Schema::create('material_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('program_id')->constrained()->restrictOnDelete();
            $table->foreignId('status_id')->constrained('status_definitions')->restrictOnDelete();
            $table->timestamp('sent_at');
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('handled_at')->nullable();
            $table->timestamps();

            $table->index('customer_id');
            $table->index('program_id');
            $table->index('status_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_deliveries');
    }
};
