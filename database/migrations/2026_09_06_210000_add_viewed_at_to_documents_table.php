<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Build-plan 12 follow-up — the "סטטוס" badge (⚡deal-detail.blade.php,
     * ⚡document-view.blade.php) always showed status_id's own name, which
     * never actually transitions anywhere in this codebase (nothing sets
     * status_id past its draft default) — so the badge was permanently
     * stuck on "טיוטה" regardless of what actually happened to the
     * document. Rather than wire up status_id transitions (a scope/gating
     * concept documents.manage/business rules don't otherwise use),
     * Document::engagementStatusLabel() computes the badge straight from
     * real events: viewed_at (set the first time the public sign form is
     * opened — ⚡document-sign.blade.php's mount()) and confirmed_at
     * (already existed, set by Document::confirm()).
     */
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->timestamp('viewed_at')->nullable()->after('confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn('viewed_at');
        });
    }
};
