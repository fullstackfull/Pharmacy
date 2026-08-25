<?php

namespace App\Services\Notifications;

use App\Models\NotificationDelivery;
use App\Traits\PushNotificationTrait;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sending one recorded message again.
 *
 * The value of a delivery log is that the person who can see the failure is also the person who can
 * fix it. Before this, a bounced order confirmation was repaired by asking the customer to place the
 * order again, or by a developer running tinker.
 *
 * A resend is a new row that points at the one it repeats, never an edit of the original. The
 * original is a record of what happened at that moment and stays true whatever is done afterwards.
 *
 * It sends the message that was recorded rather than re-rendering the template. A template edited
 * since would otherwise rewrite the past: the operator would read "resent" and the customer would
 * receive something different from what the log shows.
 */
class DeliveryResender
{
    use PushNotificationTrait;

    public function __construct(private readonly DeliveryLog $log)
    {
    }

    /** @return array{ok: bool, reason?: string, delivery?: NotificationDelivery} */
    public function resend(NotificationDelivery $delivery, ?int $actorId = null): array
    {
        if (!$delivery->isResendable()) {
            return ['ok' => false, 'reason' => $this->refusal($delivery)];
        }

        return $delivery->channel === NotificationDelivery::CHANNEL_MAIL
            ? $this->resendMail($delivery, $actorId)
            : $this->resendPush($delivery, $actorId);
    }

    private function resendMail(NotificationDelivery $delivery, ?int $actorId): array
    {
        $this->log->attributeNextTo($delivery->id, $actorId, $delivery->attempts + 1);

        try {
            Mail::html((string) $delivery->body, function ($message) use ($delivery) {
                $message->to($delivery->recipient)->subject((string) $delivery->subject);
            });
        } catch (Throwable $exception) {
            $this->log->fail($this->log->lastStarted, $exception->getMessage());
            $this->log->clearAttribution();

            return ['ok' => false, 'reason' => 'the_message_could_not_be_sent', 'delivery' => $this->log->lastStarted];
        } finally {
            $this->log->clearAttribution();
        }

        return ['ok' => true, 'delivery' => $this->log->lastStarted];
    }

    /**
     * A push resend replays the stored FCM payload, which already carries the device token.
     *
     * As with mail, the row is written by the seam the send passes through rather than here — one
     * attempt, one record, whether it was a customer's order or an operator pressing the button.
     */
    private function resendPush(NotificationDelivery $delivery, ?int $actorId): array
    {
        $payload = $delivery->payload;

        if (!is_array($payload) || !isset($payload['message'])) {
            return ['ok' => false, 'reason' => 'this_push_has_no_stored_payload_to_send_again'];
        }

        $this->log->attributeNextTo($delivery->id, $actorId, $delivery->attempts + 1);

        try {
            $response = $this->sendNotificationToHttp($payload);
        } finally {
            $this->log->clearAttribution();
        }

        $copy = $this->log->lastStarted;

        return $response === false
            ? ['ok' => false, 'reason' => 'the_message_could_not_be_sent', 'delivery' => $copy]
            : ['ok' => true, 'delivery' => $copy];
    }

    private function refusal(NotificationDelivery $delivery): string
    {
        return match (true) {
            $delivery->channel === NotificationDelivery::CHANNEL_SMS => 'an_sms_carries_a_one_time_code_that_has_already_expired',
            $delivery->recipient === null => 'this_record_has_no_recipient_to_send_to',
            default => 'this_message_was_not_stored_in_full_so_it_cannot_be_sent_again',
        };
    }
}
