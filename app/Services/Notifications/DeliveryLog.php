<?php

namespace App\Services\Notifications;

use App\Models\NotificationDelivery;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * The one place a transactional message is written down.
 *
 * Three channels, three seams, one record shape. Mail is caught on Laravel's own MessageSending and
 * MessageSent events, so every one of the twenty-three listeners is covered without editing any of
 * them — and so is the twenty-fourth, whenever somebody adds it. SMS is caught in the single
 * dispatcher every caller in the codebase goes through. Push is caught at the one HTTP call all
 * device and topic sends funnel into.
 *
 * Nothing here is allowed to break a send. A notification that is not logged is a gap in a report;
 * a logger that throws is a customer who never got their order confirmation.
 */
class DeliveryLog
{
    /** Enough of a rendered email to send again and to read on the page; past this it is images. */
    private const MAX_BODY_BYTES = 262144;

    /**
     * The row the seams most recently opened.
     *
     * A resend cannot pre-create its own row: the send it triggers passes through the same seam and
     * would write a second one, so the log would show two attempts for one message. Instead the
     * resender attributes the next row and reads it back from here.
     */
    public ?NotificationDelivery $lastStarted = null;

    /** @var array<string, mixed>|null */
    private ?array $attribution = null;

    public function start(string $channel, ?string $recipient, array $attributes = []): ?NotificationDelivery
    {
        $delivery = $this->safely(fn () => NotificationDelivery::create(array_merge([
            'channel' => $channel,
            'recipient' => $recipient === null ? null : mb_substr($recipient, 0, 191),
            'status' => NotificationDelivery::STATUS_PENDING,
            'attempts' => 1,
        ], $this->trim($attributes), $this->attribution ?? [])));

        $this->lastStarted = $delivery;

        return $delivery;
    }

    /**
     * Mark whatever the next send writes as a repeat of an earlier one.
     *
     * Held for exactly one send and cleared by the caller, so an unrelated notification that happens
     * to go out in the same request is never mislabelled as somebody's resend.
     */
    public function attributeNextTo(int $originalId, ?int $actorId, int $attempts): void
    {
        $this->attribution = ['resent_from_id' => $originalId, 'resent_by' => $actorId, 'attempts' => $attempts];
        $this->lastStarted = null;
    }

    public function clearAttribution(): void
    {
        $this->attribution = null;
    }

    public function succeed(?NotificationDelivery $delivery, array $attributes = []): void
    {
        if ($delivery === null) {
            return;
        }

        $this->safely(fn () => $delivery->forceFill(array_merge($this->trim($attributes), [
            'status' => NotificationDelivery::STATUS_SENT,
            'error' => null,
            'sent_at' => Carbon::now(),
        ]))->save());
    }

    public function fail(?NotificationDelivery $delivery, string $reason, array $attributes = []): void
    {
        if ($delivery === null) {
            return;
        }

        $this->safely(fn () => $delivery->forceFill(array_merge($this->trim($attributes), [
            'status' => NotificationDelivery::STATUS_FAILED,
            'error' => mb_substr($reason, 0, 2000),
        ]))->save());
    }

    /** One call, for a channel that answers immediately and has nothing to correlate. */
    public function record(string $channel, ?string $recipient, bool $succeeded, array $attributes = []): ?NotificationDelivery
    {
        $delivery = $this->start($channel, $recipient, $attributes);

        if ($succeeded) {
            $this->succeed($delivery);
        } else {
            $this->fail($delivery, (string) ($attributes['error'] ?? 'the channel did not confirm delivery'));
        }

        return $delivery;
    }

    /**
     * Close out sends that never came back.
     *
     * A mail whose transport threw, or whose queue worker died mid-job, leaves a row that says
     * "pending" for ever. Left alone it reads as "still going", which is the one thing it is not,
     * and it hides the failure inside a status that looks harmless.
     */
    public function closeStalled(int $olderThanMinutes = 15): int
    {
        if (!Schema::hasTable('notification_deliveries')) {
            return 0;
        }

        return NotificationDelivery::where('status', NotificationDelivery::STATUS_PENDING)
            ->where('created_at', '<', Carbon::now()->subMinutes($olderThanMinutes))
            ->update([
                'status' => NotificationDelivery::STATUS_FAILED,
                'error' => 'the transport never confirmed this message',
                'updated_at' => Carbon::now(),
            ]);
    }

    /** Old rows are a support aid with a shelf life, not a permanent archive of customer messages. */
    public function prune(int $retentionDays): int
    {
        if ($retentionDays <= 0 || !Schema::hasTable('notification_deliveries')) {
            return 0;
        }

        return NotificationDelivery::where('created_at', '<', Carbon::now()->subDays($retentionDays))->delete();
    }

    /** @param array<string, mixed> $attributes */
    private function trim(array $attributes): array
    {
        foreach (['event' => 96, 'subject' => 191, 'user_type' => 16] as $key => $length) {
            if (isset($attributes[$key]) && is_string($attributes[$key])) {
                $attributes[$key] = mb_substr($attributes[$key], 0, $length);
            }
        }

        if (isset($attributes['body']) && is_string($attributes['body'])) {
            $attributes['body'] = mb_strcut($attributes['body'], 0, self::MAX_BODY_BYTES);
        }

        unset($attributes['error']);

        return $attributes;
    }

    private function safely(callable $write): mixed
    {
        try {
            return Schema::hasTable('notification_deliveries') ? $write() : null;
        } catch (Throwable) {
            return null;
        }
    }
}
