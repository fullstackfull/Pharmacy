<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Global SEO templates with placeholders, per entity type and language.
 *
 * e.g. product / en: "{product_name} | {brand_name} | {store_name}"
 *      category / ar: "منتجات {category_name} | {store_name}"
 *
 * Templates are the last resort in the resolution chain (after per-language overrides and the
 * existing seo_meta_info row), so they fill gaps without overriding anything an admin set.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('seo_templates')) {
            return;
        }

        Schema::create('seo_templates', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type', 60);            // product | category | brand | vendor | page | blog | home
            $table->string('language_code', 10);
            $table->string('title_template')->nullable();
            $table->string('description_template', 1000)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['entity_type', 'language_code'], 'seo_templates_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_templates');
    }
};
