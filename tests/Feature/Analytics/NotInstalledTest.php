<?php

namespace Tests\Feature\Analytics;

use App\Services\Analytics\Reporting\AnalyticsReporting;
use App\Services\Analytics\Reporting\Window;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Every reader has to survive an installation whose migrations have not run.
 *
 * This is not hypothetical: code reaches a server before its migration does on every deployment
 * that does not run them in the same step, and that is exactly how /admin/developer returned a 500
 * in production. A missing table has to produce "analytics is not installed" on the screen, never a
 * stack trace — and never a zero, which reads as a real measurement of nothing.
 */
class NotInstalledTest extends TestCase
{
    private const CONNECTION = 'analytics_absent';

    protected function setUp(): void
    {
        parent::setUp();

        // A real, empty database: nothing here creates the analytics schema.
        config()->set('database.connections.' . self::CONNECTION, [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);
        config()->set('analytics.connection', self::CONNECTION);

        DB::purge(self::CONNECTION);
        DB::connection(self::CONNECTION)->getPdo();
    }

    public function test_the_reader_says_not_installed_rather_than_throwing(): void
    {
        $reporting = app(AnalyticsReporting::class);

        $this->assertFalse($reporting->ready());
        $this->assertSame('not_installed', $reporting->collectionHealth()['state']);
        $this->assertSame('not_installed', $reporting->live()['state']);
    }

    public function test_no_reader_invents_a_zero(): void
    {
        $reporting = app(AnalyticsReporting::class);
        $window = Window::make('30d');

        $totals = $reporting->totals($window);

        $this->assertSame('not_installed', $totals['state']);

        foreach (['visitors', 'sessions', 'pageviews', 'orders', 'revenue', 'conversion_rate'] as $key) {
            $this->assertArrayHasKey($key, $totals, "the view reads {$key} and would crash without it");
            $this->assertNull($totals[$key]['value'], "{$key} must be unavailable, not zero");
        }
    }

    public function test_every_reader_keeps_the_shape_its_view_expects(): void
    {
        $reporting = app(AnalyticsReporting::class);
        $window = Window::make('30d');

        // A different shape here is a crash on the page rather than a message on it.
        $this->assertIsArray($reporting->trend($window));
        $this->assertSame([], $reporting->trend($window));
        $this->assertSame([], $reporting->breakdown($window, 'source')['rows']);
        $this->assertSame('not_installed', $reporting->breakdown($window, 'source')['state']);
        $this->assertSame([], $reporting->funnel($window)['steps']);
        $this->assertSame('not_installed', $reporting->excludedTraffic($window)['state']);
    }
}
