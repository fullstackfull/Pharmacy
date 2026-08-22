<?php

namespace Tests\Feature\Analytics;

use App\Services\Analytics\Reporting\AnalyticsReporting;
use App\Services\Analytics\Reporting\Window;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * A vendor must never see another vendor's numbers.
 *
 * This is the hardest requirement in the analytics area and the one that cannot be checked by
 * reading the code, so it builds the real schema from the shipped migration and puts deliberately
 * identical activity behind two different vendor ids. If scoping ever regresses, the two vendors
 * start agreeing with each other and this fails.
 */
class VendorIsolationTest extends TestCase
{
    private const CONNECTION = 'analytics_test';

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

        DB::purge(self::CONNECTION);
        DB::connection(self::CONNECTION)->getPdo();

        // The shipped migration, not a hand-written stand-in: a test against a schema that has
        // drifted from production is a test of nothing.
        foreach (glob(database_path('migrations/*_create_analytics_tables.php')) as $migration) {
            (require $migration)->up();
        }

        $this->assertTrue(Schema::connection(self::CONNECTION)->hasTable('analytics_events'));
    }

    public function test_two_vendors_with_identical_activity_see_only_their_own(): void
    {
        $this->recordFor(vendorId: 7, productId: '101', revenue: 250.00);
        $this->recordFor(vendorId: 8, productId: '202', revenue: 900.00);

        $reporting = app(AnalyticsReporting::class);
        $window = Window::make('7d');

        $first = $reporting->forVendor(7, $window);
        $second = $reporting->forVendor(8, $window);

        $this->assertSame(250.0, (float) $first['summary']['revenue']);
        $this->assertSame(900.0, (float) $second['summary']['revenue']);

        $this->assertSame(['101'], $this->productIds($first));
        $this->assertSame(['202'], $this->productIds($second));
    }

    public function test_a_vendor_with_no_activity_sees_nothing_rather_than_everything(): void
    {
        // The failure mode worth guarding: a scoping bug that drops the WHERE clause shows an
        // empty vendor every other vendor's data, and looks like a working feature.
        $this->recordFor(vendorId: 7, productId: '101', revenue: 250.00);
        $this->recordFor(vendorId: 8, productId: '202', revenue: 900.00);

        $stranger = app(AnalyticsReporting::class)->forVendor(999, Window::make('7d'));

        $this->assertSame(0, $stranger['summary']['sessions']);
        $this->assertSame(0, $stranger['summary']['product_views']);
        $this->assertSame(0.0, (float) $stranger['summary']['revenue']);
        $this->assertSame([], $stranger['products']);
    }

    public function test_events_with_no_vendor_never_leak_into_a_vendors_report(): void
    {
        // Admin-owned products carry a null vendor_id. A report that treated null as "matches
        // everything" would show every seller the in-house shop's numbers as their own.
        $this->recordFor(vendorId: null, productId: '303', revenue: 400.00);
        $this->recordFor(vendorId: 7, productId: '101', revenue: 250.00);

        $vendor = app(AnalyticsReporting::class)->forVendor(7, Window::make('7d'));

        $this->assertSame(250.0, (float) $vendor['summary']['revenue']);
        $this->assertSame(['101'], $this->productIds($vendor));
    }

    public function test_bot_traffic_is_excluded_from_a_vendors_report_too(): void
    {
        // The exclusion has to hold on every path, not only the admin one — otherwise a vendor's
        // conversion rate is computed against a denominator full of crawlers.
        $this->recordFor(vendorId: 7, productId: '101', revenue: 250.00);
        $this->recordFor(vendorId: 7, productId: '101', revenue: 500.00, isBot: true);

        $vendor = app(AnalyticsReporting::class)->forVendor(7, Window::make('7d'));

        $this->assertSame(250.0, (float) $vendor['summary']['revenue'], 'bot revenue was counted');
        $this->assertSame(1, $vendor['summary']['orders']);
    }

    // -------------------------------------------------------------------------------------------

    private function recordFor(?int $vendorId, string $productId, float $revenue, bool $isBot = false): void
    {
        $connection = DB::connection(self::CONNECTION);
        $suffix = uniqid('', true);
        $visitor = 'visitor-' . ($vendorId ?? 'admin') . ($isBot ? '-bot' : '');

        $connection->table('analytics_events')->insert([
            [
                'name' => 'product_viewed',
                'category' => 'catalogue',
                'visitor_id' => $visitor,
                'session_id' => abs(crc32($visitor)),
                'channel' => 'web',
                'entity_type' => 'product',
                'entity_id' => $productId,
                'vendor_id' => $vendorId,
                'value' => null,
                'path' => '/product/{slug}',
                'is_bot' => $isBot,
                'is_internal' => false,
                'dedupe_key' => 'view-' . $suffix,
                'occurred_at' => now(),
            ],
            [
                'name' => 'order_placed',
                'category' => 'order',
                'visitor_id' => $visitor,
                'session_id' => abs(crc32($visitor)),
                'channel' => 'web',
                'entity_type' => 'order',
                'entity_id' => $productId . '-order',
                'vendor_id' => $vendorId,
                'value' => $revenue,
                'path' => '/checkout',
                'is_bot' => $isBot,
                'is_internal' => false,
                'dedupe_key' => 'order-' . $suffix,
                'occurred_at' => now(),
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<int, string>
     */
    private function productIds(array $report): array
    {
        return array_map(static fn (object $row) => (string) $row->entity_id, $report['products']);
    }
}
