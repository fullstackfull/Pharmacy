<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-language SEO overrides (Phase 1.6 — bilingual AR/EN).
 *
 * Why a separate table: `seo_meta_info` carries a unique index on (seoable_type, seoable_id), so it
 * can hold exactly one row per entity and cannot represent multiple languages. Rather than alter
 * that index (destructive, and every existing writer assumes one row), this table layers language
 * specific overrides on top. Resolution order is: translation for the active locale -> existing
 * seo_meta_info row -> template -> null. Existing single-language behavior is unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('seo_meta_translations')) {
            return;
        }

        Schema::create('seo_meta_translations', function (Blueprint $table) {
            $table->id();
            $table->string('seoable_type', 191);
            $table->unsignedBigInteger('seoable_id');
            $table->string('language_code', 10);          // en, ar, sa, sy, ar-KW ...
            $table->string('title')->nullable();
            $table->string('description', 1000)->nullable();
            $table->string('keywords', 500)->nullable();
            $table->string('canonical_url', 2048)->nullable();
            $table->string('og_title')->nullable();
            $table->string('og_description', 1000)->nullable();
            $table->string('og_image', 2048)->nullable();
            $table->boolean('is_indexable')->default(true);
            $table->boolean('is_followable')->default(true);
            $table->timestamps();

            $table->unique(['seoable_type', 'seoable_id', 'language_code'], 'seo_meta_translations_unique');
            $table->index(['seoable_type', 'seoable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_meta_translations');
    }
};
