<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attempt to deliver one transactional message.
 *
 * A row is written when the send starts and updated when the channel confirms it, so a message that
 * never came back is visible as unconfirmed rather than absent. That distinction is the whole point:
 * "no row" and "a row that never turned green" look identical in a log that only records successes,
 * and the second one is the outage.
 */
class NotificationDelivery extends Model
{
    public const CHANNEL_MAIL = 'mail';
    public const CHANNEL_SMS = 'sms';
    public const CHANNEL_PUSH = 'push';

    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';

    /** Channels a message can be sent again on. */
    public const RESENDABLE = [self::CHANNEL_MAIL, self::CHANNEL_PUSH];

    protected $fillable = [
        'channel', 'event', 'recipient', 'user_type', 'user_id', 'subject', 'body', 'payload',
        'status', 'error', 'attempts', 'resent_from_id', 'resent_by', 'sent_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'sent_at' => 'datetime',
    ];

    public function original(): BelongsTo
    {
        return $this->belongsTo(self::class, 'resent_from_id');
    }

    /**
     * Whether an operator may send this one again.
     *
     * A one-time code is deliberately excluded even though the row holds everything needed to send
     * it. Re-sending an OTP minutes later delivers a secret that has already expired, and an
     * operator who watches it "succeed" concludes the customer's problem is solved when it is not.
     */
    public function isResendable(): bool
    {
        return in_array($this->channel, self::RESENDABLE, true)
            && ($this->channel !== self::CHANNEL_MAIL || $this->body !== null)
            && $this->recipient !== null;
    }
}
