<?php

namespace App\Listeners\Notifications;

use App\Models\NotificationDelivery;
use App\Services\Notifications\DeliveryLog;
use Illuminate\Events\Dispatcher;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Symfony\Component\Mime\Email;

/**
 * Every email this platform sends, recorded where it is already passing.
 *
 * Laravel raises MessageSending before the transport runs and MessageSent after it accepts the
 * message, which makes this the only seam that covers all twenty-three transactional listeners, the
 * password resets, the invoices and whatever is added next — without one edit in any of them.
 *
 * The two events are correlated by a header this listener adds on the way out. Message-ID is minted
 * by the transport and is therefore absent on the first event, and matching on recipient and subject
 * would merge two identical emails sent to the same customer a second apart, which is exactly what a
 * retry looks like.
 */
class RecordMailDelivery
{
    /** Removed before the message goes out, so nothing internal reaches the recipient's headers. */
    private const CORRELATION_HEADER = 'X-Delivery-Log-Id';

    public function __construct(private readonly DeliveryLog $log)
    {
    }

    public function subscribe(Dispatcher $events): array
    {
        return [
            MessageSending::class => 'sending',
            MessageSent::class => 'sent',
        ];
    }

    public function sending(MessageSending $event): void
    {
        $message = $event->message;

        $delivery = $this->log->start(NotificationDelivery::CHANNEL_MAIL, $this->firstRecipient($message), [
            'event' => $this->event($event),
            'subject' => (string) $message->getSubject(),
            // The rendered message, not the template: a resend has to send what was sent, and a
            // template edited since would otherwise quietly rewrite history.
            'body' => $this->body($message),
        ]);

        if ($delivery !== null) {
            $message->getHeaders()->addTextHeader(self::CORRELATION_HEADER, (string) $delivery->id);
        }
    }

    public function sent(MessageSent $event): void
    {
        $headers = $event->message->getHeaders();
        $header = $headers->get(self::CORRELATION_HEADER);

        if ($header === null) {
            return;
        }

        $id = (int) $header->getBodyAsString();
        $headers->remove(self::CORRELATION_HEADER);

        $this->log->succeed(NotificationDelivery::find($id));
    }

    private function firstRecipient(Email $message): ?string
    {
        $to = $message->getTo();

        return $to === [] ? null : $to[0]->getAddress();
    }

    /**
     * What this email was.
     *
     * Laravel puts the Mailable's class name in the event data when one was used; a raw Mail::html()
     * has no class, and 'raw' is a truer label than a guess made from the subject line.
     */
    private function event(MessageSending $event): string
    {
        $mailable = $event->data['__laravel_mailable'] ?? null;

        return is_string($mailable) ? class_basename($mailable) : 'raw';
    }

    private function body(Email $message): ?string
    {
        $html = $message->getHtmlBody();

        if (is_string($html) && $html !== '') {
            return $html;
        }

        $text = $message->getTextBody();

        return is_string($text) && $text !== '' ? $text : null;
    }
}
