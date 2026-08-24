<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What was actually sent to a seller, and whether it arrived.
 *
 * The platform has known things about sellers for a while and told them none of it. An SLA breach
 * writes a row in `seller_sla_breaches`; an insight writes a row in `seller_insights`; neither has
 * ever touched the notification stack, which is complete and sitting unused. The gap between what
 * the platform knows and what a seller finds out is this table's reason to exist.
 *
 * It is a delivery log, not a message queue. Two properties make it worth having rather than firing
 * and forgetting:
 *
 * **Dedup.** A digest key stops the same fact being sent twice. Detection runs on a schedule, so
 * without this every sweep would re-announce the same forty late orders and the seller would stop
 * reading any of it — which is the failure mode that makes alerting worthless.
 *
 * **Aggregation.** One row can stand for many subjects. "Fifty orders need shipping within two
 * hours" is one message with fifty things behind it, not fifty messages.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('seller_notification_deliveries')) {
            return;
        }

        Schema::create('seller_notification_deliveries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id');

            /** What this is about, e.g. ORDER_SLA. Matches the detector that raised it. */
            $table->string('topic', 60);

            /** critical | high | medium | low — decides whether it is worth interrupting anyone. */
            $table->string('severity', 20)->default('medium');

            $table->string('title', 191);
            $table->text('body')->nullable();

            /**
             * How many things this one message stands for. One message about fifty orders beats
             * fifty messages, and the number is the part that makes it actionable.
             */
            $table->unsignedInteger('subject_count')->default(1);

            /** Where tapping it should land, and what that view needs to filter itself. */
            $table->string('action_key', 60)->nullable();
            $table->json('action_params')->nullable();

            /**
             * Identity of the fact, not of the message: (seller, topic, window). Sending the same
             * fact twice is how an alerting system teaches people to ignore it.
             */
            $table->string('digest_key', 191);

            /** queued | sent | failed | suppressed */
            $table->string('status', 20)->default('queued');

            /** Which ways it went out, and what each said. */
            $table->json('channels')->nullable();
            $table->text('error')->nullable();

            $table->timestamp('sent_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->unique('digest_key');
            // The seller's own notification list, newest first.
            $table->index(['seller_id', 'created_at'], 'snd_seller_time_idx');
            // And the sweep's own question: has this topic been sent recently?
            $table->index(['seller_id', 'topic', 'sent_at'], 'snd_seller_topic_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_notification_deliveries');
    }
};
