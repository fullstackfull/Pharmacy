<?php

namespace Tests\Feature\Analytics;

use App\Services\Analytics\AnalyticsEvent;
use App\Services\Analytics\EventRecorder;
use App\Services\Analytics\Support\AttributionEngine;
use App\Services\Analytics\Support\BotDetector;
use App\Services\Analytics\Support\PathNormalizer;
use App\Services\Analytics\Support\PrivacyGate;
use App\Services\Analytics\VisitorContext;
use App\Services\Telemetry\ClientIdentity;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * What deduplication actually collapses — which is not "duplicates".
 *
 * The class docblock used to say a double-submitted form, a retried upload or a page restored from
 * the back/forward cache "produces one row", full stop. Two of those are only true some of the
 * time: without an explicit key the identity carries a bucket number — the clock divided by the
 * window — so two identical events collapse when they land in the same bucket and both survive
 * when they straddle its edge, however close together they were. That is a deliberate trade (a
 * sliding window would mean a read before every insert on the request path), and it is pinned here
 * so the sentence describing it cannot drift back into a promise.
 */
class DedupeScopeTest extends TestCase
{
    private const CONNECTION = 'analytics_dedupe';

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
        config()->set('analytics.dedupe_window_seconds', 60);

        DB::purge(self::CONNECTION);
        DB::connection(self::CONNECTION)->getPdo();

        foreach (glob(database_path('migrations/*_create_analytics_tables.php')) as $migration) {
            (require $migration)->up();
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_the_same_act_twice_inside_one_bucket_is_one_row(): void
    {
        Carbon::setTestNow('2026-03-01 12:00:10');
        $this->recordView();

        Carbon::setTestNow('2026-03-01 12:00:50');
        $this->recordView();

        $this->assertSame(1, $this->rows());
    }

    public function test_the_same_act_two_seconds_apart_across_a_bucket_edge_is_two_rows(): void
    {
        // Not a bug being enshrined — a limit being stated. Anything that must be unique for
        // longer than a bucket carries its own key, which the next test covers.
        Carbon::setTestNow('2026-03-01 12:00:59');
        $this->recordView();

        Carbon::setTestNow('2026-03-01 12:01:01');
        $this->recordView();

        $this->assertSame(2, $this->rows());
    }

    public function test_an_explicit_key_holds_for_as_long_as_the_rows_are_kept(): void
    {
        Carbon::setTestNow('2026-03-01 12:00:00');
        $this->recordOrder();

        Carbon::setTestNow('2026-03-08 09:30:00');
        $this->recordOrder();

        $this->assertSame(1, $this->rows(), 'an order is one sale however often its page is reloaded');
    }

    public function test_an_explicit_key_is_not_scoped_to_the_visitor(): void
    {
        // Deliberate, and the reason the docblock now says so: a guest and their signed-in self are
        // two visitor ids for one person, and one order must not become two sales between them.
        // The cost is that an explicit key has to be globally unique for its event.
        Carbon::setTestNow('2026-03-01 12:00:00');
        $this->recordOrder(clientId: 'install-aaaaaaaa');
        $this->recordOrder(clientId: 'install-bbbbbbbb');

        $this->assertSame(1, $this->rows());
    }

    // ---------------------------------------------------------------------------------------

    private function recordView(string $clientId = 'install-aaaaaaaa'): void
    {
        $this->recorderFor($clientId, new AnalyticsEvent(
            name: AnalyticsEvent::PRODUCT_VIEWED,
            entityType: 'product',
            entityId: '42',
        ));
    }

    private function recordOrder(string $clientId = 'install-aaaaaaaa'): void
    {
        $this->recorderFor($clientId, new AnalyticsEvent(
            name: AnalyticsEvent::ORDER_PLACED,
            entityType: 'order',
            entityId: '9001',
            dedupeKey: 'order:9001',
        ));
    }

    private function recorderFor(string $clientId, AnalyticsEvent $event): void
    {
        // A fresh context per request, as the container gives one: it memoises its answers.
        $request = Request::create('/api/v1/products/latest', 'GET', server: ['REMOTE_ADDR' => '203.0.113.9']);
        $request->headers->set('X-Client-Id', $clientId);

        $recorder = new EventRecorder(
            new VisitorContext(new BotDetector(), new AttributionEngine(), new PathNormalizer(), new ClientIdentity()),
            new PathNormalizer(),
            new PrivacyGate(),
        );

        $recorder->record($event, $request);
        $recorder->flush($request);
    }

    private function rows(): int
    {
        return DB::connection(self::CONNECTION)->table('analytics_events')->count();
    }
}
