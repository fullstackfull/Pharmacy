<?php

namespace Tests\Feature;

use App\Models\SellerInsight;
use App\Services\SellerIntelligence\Severity\ImpactSignals;
use App\Services\SellerIntelligence\Severity\SellerBaseline;
use App\Services\SellerIntelligence\Severity\SeverityEngine;
use Tests\TestCase;

/**
 * How much a problem matters to this particular seller.
 *
 * The brief's own example is the specification: a stockout on a product selling once every sixty
 * days must not rank with a stockout on the seller's best seller. Every measure here is therefore a
 * share of the seller's own business, and the test that matters most is the one where the same
 * absolute figure produces different severities for different shops.
 *
 * The second thing under test is restraint about what was not measured. A detector that cannot see
 * revenue must produce a lower score than one that can and found revenue at stake — not the same
 * score scaled up to compensate, which would rank a half-measured issue above a fully-measured one.
 */
class SeverityEngineTest extends TestCase
{
    private function engine(): SeverityEngine
    {
        return new SeverityEngine();
    }

    public function test_the_same_stockout_is_not_the_same_event_for_two_different_shops(): void
    {
        $engine = $this->engine();

        // One product out of stock, worth 500 a month, in a shop turning over 2,000 a month.
        $bestSeller = new ImpactSignals(
            revenueAtRisk: 500, sellerRecentRevenue: 2000,
            affectedCount: 1, sellerTotalCount: 20,
        );

        // The identical product, identical revenue at stake, in a shop turning over 200,000.
        $rareItem = new ImpactSignals(
            revenueAtRisk: 500, sellerRecentRevenue: 200000,
            affectedCount: 1, sellerTotalCount: 20,
        );

        $this->assertGreaterThan(
            $engine->score($rareItem),
            $engine->score($bestSeller),
            'The same absolute figure scored the same for two shops of different sizes.',
        );
        $this->assertSame(SellerInsight::SEVERITY_HIGH, $engine->severity($bestSeller));
        $this->assertSame(SellerInsight::SEVERITY_LOW, $engine->severity($rareItem));
    }

    public function test_a_quarter_of_a_sellers_revenue_saturates_the_revenue_component(): void
    {
        $engine = $this->engine();

        $quarter = new ImpactSignals(revenueAtRisk: 250, sellerRecentRevenue: 1000);
        $half = new ImpactSignals(revenueAtRisk: 500, sellerRecentRevenue: 1000);

        // Past the saturation point the component stops climbing — otherwise one signal alone would
        // decide everything and the other four would be decoration.
        $this->assertSame(SeverityEngine::WEIGHT_REVENUE, (int) round($engine->breakdown($quarter)['revenue']));
        $this->assertSame(SeverityEngine::WEIGHT_REVENUE, (int) round($engine->breakdown($half)['revenue']));
    }

    public function test_no_single_component_can_reach_critical_alone(): void
    {
        $engine = $this->engine();

        // Everything the revenue component can measure, and nothing else known.
        $revenueOnly = new ImpactSignals(revenueAtRisk: 10000, sellerRecentRevenue: 1000);

        // Two independent things have to be true at once before a seller is told something is
        // critical. One loud signal is a strong finding, not an emergency.
        $this->assertLessThan(SeverityEngine::BAND_CRITICAL, $engine->score($revenueOnly));
        $this->assertSame(SellerInsight::SEVERITY_HIGH, $engine->severity($revenueOnly));
    }

    public function test_a_passed_deadline_and_real_money_together_do_reach_critical(): void
    {
        $engine = $this->engine();

        $signals = new ImpactSignals(
            revenueAtRisk: 400, sellerRecentRevenue: 1000,
            affectedCount: 5, sellerTotalCount: 40,
            hoursUntilDue: -3,
        );

        $this->assertGreaterThanOrEqual(SeverityEngine::BAND_CRITICAL, $engine->score($signals));
        $this->assertSame(SellerInsight::SEVERITY_CRITICAL, $engine->severity($signals));
    }

    public function test_urgency_stays_flat_until_the_deadline_is_close(): void
    {
        $engine = $this->engine();

        $tomorrow = new ImpactSignals(hoursUntilDue: 20);
        $soon = new ImpactSignals(hoursUntilDue: 2);
        $late = new ImpactSignals(hoursUntilDue: -1);

        // An order with twenty hours left is not urgent. A curve that started scoring it would make
        // everything look mildly urgent, which is the same as nothing being urgent.
        $this->assertSame(0.0, $engine->breakdown($tomorrow)['urgency']);
        $this->assertGreaterThan(0, $engine->breakdown($soon)['urgency']);
        $this->assertSame((float) SeverityEngine::WEIGHT_URGENCY, $engine->breakdown($late)['urgency']);
    }

