<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The record of every transactional message this shop tries to send.
 *
 * Twenty-three listeners send the platform's entire transactional traffic — order status, refunds,
 * wallet, OTP, verification, restock, referral, seller onboarding — over three channels, and not one
 * of them recorded whether the message arrived. The fourteen SMS providers return the literal string
 * `error` and persist nothing, Mail:: bypasses the HTTP-client middleware the dependency monitor
 * watches, and FCM goes out through a trait. So a shop whose SMS credentials expired sends no OTP,
 * no customer can sign in, and every screen in the monitoring console stays green.
 *
 * Kept in the application database rather than the monitoring one on purpose. This is not telemetry
 * — it is the operational record a support agent opens to answer "did she get the email", and the
 * source a resend is driven from, so it has to be present and consistent with the order it belongs
 * to even on an installation where monitoring is switched off or points at another host.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('notification_deliveries')) {
            return;
        }

        Schema::create('notification_deliveries', function (Blueprint $table) {
            $table->id();
            // mail | sms | push
            $table->string('channel', 8);
            // What it was: a mailable's class basename, an OTP purpose, a push notification key.
            $table->string('event', 96)->nullable();
            // Full, not masked: an admin can already read a customer's email on their order, and a
            // masked address cannot be resent to.
            $table->string('recipient', 191)->nullable();
            $table->string('user_type', 16)->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('subject', 191)->nullable();
            // The rendered message, so a resend sends what was sent rather than a fresh render of a
            // template that may have changed since. Capped by the writer.
            $table->longText('body')->nullable();
            // Channel payload for sms and push, where there is no rendered body.
            $table->json('payload')->nullable();
            // pending | sent | failed
            $table->string('status', 10)->default('pending');
            $table->text('error')->nullable();
            $table->unsignedSmallInteger('attempts')->default(1);
            // A resend points at the delivery it repeats, so the log reads as a history rather than
            // as two unrelated messages that happen to look alike.
            $table->unsignedBigInteger('resent_from_id')->nullable();
            $table->unsignedBigInteger('resent_by')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['channel', 'status', 'created_at'], 'notification_delivery_triage');
            $table->index(['recipient', 'created_at'], 'notification_delivery_recipient');
            $table->index('resent_from_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
    }
};
