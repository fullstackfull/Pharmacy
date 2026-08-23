<?php

namespace Tests\Feature;

use App\Models\Banner;
use App\Services\Analytics\AnalyticsEvent;
use App\Services\Theme\SectionDataResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesCatalogueSchema;
use Tests\TestCase;

/**
 * Whether anyone took the banner.
 *
 * The merchant picks the picture, the slot and the link, and until now nothing counted the result:
 * on the web the server sees the page a banner led to and nothing saying a banner led there, and in
 * the app there is no navigation at all. So the two clients report it — the browser through the
 * storefront beacon, the app through its own endpoint — and both go through the same allow-list.
 *
 * The rules that matter here are the ones that keep a public write safe: only allow-listed names,
 * no money, ids coerced to digits, and 204 to everything so a prober learns nothing.
 */
class BannerClickAnalyticsTest extends TestCase
{
    use CreatesCatalogueSchema;

    private const CONNECTION = 'banner_analytics_test';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->createCatalogueSchema();

        config()->set('database.connections.' . self::CONNECTION, [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
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

    /** @return array<int, object> */
    private function recordedEvents(): array
    {
        return DB::connection(self::CONNECTION)->table('analytics_events')->get()->all();
    }

    public function test_the_app_can_report_a_banner_tap(): void
    {
        $response = $this->withHeaders(['X-Platform' => 'android', 'X-App-Version' => '2.0.1'])
            ->postJson('/api/v1/analytics/events', [
                'events' => [[
                    'name' => AnalyticsEvent::BANNER_CLICKED,
                    'entity_type' => 'banner',
                    'entity_id' => '12',
                ]],
            ]);

        $response->assertNoContent();

        $events = $this->recordedEvents();
        $this->assertCount(1, $events);
        $this->assertSame(AnalyticsEvent::BANNER_CLICKED, $events[0]->name);
        $this->assertSame('banner', $events[0]->entity_type);
        $this->assertSame('12', $events[0]->entity_id);
    }

    public function test_the_app_endpoint_refuses_everything_it_was_not_asked_to_carry(): void
    {
        $response = $this->postJson('/api/v1/analytics/events', [
            'events' => [
                // Money is never taken from a client.
                ['name' => AnalyticsEvent::ORDER_PLACED, 'entity_type' => 'order', 'entity_id' => '1'],
                // An unknown name is not a new event, it is somebody typing.
                ['name' => 'anything_i_like'],
                // An id that is not a database key is somebody probing.
                ['name' => AnalyticsEvent::BANNER_CLICKED, 'entity_type' => 'banner', 'entity_id' => '7 OR 1=1'],
            ],
        ]);

        // 204 to all of it: an app cannot act on the difference, and an error status would teach a
        // prober which names are real.
        $response->assertNoContent();

        $events = $this->recordedEvents();
        $this->assertCount(1, $events, 'only the allow-listed name survived');
        $this->assertSame(AnalyticsEvent::BANNER_CLICKED, $events[0]->name);
        $this->assertNull($events[0]->entity_id, 'a non-numeric id is dropped, never stored');
    }

    public function test_the_storefront_beacon_accepts_the_same_event(): void
    {
        // Same allow-list, different door — the browser's, which is same-origin checked.
        $response = $this->withHeaders(['Origin' => config('app.url')])
            ->postJson(route('analytics.collect'), [
                'events' => [[
                    'name' => AnalyticsEvent::BANNER_CLICKED,
                    'entity_type' => 'banner',
                    'entity_id' => '5',
                ]],
            ]);

        $response->assertNoContent();

        $events = $this->recordedEvents();
        $this->assertCount(1, $events);
        $this->assertSame('5', $events[0]->entity_id);
    }

    public function test_a_theme_card_carries_the_banner_it_came_from(): void
    {
        // Without this the click could only be attributed to "a card in a section somewhere", not
        // to the banner row the merchant edits.
        $banner = Banner::create([
            'banner_type' => 'Main Banner', 'theme' => 'default', 'published' => 1,
            'resource_type' => 'custom', 'url' => 'https://shop.test/products', 'photo' => 'hero.webp',
        ]);

        $cards = app(SectionDataResolver::class)->dashboardBanners('Main Banner', 6);

        $this->assertSame($banner->id, $cards[0]['banner_id']);
    }

    public function test_the_event_is_a_known_name_that_clients_may_send(): void
    {
        $this->assertTrue(AnalyticsEvent::isKnown(AnalyticsEvent::BANNER_CLICKED));
        $this->assertTrue(AnalyticsEvent::isClientAllowed(AnalyticsEvent::BANNER_CLICKED));
        $this->assertFalse(AnalyticsEvent::isClientAllowed(AnalyticsEvent::ORDER_PLACED));
    }
}
