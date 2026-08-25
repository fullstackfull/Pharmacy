<?php

namespace Tests\Feature\Monitoring;

use App\Services\Monitoring\BlastRadius;
use App\Services\Monitoring\Support\Clock;
use App\Services\Monitoring\Support\MonitoringSettings;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * How many sellers a failure is reaching.
 *
 * On a marketplace this is the first question asked about any incident, and the console could not
 * answer it at all: no monitoring table carried a seller, vendor or shop id — "vendor" existed only
 * as a request-channel label — so the page could say a bug had fired two hundred times and never
 * whether that was one shop with a loop or the whole marketplace. Every triage began with a manual
 * SQL session, during the incident, by the person who should have been fixing it.
 *
 * These tests hold the measurement and the honesty around it: guests are not counted as an unknown
 * seller, and the signals that carry no seller dimension are named rather than quietly left out of
 * a total that then reads as reassurance.
 */
class BlastRadiusTest extends TestCase
{
    private const CONNECTION = 'monitoring_test';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.connections.' . self::CONNECTION, [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);
        config()->set('monitoring.connection', self::CONNECTION);

        DB::purge(self::CONNECTION);
        DB::connection(self::CONNECTION)->getPdo();

        foreach (glob(database_path('migrations/*_create_monitoring_*_tables.php')) as $migration) {
            (require $migration)->up();
        }

        app(MonitoringSettings::class)->forget();
    }

    private function error(int $groupId, ?string $userType, ?int $userId): void
    {
        DB::connection(self::CONNECTION)->table('monitoring_errors')->insert([
            'group_id' => $groupId,
            'user_type' => $userType,
            'user_id' => $userId,
            'created_at' => Clock::stamp(),
        ]);
    }

    public function test_the_window_counts_distinct_sellers_not_occurrences(): void
    {
        $this->error(1, 'seller', 4);
        $this->error(1, 'seller', 4);
        $this->error(1, 'seller', 9);

        $radius = app(BlastRadius::class)->inWindow(Clock::hoursAgo(1));

        $this->assertSame('ok', $radius['state']);
        $this->assertSame(2, $radius['sellers']);
        $this->assertSame(3, $radius['occurrences']);
    }

    /**
     * An inflated radius is worse than a stated gap when it is what decides whether somebody is
     * woken up at three in the morning.
     */
    public function test_guests_and_customers_are_not_counted_as_sellers(): void
    {
        $this->error(1, null, null);
        $this->error(1, 'customer', 11);
        $this->error(1, 'admin', 1);

        $radius = app(BlastRadius::class)->inWindow(Clock::hoursAgo(1));

        $this->assertSame(0, $radius['sellers']);
    }

    public function test_one_shop_with_a_loop_is_distinguishable_from_the_marketplace(): void
    {
        foreach (range(1, 40) as $ignored) {
            $this->error(7, 'seller', 3);
        }

        $radius = app(BlastRadius::class)->forGroup(7, Clock::hoursAgo(1));

        $this->assertSame(1, $radius['sellers']);
    }

    /** A long list stops being a list; the count stays exact and the names are capped. */
    public function test_a_wide_blast_names_the_first_few_and_counts_the_rest(): void
    {
        foreach (range(1, 12) as $sellerId) {
            $this->error(7, 'seller', $sellerId);
        }

        $radius = app(BlastRadius::class)->forGroup(7, Clock::hoursAgo(1));

        $this->assertSame(12, $radius['sellers']);
        $this->assertCount(8, $radius['named']);
        $this->assertSame(4, $radius['more']);
    }

    /**
     * A blast radius that quietly omits what it cannot see reads as "one seller affected" when the
     * truth is "one seller affected, and three systems we cannot attribute at all".
     */
    public function test_the_signals_that_carry_no_seller_are_named_rather_than_hidden(): void
    {
        $radius = app(BlastRadius::class)->inWindow(Clock::hoursAgo(1));

        $this->assertArrayHasKey('requests', $radius['unattributed']);
        $this->assertArrayHasKey('queues', $radius['unattributed']);
        $this->assertArrayHasKey('dependencies', $radius['unattributed']);
    }

    public function test_an_unreadable_store_reports_that_rather_than_zero(): void
    {
        DB::connection(self::CONNECTION)->statement('DROP TABLE monitoring_errors');

        $radius = app(BlastRadius::class)->inWindow(Clock::hoursAgo(1));

        $this->assertSame('unavailable', $radius['state']);
        $this->assertNull($radius['sellers']);
    }
}
