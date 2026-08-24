<?php

namespace Tests\Feature;

use App\Services\Analytics\AnalyticsEvent;
use App\Services\Analytics\EventRecorder;
use App\Services\Analytics\Support\AttributionEngine;
use App\Services\Analytics\Support\BotDetector;
use App\Services\Analytics\Support\PathNormalizer;
use App\Services\Analytics\Support\PrivacyGate;
use App\Services\Analytics\VisitorContext;
use App\Services\Telemetry\ClientIdentity;
use App\Services\Theme\SectionReach;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Whether the arrangement worked.
 *
 * The builder answers what a merchant arranged. Nothing answered whether anyone got that far, and
 * that is the question an arrangement exists to settle: a rail nobody scrolls to looks identical,
 * on every screen there was, to one at the top that everybody sees — and only one of those is
 * fixed by dragging it up.
 *
 * The whole path is here, because it only means anything end to end: a client says a section came
 * into view, the shop accepts it as the one thing only a client can know, the rollup counts it per
 * section, and the builder puts the number on the row it belongs to.
 */
class SectionReachTest extends TestCase
{
    private const CONNECTION = 'section_reach_test';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        config()->set('database.connections.' . self::CONNECTION, [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => false,
        ]);
        config()->set('analytics.connection', self::CONNECTION);
        config()->set('analytics.enabled', true);
        config()->set('analytics.beacon.enabled', true);

        DB::purge(self::CONNECTION);
        DB::connection(self::CONNECTION)->getPdo();

        foreach (glob(database_path('migrations/*_create_analytics_tables.php')) as $migration) {
            (require $migration)->up();
        }
    }

    /** One impression, recorded as one client would produce it. */
    private function recordImpression(string $clientId): void
    {
        // A fresh context per call, as the container gives one per request: it memoises its answers,
        // and reusing one would make two shoppers into the same shopper.
        // An app request, because that is the path on which an install id identifies a shopper —
        // the web's identity is a cookie the browser holds, which a constructed request has not
        // got, and two calls would then be two anonymous strangers rather than one person twice.
        $request = Request::create('/api/v1/theme/home', 'GET', server: ['REMOTE_ADDR' => '203.0.113.9']);
        $request->headers->set('X-Client-Id', $clientId);

        $recorder = new EventRecorder(
            new VisitorContext(new BotDetector(), new AttributionEngine(), new PathNormalizer(), new ClientIdentity()),
            new PathNormalizer(),
            new PrivacyGate(),
        );

        $recorder->record(new AnalyticsEvent(
            name: AnalyticsEvent::SECTION_VIEWED,
            entityType: 'theme_section',
            entityId: '41',
        ), $request);
        $recorder->flush($request);
    }

    public function test_a_client_may_say_a_section_came_into_view(): void
    {
        // Only the client that drew the page knows this. The server sees the request and has no
        // idea how far down anyone scrolled.
        $this->withHeaders(['X-Platform' => 'android', 'X-App-Version' => '2.1.0'])
            ->postJson('/api/v1/analytics/events', [
                'events' => [[
                    'name' => AnalyticsEvent::SECTION_VIEWED,
                    'entity_type' => 'theme_section',
                    'entity_id' => '41',
                ]],
            ])->assertNoContent();

        $events = DB::connection(self::CONNECTION)->table('analytics_events')->get();

        $this->assertCount(1, $events);
        $this->assertSame(AnalyticsEvent::SECTION_VIEWED, $events[0]->name);
        $this->assertSame('41', $events[0]->entity_id);
    }

    public function test_two_shoppers_seeing_the_same_section_are_two_impressions(): void
    {
        // The trap this guards. An explicitly-keyed event is deduplicated WITHOUT the visitor —
        // deliberately, so one order is one sale across a guest and their signed-in self — which
        // means an impression carrying an explicit key would have recorded the first shopper who
        // ever saw a section and rejected every one after them. A report reading "1", forever, and
        // looking entirely plausible.
        $this->recordImpression('install-aaaaaaaa');
        $this->recordImpression('install-bbbbbbbb');

        $this->assertSame(
            2,
            DB::connection(self::CONNECTION)->table('analytics_events')->count(),
            'one impression per shopper, not one impression ever',
        );
    }

    public function test_one_shopper_passing_twice_in_a_moment_is_one_impression(): void
    {
        // The other half: a section scrolled past, back to and past again seconds later is one
        // sighting. The clients already only report once, and the shop's own window is the net
        // under that.
        Carbon::setTestNow('2026-03-01 12:00:00');
        $this->recordImpression('install-aaaaaaaa');
        $this->recordImpression('install-aaaaaaaa');
        Carbon::setTestNow();

        $this->assertSame(1, DB::connection(self::CONNECTION)->table('analytics_events')->count());
    }

    public function test_the_builder_reads_what_the_rollup_counted(): void
    {
        DB::connection(self::CONNECTION)->table('analytics_daily')->insert([
            [
                'date' => Carbon::now()->toDateString(), 'dimension' => 'theme_section',
                'dimension_key' => '41', 'sessions' => 90, 'visitors' => 80, 'new_visitors' => 0,
                'pageviews' => 0, 'events' => 120, 'bounces' => 0, 'engaged_sessions' => 0,
                'duration_seconds' => 0, 'cart_adds' => 0, 'checkouts' => 0, 'orders' => 0,
                'revenue' => 0,
            ],
            [
                'date' => Carbon::now()->toDateString(), 'dimension' => 'theme_section',
                'dimension_key' => '42', 'sessions' => 5, 'visitors' => 4, 'new_visitors' => 0,
                'pageviews' => 0, 'events' => 5, 'bounces' => 0, 'engaged_sessions' => 0,
                'duration_seconds' => 0, 'cart_adds' => 0, 'checkouts' => 0, 'orders' => 0,
                'revenue' => 0,
            ],
        ]);

        $reach = app(SectionReach::class)->visitors();

        // Visitors rather than events: the same person passing a rail on three visits is one
        // shopper who has seen it, and "how many people got here" is the question being asked.
        $this->assertSame(80, $reach[41] ?? null);
        $this->assertSame(4, $reach[42] ?? null, 'and the section almost nobody reaches says so');
    }

    public function test_a_shop_that_has_measured_nothing_says_nothing(): void
    {
        // Not zero. A section with no measurement yet and a section nobody reaches are different
        // facts, and showing "0" for the first is the more damaging of the two mistakes.
        $this->assertSame([], app(SectionReach::class)->visitors());
        $this->assertFalse(app(SectionReach::class)->measured());
    }

    public function test_the_storefront_marks_its_sections_for_counting(): void
    {
        // The one place the attribute can be, and the shape the beacon watches for.
        $shell = file_get_contents(resource_path('views/theme-sections/home.blade.php'));

        $this->assertStringContainsString('data-analytics-view="section_viewed"', $shell);
        $this->assertStringContainsString('data-analytics-type="theme_section"', $shell);

        $beacon = file_get_contents(public_path('assets/front-end/js/analytics-beacon.js'));

        $this->assertStringContainsString('IntersectionObserver', $beacon);
        $this->assertStringContainsString('section_viewed', $beacon);
        $this->assertStringNotContainsString(
            "dedupeKey: 'view:",
            $beacon,
            'an explicit key is hashed without the visitor and would record one impression ever',
        );
    }
}
