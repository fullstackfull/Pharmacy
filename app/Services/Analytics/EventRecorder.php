<?php

namespace App\Services\Analytics;

use App\Services\Analytics\Support\PathNormalizer;
use App\Services\Monitoring\Support\Redactor;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Writes events, and is not allowed to matter.
 *
 * The rule that shapes every line here: if analytics fails, the shop does not. Events are buffered
 * in memory during the request and written once, after the response has been sent, in a single
 * bulk insert. Nothing on the checkout path waits for this, nothing on it can throw through to a
 * customer, and an unreachable analytics database costs the merchant a row in a report — never an
 * order.
 *
 * Two other properties are load-bearing:
 *
 *  - IDEMPOTENCE. Events carry a dedupe key and the table has a unique index on it, so a
 *    double-submitted form, a retried mobile upload or a page restored from the back/forward cache
 *    produces one row. Revenue counted twice is worse than revenue not counted at all, because
 *    nobody goes looking for a number that is too high.
 *
 *  - REDACTION. Properties pass through the same redactor the monitoring system uses, so a
 *    password, an OTP, a token or a card number cannot reach this table by way of a well-meaning
 *    instrumentation call somewhere else in the codebase.
 */
class EventRecorder
{
    /** @var array<int, array<string, mixed>> */
    private array $buffer = [];

    /** @var array<string, float|int> running totals for the session row, applied once on flush */
    private array $sessionDelta = [];

    private bool $dropped = false;

    public function __construct(
        private readonly VisitorContext $context,
        private readonly PathNormalizer $paths,
    ) {
    }

    /**
     * Queue an event. Cheap: no query, no validation round trip, no exception.
     */
    public function record(AnalyticsEvent $event, ?Request $request = null): void
    {
        try {
            if (!config('analytics.enabled', true)) {
                return;
            }

            $request ??= request();
            $this->context->resolve($request);

            $visitorId = $this->context->visitorId($request);

            if ($visitorId === null) {
                return;
            }

            if (count($this->buffer) >= (int) config('analytics.buffer_limit', 40)) {
                // A single request producing forty events is a loop, not a customer. The overflow
                // is dropped and RECORDED as dropped, so the data-quality screen can show it
                // rather than the numbers just being quietly short.
                $this->dropped = true;

                return;
            }

            $path = $event->path ?? $this->paths->fromRequest($request);

            $this->buffer[] = [
                'name' => Str::limit($event->name, 48, ''),
                'category' => $event->category(),
                'visitor_id' => $visitorId,
                'session_id' => null,          // filled on flush: one session lookup per request
                'channel' => $this->channel($request),
                'user_type' => $this->context->userType(),
                'user_id' => $this->context->userId(),
                'entity_type' => $event->entityType !== null ? Str::limit($event->entityType, 24, '') : null,
                'entity_id' => $event->entityId !== null ? Str::limit((string) $event->entityId, 64, '') : null,
                'vendor_id' => $event->vendorId,
                'value' => $event->value,
                'currency' => $event->currency !== null ? Str::limit($event->currency, 8, '') : null,
                'quantity' => $event->quantity,
                'path' => $path,
                'properties' => $this->properties($event->properties),
                'is_bot' => $this->context->isBot(),
                'is_internal' => $this->context->isInternal(),
                'dedupe_key' => $this->dedupeKey($event, $visitorId, $path),
                'occurred_at' => Carbon::now(),
            ];

            $this->accumulate($event);
        } catch (\Throwable) {
            // Recording an event must never be the reason a page fails.
        }
    }

