<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-category operational governance (Phase 3, Stage A — spec item 10).
 *
 * Measured: `categories` carries name, slug, icon, parent, position — and nothing operational. Every
 * governance decision is global: `refund_day_limit` is one number for the entire store, there is no
 * per-category tax class, no required attributes, and no way to say "products in this category always
 * need moderation." The spec asks for all of these per category, because cosmetics and electronics do
 * not share a return policy or a required-field set.
 *
 * One row per category, every field nullable so an ungoverned category inherits the global default
 * exactly as today — installing this and setting nothing changes no behaviour. Commission is
 * deliberately NOT duplicated here: the commission engine already resolves a category-scoped rule, so
 * governance points at that rather than storing a second rate that could drift from it.
 *
 * Additive and reversible: new table only, guarded, with a working down().
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('category_governance')) {
            return;
        }

        Schema::create('category_governance', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('category_id');

            // Return window in days. Null = inherit the global refund_day_limit. 0 = no returns.
            $table->unsignedInteger('return_window_days')->nullable();

            // Attribute keys a product in this category must fill before it can be approved.
            $table->json('required_attributes')->nullable();

            // A tax-class label the tax engine can key on later. Free text, since class names differ
            // by market and this platform is not tied to one.
            $table->string('tax_class', 60)->nullable();

            // When true, every product in this category is held for moderation regardless of the
            // seller's trust level — connects to the product moderation built alongside this.
            $table->boolean('requires_moderation')->default(0);

            // Categories that cannot ship to certain destinations (e.g. aerosols by air). Kept as a
            // flag plus a note here; the shipping engine will act on it when it exists.
            $table->boolean('shipping_restricted')->default(0);
            $table->string('restriction_note', 512)->nullable();

            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique('category_id', 'cg_category_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('category_governance');
    }
};
