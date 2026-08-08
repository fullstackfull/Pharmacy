<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Theme sections — the section-based page architecture (home / header / footer / …).
 *
 * A section belongs to a theme version; ordering, visibility and per-section settings are stored
 * here so pages are composed from configurable sections instead of hardcoded markup.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('theme_sections')) {
            return;
        }

        Schema::create('theme_sections', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('theme_version_id');
            $table->string('page', 60)->default('home');   // home | header | footer | ...
            $table->string('type', 80);                     // hero | product_slider | category_grid | ...
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_visible')->default(true);
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->index(['theme_version_id', 'page', 'sort_order']);

            $table->foreign('theme_version_id')->references('id')->on('theme_versions')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('theme_sections');
    }
};