    /**
     * Write everything queued. Called from terminable middleware, after the response has gone out.
     */
    public function flush(?Request $request = null): int
    {
        if ($this->buffer === []) {
            return 0;
        }

        $queued = $this->buffer;
        $delta = $this->sessionDelta;

        // Cleared first: a failure below must not leave events queued to be written twice by a
        // later flush in the same process (queue workers and Octane both reuse the container).
        $this->buffer = [];
        $this->sessionDelta = [];

        try {
            $sessionId = $this->context->sessionId($request ?? request());

            foreach ($queued as $index => $row) {
                $queued[$index]['session_id'] = $sessionId;
                $queued[$index]['properties'] = $row['properties'] === null ? null : json_encode($row['properties']);
            }

            // insertOrIgnore, not insert: the unique index on dedupe_key is the deduplication, and
            // a duplicate must be a no-op rather than an exception on the response path.
            $written = $this->connection()->table('analytics_events')->insertOrIgnore($queued);

            $this->applySessionDelta($sessionId, $delta);
            $this->applyVisitorDelta($queued[0]['visitor_id'] ?? null, $delta, count($queued));
            $this->health('events_written', $written);

            if ($this->dropped) {
                $this->health('events_dropped_buffer_full', 1);
                $this->dropped = false;
            }

            return $written;
        } catch (\Throwable $exception) {
            $this->health('write_failed', 1, class_basename($exception) . ': ' . $exception->getMessage());

            return 0;
        }
    }

    /** How many events are waiting — used by the tests and the debugger, not by the request path. */
    public function pending(): int
    {
        return count($this->buffer);
    }

    // -------------------------------------------------------------------------------------------

    /**
     * The identity of an act, so the same act recorded twice is stored once.
     *
     * An explicit key from the caller wins — an order is the same order however many times its
     * confirmation page is reloaded. Without one, the key covers the visitor, the event and what
     * it was about within the dedupe window, which collapses a double-clicked button but leaves
     * two genuine views ten minutes apart as two views.
     */
    private function dedupeKey(AnalyticsEvent $event, string $visitorId, ?string $path): ?string
    {
        if ($event->dedupeKey !== null) {
            return substr(hash('sha256', $event->name . '|' . $event->dedupeKey), 0, 60);
        }

        // Short, and keyed on the page as well as the entity. The duplicates worth collapsing are
        // accidents seconds apart — a double-clicked button, a page restored from the back/forward
        // cache, a beacon retried on a flaky connection. A window of minutes would instead collapse
        // four genuine pageviews in one visit into one, which is a measurement error, not a
        // deduplication. Anything that must be unique for longer than that says so explicitly:
        // an order carries order:{id} and is one sale however often its page is reloaded.
        $window = max(1, (int) config('analytics.dedupe_window_seconds', 5));
        $bucket = intdiv(time(), $window);

        return substr(hash('sha256', implode('|', [
            $visitorId,
            $event->name,
            (string) $event->entityType,
            (string) $event->entityId,
            (string) $path,
            (string) $bucket,
        ])), 0, 60);
    }

    /**
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>|null
     */
    private function properties(array $properties): ?array
    {
        if ($properties === []) {
            return null;
        }

        // The monitoring redactor already knows every secret name this project uses; reusing it
        // means analytics cannot drift into leaking something monitoring has learned to hide.
        $clean = Redactor::make()->array($properties);

        return $clean === [] ? null : array_slice($clean, 0, 25, true);
    }

    private function accumulate(AnalyticsEvent $event): void
    {
        $this->sessionDelta['events'] = ($this->sessionDelta['events'] ?? 0) + 1;

        match ($event->name) {
            AnalyticsEvent::PAGE_VIEWED => $this->sessionDelta['pageviews'] = ($this->sessionDelta['pageviews'] ?? 0) + 1,
            AnalyticsEvent::CART_ADDED => $this->sessionDelta['cart_adds'] = ($this->sessionDelta['cart_adds'] ?? 0) + 1,
            AnalyticsEvent::CHECKOUT_STARTED => $this->sessionDelta['checkouts'] = ($this->sessionDelta['checkouts'] ?? 0) + 1,
            AnalyticsEvent::ORDER_PLACED => $this->countOrder($event),
            default => null,
        };
    }

