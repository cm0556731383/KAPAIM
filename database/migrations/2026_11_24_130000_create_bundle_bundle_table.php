<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bundles included in a subscription bundle (owner's call, 2026-11-24): a
 * bundle flagged is_subscription_type may hold, next to its own programs,
 * other (non-subscription) bundles — their programs count as the
 * subscription's (Bundle::allPrograms()). One level only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bundle_bundle', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_bundle_id')->constrained('bundles')->cascadeOnDelete();
            $table->foreignId('child_bundle_id')->constrained('bundles')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['parent_bundle_id', 'child_bundle_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bundle_bundle');
    }
};
