<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SEO Redirect Manager — storage for 301/302 rules.
 *
 * Purely additive: creates a new table only, guarded by hasTable so it is safe to run on top of
 * the existing 6Valley database dump. No existing table/column is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('redirects')) {
            return;
        }

        Schema::create('redirects', function (Blueprint $table) {
            $table->id();
            $table->string('from_path', 191);          // normalized source path (indexable length)
            $table->string('to_path', 2048);            // target path or absolute URL
            $table->unsignedSmallInteger('status_code')->default(301); // 301 | 302
            $table->string('match_type', 20)->default('exact');        // exact | prefix
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('hits')->default(0);
            $table->timestamp('last_hit_at')->nullable();
            $table->string('note', 500)->nullable();
            $table->string('created_by_type', 40)->nullable();          // admin | vendor | system
            $table->unsignedBigInteger('created_by_id')->nullable();
            $table->timestamps();

            $table->index(['from_path', 'is_active']);
            $table->index(['match_type', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('redirects');
    }
};
