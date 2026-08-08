<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Theme versions — the draft / published / archived lifecycle.
 *
 * Publish is a status swap (draft -> published; previous published -> archived), so it is atomic
 * and non-destructive, and rollback is selecting a prior version. `settings` holds the global
 * theme config (branding, colors, typography, layout) as a JSON bag — appropriate for a
 * schema-less settings payload; structural data (sections/blocks) is normalized into its own tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('theme_versions')) {
            return;
        }

        Schema::create('theme_versions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('theme_id');
            $table->string('label')->nullable();
            $table->string('status', 20)->default('draft'); // draft | published | archived
            $table->json('settings')->nullable();
            $table->unsignedBigInteger('based_on_version_id')->nullable(); // duplication / revision lineage
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['theme_id', 'status']);

            $table->foreign('theme_id')->references('id')->on('themes')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('theme_versions');
    }
};
