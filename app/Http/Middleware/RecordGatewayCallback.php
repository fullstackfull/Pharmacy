<?php

namespace App\Http\Middleware;

use App\Services\Payments\GatewayJournal;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A receipt for every gateway callback that reaches this application.
 *
 * Nothing recorded a callback at all, and that is the sharper half of the payment blind spot: a
 * callback that never arrived and one that arrived and was rejected are the same absence of a row,
 * which is why the payments page could name the symptom — "money captured with no order" — and never
 * the cause, and why a payment outage showed up only as orders that quietly stopped appearing.
 *
 * Global rather than attached to the payment route group, and self-selecting on the path. The
 * built-in gateway routes live in routes/web/routes.php only while the Gateways addon is
 * unpublished; when it is published the addon registers its own, and a middleware bolted to the
 * built-in group would silently cover nothing on exactly the installations that take the most money.
 *
 * It writes on terminate, after the controller has decided, so one receipt carries both facts: that
 * the callback landed, and what it was taken to mean.
 */
class RecordGatewayCallback
{
    /**
     * The legs that send a shopper OUT to a gateway rather than bringing an answer back.
     *
     * @var array<int, string>
     */
    private const INITIATION_SEGMENTS = ['pay', 'payment', 'make-payment', 'make_payment', 'index', 'token', 'create-order'];

    public function __construct(private readonly GatewayJournal $journal)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        try {
            $gateway = $this->gateway($request);

            if ($gateway === null) {
                return;
            }

            $decision = $this->journal->decision();

            $this->journal->received(
                gateway: $gateway,
                // A callback that reached no decision is not a failure — it is a callback nothing
                // acted on, which is a different thing to look for and a different thing to fix.
                outcome: $decision['outcome'] ?? GatewayJournal::OUTCOME_IGNORED,
                reference: $decision['reference'] ?? null,
                paymentRequestId: $decision['payment_request_id'] ?? null,
                note: $decision === null
                    ? 'no payment decision was reached (http ' . $response->getStatusCode() . ')'
                    : $decision['note'],
            );
        } catch (\Throwable) {
            // Recording that a callback landed must never be the reason one fails.
        }
    }

    /** The gateway a `payment/{gateway}/{leg}` URL belongs to, or null when this is not one. */
    private function gateway(Request $request): ?string
    {
        $segments = $request->segments();

        if (count($segments) < 3 || $segments[0] !== 'payment') {
            return null;
        }

        return in_array(end($segments), self::INITIATION_SEGMENTS, true) ? null : $segments[1];
    }
}
