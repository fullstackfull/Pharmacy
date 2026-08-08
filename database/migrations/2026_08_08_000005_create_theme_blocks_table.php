<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Theme blocks — reusable child elements inside a section (e.g. slides in a slider, columns in a
 * footer, links in a menu). Ordered and independently toggleable, with a JSON settings payload.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('theme_blocks')) {
            return;
        }

        Schema::create('theme_blocks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('theme_section_id');
            $table->string('type', 80);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_visible')->default(true);
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->index(['theme_section_id', 'sort_order']);

            $table->foreign('theme_section_id')->references('id')->on('theme_sections')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('theme_blocks');
    }
};
