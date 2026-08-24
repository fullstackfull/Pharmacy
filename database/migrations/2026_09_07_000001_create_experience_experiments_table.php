<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Phase 3.5 — Experiments. One NEW table. An experiment varies ONE composed section's settings
 * per variant; control is the section exactly as published, which is also what everybody gets
 * the moment the experiment stops, breaks, or the engine is switched off.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('experience_experiments')) {
            Schema::create('experience_experiments', function (Blueprint $table) {
                $table->id();
                $table->string('name', 120);
                $table->string('key', 60)->unique();
                $table->string('status', 20)->default('draft');
                $table->string('page', 60)->default('home');
                // The section under test, by the uuid that survives republishes.
                $table->uuid('section_uuid');
                // Validated [{key, weight, settings}] rows; weights are percentages, the
                // remainder is control (ExperimentRules).
                $table->json('variants')->nullable();
                $table->timestamps();

                $table->index(['status', 'page'], 'experience_experiments_serving');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('experience_experiments');
    }
};
