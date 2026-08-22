<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What each endpoint actually answers with, observed rather than declared.
 *
 * The manifest derives everything else from the route table, the controllers and their validation.
 * Responses are the one thing it cannot reach: this API returns `response()->json(...)` directly
 * nearly a thousand times and has almost no Resource classes, so there is no type to reflect. So
 * the shape is recorded from real traffic — keys and types only, no values ever — and one row per
 * route, method and status class is all it takes to describe the whole surface.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('api_response_shapes')) {
            return;
        }

        Schema::create('api_response_shapes', function (Blueprint $table) {
            $table->id();
            // The route PATTERN, so /api/v1/products/details/{slug} is one row rather than one per
            // product — the same key the manifest and monitoring use.
            $table->string('route', 191);
            $table->string('method', 10);
            $table->unsignedSmallInteger('status');
            // Keys and types. No leaf value from a response is ever written here.
            $table->longText('shape');
            $table->unsignedInteger('samples')->default(1);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');

            $table->unique(['route', 'method', 'status'], 'api_response_shape_identity');
            $table->index('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_response_shapes');
    }
};
