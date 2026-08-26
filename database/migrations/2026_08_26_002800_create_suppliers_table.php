<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Build-plan 11 — SUPPLIER (FR-6.14/FR-6.15). classification reuses the
     * exact same three values as BusinessEntity.classification (עוסק פטור /
     * עוסק מורשה / חברה בעמ — see ⚡settings.blade.php's
     * $businessEntityClassifications) even though SUPPLIER and
     * BUSINESS_ENTITY stay two separate tables/concepts: a supplier is who
     * WE pay; a business entity is who WE issue documents as.
     *
     * No is_active/soft-delete column: docs/storyboard/suppliers.html never
     * shows a disable/delete action for a supplier row, only "עריכה" — this
     * stage's judgment call, consistent with this codebase's project-wide
     * "no delete route" convention (editing in place is enough here, there's
     * no historical snapshot of a supplier to protect the way Program/Bundle
     * protect a deal's snapshot).
     */
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('company_number')->nullable();
            $table->string('classification');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suppliers');
    }
};
