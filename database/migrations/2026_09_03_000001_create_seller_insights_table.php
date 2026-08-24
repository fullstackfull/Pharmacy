<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The one place anything says "a seller should look at this".
 *
 * Before this, every page invented its own alerts: the dashboard counted one thing, the stock page
 * another, the scorecard a third, and none of them agreed or could be dismissed. An insight is a
 * typed, addressed, actionable row that Home, the Action Center, notifications and — later — the
 * assistant all read, so the product speaks with one voice.
 *
 * Precomputed rather than derived on read: these are aggregate queries over orders, stock and ledger
 * entries, and a seller opens Home many times a day. Storing them also buys dismissal, history, and
 * a stable sort the Action Center can page through.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('seller_insights')) {
            return;
        }

        Schema::create('seller_insights', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id');

            /** The producer's own name, e.g. INVENTORY_RISK. Never free text from a caller. */
            $table->string('type', 60);

            /** critical | high | medium | low — what the Action Center sorts and filters on. */
            $table->string('severity', 20)->default('medium');

            $table->string('title', 191);
            $table->text('body')->nullable();

            /** What the insight is about, so the UI can open it directly. */
            $table->string('entity_type', 60)->nullable();
            $table->string('entity_id', 60)->nullable();

            /** The number that triggered it and what it is worth, for explaining rather than asserting. */
            $table->decimal('metric', 24, 4)->nullable();
            $table->decimal('impact', 24, 4)->nullable();

            /** The action a client should offer, and what it needs to perform it. */
            $table->string('action_key', 60)->nullable();
            $table->json('action_params')->nullable();

            /**
             * Identity, not content: (seller, type, entity). A producer that runs again updates the
             * row it wrote last time instead of stacking a second copy of the same warning — which is
             * what turns an alert list into noise nobody reads.
             */
            $table->string('fingerprint', 191);

            $table->timestamp('expires_at')->nullable();
            $table->timestamp('dismissed_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique('fingerprint');
            // The Action Center's own query: this seller's live insights, worst first.
            $table->index(['seller_id', 'dismissed_at', 'resolved_at', 'severity'], 'seller_insights_open_index');
            $table->index(['seller_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_insights');
    }
};
