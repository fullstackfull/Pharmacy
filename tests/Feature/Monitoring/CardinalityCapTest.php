<?php

namespace Tests\Feature\Monitoring;

use App\Services\Monitoring\Ingest\BucketWriter;
use Tests\TestCase;

/**
 * What the cardinality cap must not do.
 *
 * The cap exists so a scanner probing random paths cannot make the bucket table grow without
 * bound. It is not allowed to pay for that by evicting the gauges the whole dashboard is built on,
 * or by adding unrelated measurements together and storing the result as if somebody had taken it.
 */
class CardinalityCapTest extends TestCase
{
    private function cap(array $buckets, int $limit = 50): array
    {
        config()->set('monitoring.max_series_per_minute', $limit);

        $method = new \ReflectionMethod(BucketWriter::class, 'capSeries');
        $method->setAccessible(true);

        return $method->invoke(app(BucketWriter::class), $buckets);
    }

    public function test_a_gauge_is_not_evicted_by_a_scanner_probing_random_paths(): void
    {
        // A named series counts samples in `n`. The shared sort read `hits` or `calls`, so every
        // gauge in the system sorted as zero and was dropped before a single-hit scanner route.
        $buckets = ['ser|server.cpu.usage_pct|' => ['n' => 1, 'sum' => 93.0, 'last' => 93.0]];

        for ($i = 0; $i < 200; $i++) {
            $buckets['req|web|GET|/wp-admin-' . $i] = ['hits' => 1];
        }

        $kept = $this->cap($buckets);

        $this->assertArrayHasKey('ser|server.cpu.usage_pct|', $kept, 'the CPU gauge was evicted by a scanner');
        $this->assertSame(93.0, $kept['ser|server.cpu.usage_pct|']['sum']);
    }

    public function test_unrelated_gauges_are_never_added_together(): void
    {
        $buckets = [];

        for ($i = 0; $i < 120; $i++) {
            $buckets['ser|metric.number.' . $i . '|'] = ['n' => 1, 'sum' => 10.0, 'last' => 10.0];
        }

        $kept = $this->cap($buckets);

        $this->assertArrayNotHasKey('ser|__other__', $kept, 'CPU percent and queue-lag seconds must never be summed');
        $this->assertCount(120, $kept);
    }

    public function test_the_folded_row_keeps_the_channel_and_the_method_it_came_from(): void
    {
        $buckets = [];

        // Fifty busy web GETs fill the cap, then a hundred API POSTs overflow.
        for ($i = 0; $i < 50; $i++) {
            $buckets['req|web|GET|/page-' . $i] = ['hits' => 1000];
        }
        for ($i = 0; $i < 100; $i++) {
            $buckets['req|api|POST|/api/v1/probe-' . $i] = ['hits' => 1];
        }

        $kept = $this->cap($buckets);

        $this->assertArrayHasKey('req|api|POST|__other__', $kept);
        $this->assertArrayNotHasKey('req|web|GET|__other__', $kept, 'API POSTs must not be filed as web GETs');
        $this->assertSame(100, $kept['req|api|POST|__other__']['hits']);
    }

    public function test_the_busiest_routes_survive_the_cap(): void
    {
        $buckets = ['req|web|GET|/' => ['hits' => 9999]];

        for ($i = 0; $i < 200; $i++) {
            $buckets['req|web|GET|/junk-' . $i] = ['hits' => 1];
        }

        $kept = $this->cap($buckets);

        $this->assertArrayHasKey('req|web|GET|/', $kept);
        $this->assertSame(9999, $kept['req|web|GET|/']['hits']);
    }
}
