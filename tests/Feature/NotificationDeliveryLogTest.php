<?php

namespace Tests\Feature;

use App\Models\NotificationDelivery;
use App\Services\Notifications\DeliveryLog;
use App\Services\Notifications\DeliveryResender;
use App\Utils\SMSModule;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Whether the message arrived, and whether it can be sent again.
 *
 * Two questions the platform could not answer at all. Twenty-three listeners send its entire
 * transactional traffic — order status, refunds, wallet, OTP, verification, restock, referral,
 * seller onboarding — across three channels, and not one recorded an outcome: the fourteen SMS
 * providers return the literal string 'error' and persist nothing, Mail:: bypasses the HTTP client
 * the dependency monitor watches, and FCM goes out through a trait. So a shop whose SMS credentials
 * expired sent no OTP, no customer could sign in, and every screen in the console stayed green.
 *
 * These tests hold the three seams and the judgement calls around them: a send is recorded before it
 * is confirmed so an unanswered one is visible rather than absent, a resend is a new attempt that
 * points at the one it repeats, and a one-time code is never sent twice.
 */
class NotificationDeliveryLogTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('notification_deliveries');
        (require database_path('migrations/2026_09_18_000001_create_notification_deliveries_table.php'))->up();
    }

    // ─────────────────────────────────────────────────────────────────────── mail

    public function test_an_email_is_recorded_and_confirmed_on_the_frameworks_own_events(): void
    {
        Mail::html('<p>Your order is on its way.</p>', function ($message) {
            $message->to('buyer@example.test')->subject('Order dispatched');
        });

        $delivery = NotificationDelivery::first();

        $this->assertNotNull($delivery);
        $this->assertSame(NotificationDelivery::CHANNEL_MAIL, $delivery->channel);
        $this->assertSame('buyer@example.test', $delivery->recipient);
        $this->assertSame('Order dispatched', $delivery->subject);
        $this->assertSame(NotificationDelivery::STATUS_SENT, $delivery->status);
        $this->assertNotNull($delivery->sent_at);
        // Stored in full, because a resend has to send what was sent rather than re-render a
        // template that may have been edited since.
        $this->assertStringContainsString('on its way', (string) $delivery->body);
    }

    /** Nothing internal may reach the recipient's headers. */
    public function test_the_correlation_header_does_not_travel_with_the_message(): void
    {
        Mail::html('<p>Hello.</p>', fn ($message) => $message->to('buyer@example.test')->subject('Hello'));

        $sent = app('mailer')->getSymfonyTransport()->messages();

        $this->assertNotEmpty($sent);
        $this->assertFalse($sent[0]->getOriginalMessage()->getHeaders()->has('X-Delivery-Log-Id'));
    }

    // ──────────────────────────────────────────────────────────────────────── sms

    public function test_an_sms_the_gateway_refused_is_recorded_as_failed(): void
    {
        // No provider is configured in the test environment, so the dispatcher answers 'not_found' —
        // which is precisely the shape of the real failure this log exists to make visible.
        SMSModule::sendCentralizedSMS('+963900000000', '1234');

        $delivery = NotificationDelivery::first();

        $this->assertNotNull($delivery);
        $this->assertSame(NotificationDelivery::CHANNEL_SMS, $delivery->channel);
        $this->assertSame(NotificationDelivery::STATUS_FAILED, $delivery->status);
        $this->assertSame('not_found', $delivery->error);
    }

    /** A delivery log that holds live one-time secrets is a credential store nobody meant to build. */
    public function test_the_one_time_code_itself_is_never_stored(): void
    {
        SMSModule::sendCentralizedSMS('+963900000000', '918273');

        $delivery = NotificationDelivery::first();

        $this->assertStringNotContainsString('918273', json_encode($delivery->toArray()));
    }

    // ───────────────────────────────────────────────────────────────────── resend

    public function test_a_resend_is_a_new_attempt_pointing_at_the_one_it_repeats(): void
    {
        Mail::html('<p>Your invoice.</p>', fn ($message) => $message->to('buyer@example.test')->subject('Invoice'));
        $original = NotificationDelivery::first();

        $result = app(DeliveryResender::class)->resend($original, actorId: 7);

        $this->assertTrue($result['ok']);
        $this->assertSame(2, NotificationDelivery::count(), 'a resend must not overwrite the original attempt');

        $copy = $result['delivery'];
        $this->assertSame($original->id, $copy->resent_from_id);
        $this->assertSame(7, (int) $copy->resent_by);
        $this->assertSame(2, (int) $copy->attempts);
        $this->assertSame(NotificationDelivery::STATUS_SENT, $copy->fresh()->status);

        // The original stays exactly what it was: a record of what happened at that moment.
        $this->assertSame(NotificationDelivery::STATUS_SENT, $original->fresh()->status);
        $this->assertNull($original->fresh()->resent_from_id);
    }

    /**
     * Re-sending a one-time code minutes later delivers a secret that has already expired, and an
     * operator who watches it "succeed" concludes the customer's problem is solved when it is not.
     */
    public function test_an_sms_is_never_sent_again(): void
    {
        SMSModule::sendCentralizedSMS('+963900000000', '1234');
        $delivery = NotificationDelivery::first();

        $this->assertFalse($delivery->isResendable());

        $result = app(DeliveryResender::class)->resend($delivery);

        $this->assertFalse($result['ok']);
        $this->assertSame(1, NotificationDelivery::count());
    }

    // ─────────────────────────────────────────────────────────────────── the sweep

    /**
     * "Pending" for ever reads as "still going", which is the one thing it is not — the failure
     * hides inside a status that looks harmless.
     */
    public function test_a_send_the_transport_never_confirmed_becomes_a_failure(): void
    {
        $log = app(DeliveryLog::class);
        $stalled = $log->start(NotificationDelivery::CHANNEL_MAIL, 'buyer@example.test', ['event' => 'Invoice']);
        $stalled->forceFill(['created_at' => now()->subHour()])->save();

        $fresh = $log->start(NotificationDelivery::CHANNEL_MAIL, 'other@example.test', ['event' => 'Invoice']);

        $this->assertSame(1, $log->closeStalled(15));
        $this->assertSame(NotificationDelivery::STATUS_FAILED, $stalled->fresh()->status);
        $this->assertSame(NotificationDelivery::STATUS_PENDING, $fresh->fresh()->status);
    }

    public function test_pruning_keeps_the_window_and_drops_what_is_past_it(): void
    {
        $log = app(DeliveryLog::class);

        $old = $log->start(NotificationDelivery::CHANNEL_MAIL, 'old@example.test', []);
        $old->forceFill(['created_at' => now()->subDays(120)])->save();
        $log->start(NotificationDelivery::CHANNEL_MAIL, 'recent@example.test', []);

        $this->assertSame(1, $log->prune(90));
        $this->assertSame(1, NotificationDelivery::count());
    }

    /** A missing table is a missing report, never a customer who did not get their confirmation. */
    public function test_a_missing_table_never_breaks_a_send(): void
    {
        Schema::dropIfExists('notification_deliveries');

        Mail::html('<p>Hello.</p>', fn ($message) => $message->to('buyer@example.test')->subject('Hello'));

        $this->assertNotEmpty(app('mailer')->getSymfonyTransport()->messages());
    }
}
