<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Build-plan 10 — MAILING_LIST. list_type is one of:
     *   - 'primary'      — singleton, every lead/customer ends up here (FR-5.21).
     *   - 'program'      — one per catalog PROGRAM, program_id set (FR-5.22).
     *   - 'subscription' — singleton "subscribers" list (FR-5.23/FR-5.24).
     *   - 'supplier'     — singleton "ספקים" shell seeded now (empty), but
     *                      actually populated only in build-plan 11 once
     *                      SUPPLIER exists (FR-5.26) — not this stage's job.
     *
     * No DB-level unique constraint on (list_type, program_id): the singleton
     * lists (primary/subscription/supplier) all have program_id = null, and a
     * unique index would not actually prevent duplicate NULLs on Postgres —
     * singleton-ness is instead guaranteed the same way StatusDefinition's
     * scope+name singletons are elsewhere in this codebase: every list is
     * only ever obtained through MailingList::primaryList()/subscribersList()/
     * suppliersList()/forProgram(), all firstOrCreate()-backed.
     */
    public function up(): void
    {
        Schema::create('mailing_lists', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('list_type');
            $table->foreignId('program_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('list_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mailing_lists');
    }
};
