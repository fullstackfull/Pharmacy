<?php

namespace App\Http\Controllers\Admin\Notification;

use App\Http\Controllers\BaseController;
use App\Models\NotificationDelivery;
use App\Services\AuditLogger;
use App\Services\Notifications\DeliveryResender;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Did the message arrive, and can we send it again.
 *
 * Two questions the platform could not answer at all. Twenty-three listeners send the whole of its
 * transactional traffic and none of them recorded an outcome, so a shop whose SMS credentials had
 * expired sent no OTP, no customer could sign in, and every screen in the monitoring console stayed
 * green. The failing part of this system was the part nobody could see.
 *
 * Read-mostly: one action, and it is a send, so it is a POST and it is audited.
 */
class DeliveryLogController extends BaseController
{
    private const PER_PAGE = 30;

    public function __construct(
        private readonly DeliveryResender $resender,
        private readonly AuditLogger $audit,
    ) {
    }

    public function index(Request|null $request = null, ?string $type = null): View
    {
        $request ??= request();

        $deliveries = NotificationDelivery::query()
            ->when($request->filled('channel'), fn ($query) => $query->where('channel', $request->get('channel')))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->get('status')))
            ->when($request->filled('search'), fn ($query) => $query->where('recipient', 'like', '%' . $request->get('search') . '%'))
            ->latest('id')
            ->paginate(self::PER_PAGE)
            ->appends($request->query());

        return view('admin-views.notification.delivery-log', [
            'deliveries' => $deliveries,
            'counts' => $this->counts(),
            'channel' => $request->get('channel'),
            'status' => $request->get('status'),
            'search' => $request->get('search'),
        ]);
    }

    public function resend(int $id): RedirectResponse
    {
        $delivery = NotificationDelivery::findOrFail($id);
        $result = $this->resender->resend($delivery, auth('admin')->id());

        if (!$result['ok']) {
            ToastMagic::error(translate($result['reason']));

            return back();
        }

        $this->audit->record(
            action: 'notification.resent',
            subject: $delivery,
            context: ['channel' => $delivery->channel, 'event' => $delivery->event, 'recipient' => $delivery->recipient],
        );

        ToastMagic::success(translate('the_message_was_sent_again'));

        return back();
    }

    /**
     * The counts the page leads with.
     *
     * Failures for the last day rather than for all time: an operator opening this page is asking
     * "is something broken now", and a lifetime total of failures answers a different question in a
     * way that looks like an alarm.
     */
    private function counts(): array
    {
        $since = now()->subDay();

        return [
            'sent' => NotificationDelivery::where('status', NotificationDelivery::STATUS_SENT)->where('created_at', '>=', $since)->count(),
            'failed' => NotificationDelivery::where('status', NotificationDelivery::STATUS_FAILED)->where('created_at', '>=', $since)->count(),
            'pending' => NotificationDelivery::where('status', NotificationDelivery::STATUS_PENDING)->where('created_at', '>=', $since)->count(),
        ];
    }
}
