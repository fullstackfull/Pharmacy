<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Phase 3.3 — Campaigns. One NEW table; the base pages a campaign dresses are never written by
 * it (§33–34): overrides live here, the published theme_sections stay exactly as published, and
 * when the window closes the base page is simply what it always was.
 *
 * Overrides ride a JSON column rather than a child table: a campaign carries at most a handful
 * of validated slot overrides, they are read only ever as the whole set, and a second table
 * would add joins to a read that happens on every Home request.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('experience_campaigns')) {
            Schema::create('experience_campaigns', function (Blueprint $table) {
                $table->id();
                $table->string('name', 120);
                $table->string('status', 20)->default('draft');
                $table->string('page', 60)->default('home');
                // Deterministic slot arbitration (§36): highest priority wins a contested slot.
                $table->unsignedInteger('priority')->default(30);
                $table->timestamp('starts_at')->nullable();
                $table->timestamp('ends_at')->nullable();
                // Validated [{slot, section: {type, settings}}] rows (CampaignRules).
                $table->json('overrides')->nullable();
                $table->timestamps();

                $table->index(['status', 'page'], 'experience_campaigns_serving');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('experience_campaigns');
    }
};
