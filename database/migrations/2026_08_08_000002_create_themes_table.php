<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Professional Theme System (Phase 1.1) — theme registry.
 *
 * Additive: new table only, guarded by hasTable. The existing env/WEB_THEME view resolution is
 * untouched; this layer is consulted only where a published theme version exists (compatibility
 * shim documented in docs/audit/phase-1-approach.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('themes')) {
            return;
        }

        Schema::create('themes', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug', 120)->unique();
            $table->string('description', 500)->nullable();
            $table->string('preview_image', 2048)->nullable();
            $table->boolean('is_active')->default(false);   // exactly one theme is active at a time
            $table->boolean('is_system')->default(false);   // built-in (default / aster) vs user-created
            $table->string('created_by_type', 40)->nullable();
            $table->unsignedBigInteger('created_by_id')->nullable();
            $table->timestamps();

            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('themes');
    }
};
