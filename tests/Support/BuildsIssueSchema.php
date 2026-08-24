<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The issue table, in one place.
 *
 * Several suites need `seller_insights` and the shape now has twenty-odd columns. Copying it into
 * each of them is how a test file ends up describing a table the application stopped having — which
 * is exactly what happened when the issue columns were added and one suite's hand-written schema
 * silently fell behind.
 */
trait BuildsIssueSchema
{
    protected function createIssueTable(): void
    {
        Schema::dropIfExists('seller_insights');

        Schema::create('seller_insights', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id');
            $table->string('type', 60);
            $table->string('category', 40)->nullable();
            $table->string('severity', 20)->default('medium');
            $table->string('status', 20)->default('open');
            $table->string('title', 191);
            $table->text('body')->nullable();
            $table->string('entity_type', 60)->nullable();
            $table->string('entity_id', 60)->nullable();
            $table->decimal('metric', 24, 4)->nullable();
            $table->decimal('impact', 24, 4)->nullable();
            $table->unsignedTinyInteger('impact_score')->default(0);
            $table->unsignedInteger('affected_count')->default(1);
            $table->string('action_key', 60)->nullable();
            $table->text('action_params')->nullable();
            $table->text('metadata')->nullable();
            $table->string('fingerprint', 191)->unique();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('first_detected_at')->nullable();
            $table->timestamp('last_detected_at')->nullable();
            $table->unsignedInteger('detection_count')->default(1);
            $table->unsignedTinyInteger('escalation_level')->default(0);
            $table->unsignedBigInteger('assigned_staff_id')->nullable();
            $table->timestamp('dismissed_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolution_type', 40)->nullable();
            $table->text('resolution_message')->nullable();
            $table->timestamps();
        });
    }
}
