<?php

namespace Tests\Feature\Analytics;

use App\Services\Analytics\AnalyticsEvent;
use App\Services\Analytics\EventRecorder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * One visitor's flush must never count another visitor's events.
 *
 * flush() runs in terminate(), after the response has been sent, so several requests are inside it
 * at once on any real shop. The recount that runs when deduplication drops a row used to re-read
 * every analytics_events row above an id watermark — which, between two concurrent requests, is
 * somebody else's pageviews, cart adds and orders, added to this visitor's session and this
 * visitor's revenue.
 */
class EventRecorderIsolationTest extends TestCase
{
    private const CONNECTION = 'analytics_isolation';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.connections.' . self::CONNECTION, [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);
        config()->set('analytics.connection', self::CONNECTION);
        config()->set('analytics.enabled', true);

        DB::purge(self::CONNECTION);
        DB::connection(self::CONNECTION)->getPdo();

        foreach (glob(database_path('migrations/*_create_analytics_tables.php')) as $migration) {
            (require $migration)->up();
        }
    }

    public function test_a_stranger_s_order_is_not_counted_into_this_flush_s_delta(): void
    {
        $connection = DB::connection(self::CONNECTION);

        // Our own row, and a stranger's — both above the watermark, which is what a request that
        // finished between our MAX(id) read and our recount looks like. Reproducing the real race
        // from outside is not possible, so the watermark is passed as it would be seen: zero.
        $connection->table('analytics_events')->insert([
            [
                'visitor_id' => 'us', 'session_id' => 'our-session',
                'name' => AnalyticsEvent::PAGE_VIEWED, 'category' => 'engagement',
                'value' => null,
                'path' => '/product/x', 'channel' => 'web',
                'dedupe_key' => 'our-key', 'occurred_at' => now(),
            ],
            [
                'visitor_id' => 'someone-else', 'session_id' => 'their-session',
                'name' => AnalyticsEvent::ORDER_PLACED, 'category' => 'commerce', 'value' => 5000.00,
                'path' => '/checkout', 'channel' => 'web',
                'dedupe_key' => 'their-key', 'occurred_at' => now(),
            ],
        ]);

        $delta = $this->deltaOf(
            watermark: 0,
            queued: [['dedupe_key' => 'our-key']],
            sessionId: 'our-session',
        );

        $this->assertSame(1, (int) ($delta['events'] ?? 0), 'only our own row is ours to count');
        $this->assertSame(1, (int) ($delta['pageviews'] ?? 0));
        $this->assertArrayNotHasKey('orders', $delta, "the stranger's order must not be counted here");
        $this->assertSame(0.0, (float) ($delta['revenue'] ?? 0), "the stranger's revenue must not be counted here");
    }

    public function test_a_deduplicated_event_of_our_own_is_still_counted_once(): void
    {
        $connection = DB::connection(self::CONNECTION);
        $connection->table('analytics_events')->insert([
            'visitor_id' => 'us', 'session_id' => 'our-session',
            'name' => AnalyticsEvent::CART_ADDED, 'category' => 'commerce',
            'path' => '/cart', 'channel' => 'web',
            'dedupe_key' => 'our-cart-key', 'occurred_at' => now(),
        ]);

        $delta = $this->deltaOf(
            watermark: 0,
            queued: [['dedupe_key' => 'our-cart-key']],
            sessionId: 'our-session',
        );

        $this->assertSame(1, (int) ($delta['cart_adds'] ?? 0));
    }

    public function test_the_whole_pipeline_still_deduplicates_and_counts_once(): void
    {
        $recorder = app(EventRecorder::class);
        $request = Request::create('/product/y', 'GET');

        $recorder->record(new AnalyticsEvent(name: AnalyticsEvent::PAGE_VIEWED, path: '/product/y'), $request);
        $recorder->record(new AnalyticsEvent(name: AnalyticsEvent::PAGE_VIEWED, path: '/product/y'), $request);
        $recorder->flush($request);

        $connection = DB::connection(self::CONNECTION);

        $this->assertSame(1, (int) $connection->table('analytics_events')->count());
        $this->assertSame(1, (int) $connection->table('analytics_sessions')->value('pageviews'));
    }

    /**
     * @param  array<int, array<string, mixed>>  $queued
     * @return array<string, float|int>
     */
    private function deltaOf(int $watermark, array $queued, ?string $sessionId): array
    {
        $method = new \ReflectionMethod(EventRecorder::class, 'deltaOfWrittenRows');
        $method->setAccessible(true);

        return $method->invoke(app(EventRecorder::class), $watermark, $queued, $sessionId);
    }
}
