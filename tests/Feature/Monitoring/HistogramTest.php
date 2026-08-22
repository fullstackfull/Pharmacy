<?php

namespace Tests\Feature\Monitoring;

use App\Services\Monitoring\Support\Histogram;
use Tests\TestCase;

/**
 * Percentiles are the reason this class exists, so these are the properties that have to hold.
 *
 * The point of a histogram over stored samples is that p95 costs the same whether the minute held
 * ten requests or ten million — but only if the numbers it produces are trustworthy. A percentile
 * that reads higher than the slowest request that actually happened is not a rounding error; it
 * quietly inflates every latency chart in the system.
 */
class HistogramTest extends TestCase
{
    public function test_an_empty_histogram_reports_nothing_rather_than_zero(): void
    {
        $histogram = new Histogram();

        // Zero would read as "every request was instant", which is the opposite of the truth.
        $this->assertNull($histogram->quantile(0.95));
        $this->assertNull($histogram->average());
        $this->assertSame(0, $histogram->count());
    }

    public function test_a_percentile_never_exceeds_the_slowest_observation(): void
    {
        // Fifteen requests all around 700ms land in one wide bucket; interpolating the median
        // across that bucket used to answer 750 — above the slowest request that ever ran.
        $histogram = new Histogram();
        foreach ([650, 660, 671, 680, 688, 690, 695, 700, 705, 710, 715, 718, 720, 723, 726] as $sample) {
            $histogram->observe($sample);
        }

        $percentiles = $histogram->standardPercentiles();

        $this->assertSame(726.0, $percentiles['max']);
        foreach (['p50', 'p75', 'p90', 'p95', 'p99'] as $key) {
            $this->assertLessThanOrEqual(726.0, $percentiles[$key], "{$key} exceeded the observed maximum");
            $this->assertGreaterThanOrEqual(650.0, $percentiles[$key], "{$key} fell below the observed minimum");
        }
    }

    public function test_the_tail_is_visible_where_an_average_would_hide_it(): void
    {
        $histogram = new Histogram();
        for ($i = 0; $i < 1000; $i++) {
            $histogram->observe(40);
        }
        for ($i = 0; $i < 10; $i++) {
            $histogram->observe(4200);
        }

        // The mean is dragged to ~81ms and says nothing is wrong.
        $this->assertLessThan(120, $histogram->average());
        // The ten slow ones are still countable, which is what an alert needs.
        $this->assertSame(10, $histogram->countAbove(1000));
        $this->assertSame(4200.0, $histogram->max());
    }

    public function test_percentiles_track_a_spread_distribution(): void
    {
        $histogram = new Histogram();
        for ($value = 1; $value <= 1000; $value++) {
            $histogram->observe($value);
        }

        $percentiles = $histogram->standardPercentiles();

        // Wide buckets mean bounded error, not exactness: assert the shape, not a precise value.
        $this->assertGreaterThan(300, $percentiles['p50']);
        $this->assertLessThan(700, $percentiles['p50']);
        $this->assertGreaterThan($percentiles['p50'], $percentiles['p95']);
        $this->assertGreaterThanOrEqual($percentiles['p95'], $percentiles['p99']);
    }

    public function test_state_survives_a_round_trip_through_storage(): void
    {
        // This is what a bucket row does between being written and being charted.
        $histogram = new Histogram();
        foreach ([12, 45, 90, 300, 1200, 6000] as $sample) {
            $histogram->observe($sample);
        }

        $state = $histogram->toState();
        $restored = Histogram::fromState($state['counts'], $state['total'], $state['sum'], $state['min'], $state['max']);

        $this->assertSame($histogram->count(), $restored->count());
        $this->assertSame($histogram->quantile(0.95), $restored->quantile(0.95));
        $this->assertSame($histogram->max(), $restored->max());
    }

    public function test_merging_minutes_into_an_hour_keeps_the_distribution(): void
    {
        $first = new Histogram();
        $second = new Histogram();
        for ($i = 0; $i < 100; $i++) {
            $first->observe(50);
            $second->observe(500);
        }

        $first->merge($second);

        $this->assertSame(200, $first->count());
        $this->assertSame(50.0, $first->min());
        $this->assertSame(500.0, $first->max());
        // Half the observations are at 500, so the median sits at the boundary between them.
        $this->assertGreaterThanOrEqual(50.0, $first->quantile(0.5));
        $this->assertLessThanOrEqual(500.0, $first->quantile(0.5));
    }

    public function test_an_observation_past_the_last_bucket_is_counted_not_dropped(): void
    {
        $histogram = new Histogram([10, 100]);
        $histogram->observe(5);
        $histogram->observe(50);
        $histogram->observe(90_000);

        $this->assertSame(3, $histogram->count());
        $this->assertSame(1, $histogram->countAbove(100));
        $this->assertSame(90_000.0, $histogram->max());
        // The overflow bucket has no upper bound, so the honest p99 is the largest value seen.
        $this->assertSame(90_000.0, $histogram->quantile(0.99));
    }

    public function test_merging_histograms_with_different_bucket_layouts_keeps_totals_honest(): void
    {
        // A config change between two writes: the counts are not comparable, so only the figures
        // that remain meaningful are merged rather than blending two different distributions.
        $wide = new Histogram([100, 1000]);
        $wide->observe(50);
        $narrow = new Histogram([10, 20, 30]);
        $narrow->observe(15);

        $wide->merge($narrow);

        $this->assertSame(2, $wide->count());
        $this->assertSame(15.0, $wide->min());
        $this->assertSame(50.0, $wide->max());
    }
}
