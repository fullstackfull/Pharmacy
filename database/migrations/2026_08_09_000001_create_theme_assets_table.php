<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Theme assets (Phase 1.1) — images a merchant uploads for a theme (logos, favicons, section
 * backgrounds) instead of pasting external URLs.
 *
 * Additive: new table only, guarded. Assets belong to a THEME rather than a version, so they
 * survive publishing and version restores — losing a logo because a draft was rolled back would be
 * surprising and destructive.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('theme_assets')) {
            return;
        }

        Schema::create('theme_assets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('theme_id');
            $table->string('label')->nullable();
            $table->string('path', 2048);                 // storage-relative path
            $table->string('disk', 40)->default('public');
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('uploaded_by_type', 40)->nullable();
            $table->unsignedBigInteger('uploaded_by_id')->nullable();
            $table->timestamps();

            $table->index('theme_id');

            $table->foreign('theme_id')->references('id')->on('themes')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('theme_assets');
    }
};
