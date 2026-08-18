<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('visit_sessions')) {
            Schema::create('visit_sessions', function (Blueprint $table) {
                $table->id();
                $table->string('visitor_id', 64)->index();
                $table->string('channel', 8)->default('web');           // web | api
                $table->string('user_type', 16)->nullable();            // guest|customer|vendor|admin|deliveryman
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('entry_path', 191)->nullable();
                $table->string('referrer_domain', 191)->nullable()->index();
                $table->string('utm_source', 96)->nullable()->index();
                $table->string('utm_medium', 96)->nullable();
                $table->string('utm_campaign', 96)->nullable();
                $table->string('device', 16)->nullable();               // desktop|mobile|tablet|bot|app
                $table->string('ip_hash', 64)->nullable();
                $table->unsignedInteger('pageviews')->default(0);
                $table->timestamp('started_at')->index();
                $table->timestamp('last_activity_at')->index();
            });
        }

        if (!Schema::hasTable('telemetry_requests')) {
            Schema::create('telemetry_requests', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('session_id')->nullable()->index();
                $table->string('visitor_id', 64)->nullable();
                $table->string('channel', 8)->index();                  // web | api
                $table->string('method', 8);
                $table->string('route_name', 128)->nullable()->index();
                $table->string('path', 191)->index();
                $table->unsignedSmallInteger('status')->index();
                $table->unsignedInteger('duration_ms')->nullable();
                $table->string('user_type', 16)->nullable();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('referrer_domain', 191)->nullable();
                $table->timestamp('created_at')->index();
            });
        }

        if (!Schema::hasTable('telemetry_daily')) {
            Schema::create('telemetry_daily', function (Blueprint $table) {
                $table->id();
                $table->date('date');
                // totals|path|source|device|product|shop|api_group|status_class|utm_source
                $table->string('scope', 24);
                $table->string('key', 191);
                $table->unsignedBigInteger('hits')->default(0);
                $table->unsignedBigInteger('visitors')->default(0);
                $table->unsignedBigInteger('errors')->default(0);
                $table->unsignedInteger('avg_duration_ms')->nullable();
                $table->decimal('revenue', 24, 2)->nullable();
                $table->unsignedBigInteger('orders')->nullable();
                $table->unique(['date', 'scope', 'key']);
                $table->index(['scope', 'date']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('telemetry_requests');
        Schema::dropIfExists('telemetry_daily');
        Schema::dropIfExists('visit_sessions');
    }
};