    private function countOrder(AnalyticsEvent $event): void
    {
        $this->sessionDelta['orders'] = ($this->sessionDelta['orders'] ?? 0) + 1;
        $this->sessionDelta['revenue'] = ($this->sessionDelta['revenue'] ?? 0) + (float) ($event->value ?? 0);
    }

    /**
     * @param  array<string, float|int>  $delta
     */
    private function applySessionDelta(?int $sessionId, array $delta): void
    {
        if ($sessionId === null || $delta === []) {
            return;
        }

        $update = [];
        foreach (['pageviews', 'events', 'cart_adds', 'checkouts', 'orders'] as $column) {
            if (!empty($delta[$column])) {
                $update[$column] = DB::raw($column . ' + ' . (int) $delta[$column]);
            }
        }

        if (!empty($delta['revenue'])) {
            $update['revenue'] = DB::raw('revenue + ' . (float) $delta['revenue']);
        }

        // A second pageview is the definition of not bouncing, applied in the same statement so a
        // report can never see the pageview without the bounce flag that follows from it.
        //
        // Ordered BEFORE the increment on purpose: MySQL evaluates the assignments in an UPDATE
        // left to right, and a later expression reads the value an earlier one has already
        // written. Computing this after the increment double-counted the new pageview and marked
        // every single-page visit as engaged.
        if (!empty($delta['pageviews'])) {
            $total = 'pageviews + ' . (int) $delta['pageviews'];
            $update = [
                'is_bounce' => DB::raw("CASE WHEN {$total} >= 2 THEN 0 ELSE is_bounce END"),
                'is_engaged' => DB::raw("CASE WHEN {$total} >= 2 THEN 1 ELSE is_engaged END"),
            ] + $update;
        }

        if ($update !== []) {
            $this->connection()->table('analytics_sessions')->where('id', $sessionId)->update($update);
        }
    }

    /**
     * @param  array<string, float|int>  $delta
     */
    private function applyVisitorDelta(?string $visitorId, array $delta, int $events): void
    {
        if ($visitorId === null) {
            return;
        }

        $update = [
            'events' => DB::raw('events + ' . $events),
            'last_seen_at' => Carbon::now(),
        ];

        if (!empty($delta['orders'])) {
            $update['orders'] = DB::raw('orders + ' . (int) $delta['orders']);
            $update['revenue'] = DB::raw('revenue + ' . (float) ($delta['revenue'] ?? 0));
        }

        $this->connection()->table('analytics_visitors')->where('visitor_id', $visitorId)->update($update);
    }

    /**
     * Analytics watching itself.
     *
     * A collector that stops has to be able to say so: without this, a broken pipeline draws a
     * flat line that everybody reads as a quiet week.
     */
    private function health(string $signal, int $count, ?string $detail = null): void
    {
        try {
            $now = Carbon::now();

            // Update first, insert only if the signal is new: updateOrInsert would carry the raw
            // `count + n` expression into the INSERT, where there is no existing row to add to.
            $updated = $this->connection()->table('analytics_health')
                ->where('signal', $signal)
                ->update([
                    'count' => DB::raw('`count` + ' . max(0, $count)),
                    'detail' => $detail !== null ? Str::limit($detail, 500, '') : null,
                    'occurred_at' => $now,
                    'updated_at' => $now,
                ]);

            if ($updated === 0) {
                $this->connection()->table('analytics_health')->insertOrIgnore([
                    'signal' => $signal,
                    'count' => max(0, $count),
                    'detail' => $detail !== null ? Str::limit($detail, 500, '') : null,
                    'occurred_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        } catch (\Throwable) {
            // The health table failing is itself only worth knowing if it can be recorded.
        }
    }

    private function channel(Request $request): string
    {
        return match (true) {
            $request->headers->has('X-App-Version') => 'app',
            $request->is('api/*') => 'api',
            default => 'web',
        };
    }

    private function connection(): \Illuminate\Database\Connection
    {
        return DB::connection(config('analytics.connection'));
    }
}
