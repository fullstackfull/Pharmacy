<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A seller's own keys and webhooks.
 *
 * The key is never stored. Only a hash of it and the short prefix that identifies which row to
 * check — so the plaintext exists exactly once, in the response that created it, and a database
 * dump does not hand anybody the shops it belongs to.
 *
 * Deliveries are kept as their own rows rather than as a counter on the webhook, because "it is
 * failing" is not a useful thing to tell a seller. "It has been failing since Tuesday with a 500,
 * and here is the last response body" is something they can act on.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('seller_api_keys')) {
            Schema::create('seller_api_keys', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('seller_id');
                $table->unsignedBigInteger('created_by_staff_id')->nullable();

                $table->string('name', 120);

                /** The visible half: enough to tell two keys apart in a list, useless on its own. */
                $table->string('prefix', 16)->unique();

                /** A hash. The key itself is shown once, at creation, and never stored. */
                $table->string('token_hash', 191);

                /**
                 * What this key may do — a subset of the seller's own permissions.
                 *
                 * A key is not an owner. An integration that only needs to read orders should hold
                 * a key that can only read orders, so a leaked key costs the seller their order list
                 * rather than their payouts.
                 */
                $table->json('scopes')->nullable();

                $table->timestamp('last_used_at')->nullable();
                $table->string('last_used_ip', 60)->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamp('revoked_at')->nullable();
                $table->timestamps();

                $table->index(['seller_id', 'revoked_at'], 'sak_seller_state_idx');
            });
        }

        if (!Schema::hasTable('seller_webhooks')) {
            Schema::create('seller_webhooks', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('seller_id');
                $table->string('name', 120);
                $table->string('url', 500);

                /** Which events this endpoint wants. An empty list receives nothing, not everything. */
                $table->json('events')->nullable();

                /** Signs every delivery, so the receiver can tell a real one from anybody's POST. */
                $table->string('secret', 191);

                /** active | paused | disabled — disabled is the platform's, after repeated failure. */
                $table->string('status', 20)->default('active');

                $table->unsignedInteger('consecutive_failures')->default(0);
                $table->timestamp('last_success_at')->nullable();
                $table->timestamp('last_failure_at')->nullable();
                $table->timestamp('disabled_at')->nullable();
                $table->string('disabled_reason', 191)->nullable();
                $table->timestamps();

                $table->index(['seller_id', 'status'], 'swh_seller_status_idx');
            });
        }

        if (!Schema::hasTable('seller_webhook_deliveries')) {
            Schema::create('seller_webhook_deliveries', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('webhook_id');
                $table->unsignedBigInteger('seller_id');

                $table->string('event', 60);
                $table->json('payload')->nullable();

                /** pending | delivered | failed */
                $table->string('status', 20)->default('pending');

                $table->unsignedSmallInteger('attempts')->default(0);
                $table->unsignedSmallInteger('response_code')->nullable();

                /** Truncated: enough to diagnose, not enough to store somebody's whole error page. */
                $table->text('response_body')->nullable();

                $table->text('error')->nullable();
                $table->timestamp('delivered_at')->nullable();
                $table->timestamp('next_attempt_at')->nullable();
                $table->timestamps();

                $table->index(['webhook_id', 'created_at'], 'swd_hook_time_idx');
                $table->index(['seller_id', 'created_at'], 'swd_seller_time_idx');
                $table->index(['status', 'next_attempt_at'], 'swd_retry_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_webhook_deliveries');
        Schema::dropIfExists('seller_webhooks');
        Schema::dropIfExists('seller_api_keys');
    }
};