    public function test_a_missing_signal_scores_zero_rather_than_averaging_away(): void
    {
        $engine = $this->engine();

        $measured = new ImpactSignals(revenueAtRisk: 250, sellerRecentRevenue: 1000);
        $unmeasured = new ImpactSignals(affectedCount: 2, sellerTotalCount: 20);

        // The unmeasured one must not be scaled up to look like a complete measurement. A
        // partially-measured issue ranking above a fully-measured one is how a priority list stops
        // meaning anything.
        $this->assertSame(0.0, $engine->breakdown($unmeasured)['revenue']);
        $this->assertGreaterThan($engine->score($unmeasured), $engine->score($measured));
    }

    public function test_confidence_says_how_much_of_the_picture_was_measured(): void
    {
        $engine = $this->engine();

        $thin = new ImpactSignals(hoursUntilDue: 1);
        $full = new ImpactSignals(
            revenueAtRisk: 100, sellerRecentRevenue: 1000,
            affectedCount: 1, sellerTotalCount: 10,
            hoursUntilDue: 1, openForHours: 5,
        );

        // A 60 built from every component is a different claim from a 60 built from urgency alone.
        $this->assertLessThan($engine->confidence($full), $engine->confidence($thin));
        $this->assertSame(1.0, $engine->confidence($full));
    }

    public function test_a_detector_can_declare_something_not_a_matter_of_degree(): void
    {
        $engine = $this->engine();

        // A listing rejected by a moderator is critical for a shop with one product and for one with
        // ten thousand. The arithmetic here says almost nothing; the floor says everything.
        $signals = new ImpactSignals(affectedCount: 1, sellerTotalCount: 9000, severityFloor: SellerInsight::SEVERITY_CRITICAL);

        $this->assertLessThan(SeverityEngine::BAND_MEDIUM, $engine->score($signals));
        $this->assertSame(SellerInsight::SEVERITY_CRITICAL, $engine->severity($signals));
    }

    public function test_a_floor_never_lowers_a_worse_finding(): void
    {
        $engine = $this->engine();

        $signals = new ImpactSignals(
            revenueAtRisk: 900, sellerRecentRevenue: 1000,
            affectedCount: 30, sellerTotalCount: 40,
            hoursUntilDue: -5,
            severityFloor: SellerInsight::SEVERITY_LOW,
        );

        // The floor is a minimum, not an instruction.
        $this->assertSame(SellerInsight::SEVERITY_CRITICAL, $engine->severity($signals));
    }

    public function test_a_new_shop_with_no_history_is_not_treated_as_in_more_danger(): void
    {
        $engine = $this->engine();

        // Dividing by nothing would make every problem infinite for a shop that has not sold yet.
        $signals = new ImpactSignals(revenueAtRisk: 500, sellerRecentRevenue: 0, affectedCount: 1, sellerTotalCount: 0);

        $this->assertSame(0, $engine->score($signals));
        $this->assertSame(SellerInsight::SEVERITY_LOW, $engine->severity($signals));
    }

    public function test_recurrence_and_duration_add_weight_without_dominating(): void
    {
        $engine = $this->engine();

        $fresh = new ImpactSignals(affectedCount: 1, sellerTotalCount: 100, openForHours: 0, detectionCount: 1);
        $persistent = new ImpactSignals(affectedCount: 1, sellerTotalCount: 100, openForHours: 200, detectionCount: 12);

        $this->assertGreaterThan($engine->score($fresh), $engine->score($persistent));
        // Together they are capped at 15 of 100 — a problem that will not go away is worth noticing,
        // not worth outranking one that is costing money right now.
        $this->assertLessThanOrEqual(
            SeverityEngine::WEIGHT_DURATION + SeverityEngine::WEIGHT_RECURRENCE,
            $engine->score($persistent) - $engine->score($fresh),
        );
    }

    public function test_the_denominator_matches_the_kind_of_problem(): void
    {
        $baseline = new SellerBaseline(recentRevenue: 5000, productCount: 800, recentOrderCount: 12);

        // A shop with eight hundred products and twelve orders must not look calm during an order
        // crisis because the catalogue is large.
        $this->assertSame(800, $baseline->totalFor(SellerInsight::CATEGORY_CATALOG));
        $this->assertSame(800, $baseline->totalFor(SellerInsight::CATEGORY_INVENTORY));
        $this->assertSame(12, $baseline->totalFor(SellerInsight::CATEGORY_ORDERS));
        $this->assertSame(12, $baseline->totalFor(SellerInsight::CATEGORY_RETURNS));
        // Nothing sensible to divide by is null, not one.
        $this->assertNull($baseline->totalFor(SellerInsight::CATEGORY_INTEGRATIONS));
    }

    public function test_the_components_total_one_hundred_by_construction(): void
    {
        // The bands are chosen against this total; if the weights drifted, "75 is critical" would
        // quietly stop meaning what it was designed to mean.
        $this->assertSame(100,
            SeverityEngine::WEIGHT_REVENUE + SeverityEngine::WEIGHT_VOLUME + SeverityEngine::WEIGHT_URGENCY
            + SeverityEngine::WEIGHT_DURATION + SeverityEngine::WEIGHT_RECURRENCE);
    }
}
