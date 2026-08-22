<?php

namespace Tests\Feature\Analytics;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A campaign's headline numbers must cover the campaign's life, not one day of it.
 *
 * The rollup wrote the day being processed into sessions/orders/revenue on the campaign row, while
 * clicks on that same row accumulate forever. The campaigns screen then divided a lifetime click
 * count by a one-day session count and called it a conversion rate — and a campaign that had no
 * sessions on the day being rolled up kept whatever numbers the last day it appeared in left there.
 */
class CampaignTotalsTest extends TestCase
{
    private const CONNECTION = 'campaign_totals';

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

        foreach (glob(database_path('migrations/*_create_analytics_tables.php')) as $migration) {
            (require $migration)->up();
        }
    }

    public function test_the_totals_cover_every_day_the_campaign_ran(): void
    {
        $connection = DB::connection(self::CONNECTION);

        $connection->table('analytics_campaigns')->insert([
            'id' => 1, 'name' => 'Ramadan', 'code' => 'rmd26',
            'destination_url' => 'https://shop.test/', 'utm_source' => 'instagram',
            'utm_medium' => 'social', 'utm_campaign' => 'ramadan', 'is_active' => true,
            'clicks' => 40, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $connection->table('analytics_campaigns')->insert([
            'id' => 2, 'name' => 'Old', 'code' => 'oldx24',
            'destination_url' => 'https://shop.test/', 'utm_source' => 'print',
            'utm_medium' => 'qr', 'utm_campaign' => 'old', 'is_active' => true,
            'clicks' => 5, 'sessions' => 99, 'orders' => 9, 'revenue' => 900,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Three days of sessions on campaign 1, one order each on two of them.
        foreach ([['-2 days', 1, 100.0], ['-1 day', 1, 250.0], ['now', 0, 0.0]] as $index => [$when, $orders, $revenue]) {
            $connection->table('analytics_sessions')->insert([
                'visitor_id' => 'visitor-' . $index,
                'campaign_id' => 1,
                'started_at' => now()->modify($when),
                'last_activity_at' => now()->modify($when),
                'pageviews' => 2,
                'orders' => $orders,
                'revenue' => $revenue,
                'is_bot' => false,
                'is_internal' => false,
            ]);
        }

        $this->artisan('analytics:rollup --days=1')->assertExitCode(0);

        $live = $connection->table('analytics_campaigns')->find(1);
        $this->assertSame(3, (int) $live->sessions, 'every day the campaign ran, not just the one rolled up');
        $this->assertSame(2, (int) $live->orders);
        $this->assertSame(350.0, (float) $live->revenue);

        // The campaign with no sessions at all has to read as zero rather than keeping yesterday's.
        $stale = $connection->table('analytics_campaigns')->find(2);
        $this->assertSame(0, (int) $stale->sessions);
        $this->assertSame(0, (int) $stale->orders);
        $this->assertSame(0.0, (float) $stale->revenue);
    }
}
