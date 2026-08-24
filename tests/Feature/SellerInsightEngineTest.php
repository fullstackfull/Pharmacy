<?php

namespace Tests\Feature;

use App\Models\SellerInsight;
use App\Services\SellerIntelligence\InsightDraft;
use App\Services\SellerIntelligence\InsightProducer;
use App\Services\SellerIntelligence\SellerInsightEngine;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Services\SellerIntelligence\Severity\ImpactSignals;
use App\Services\SellerIntelligence\Severity\SeverityEngine;
use Tests\Support\BuildsIssueSchema;
use Tests\TestCase;

/**
 * The one store that decides what a seller is told to look at.
 *
 * The properties asserted here are what separate an Action Center from a wall of noise:
 *
 * A warning has an identity — the seller, the kind of problem, the thing it is about — so a producer
 * running every hour updates one row instead of stacking twenty-four copies of the same sentence.
 *
 * A warning disappears by itself when it stops being true. An alert list that has to be cleared by
 * hand after the problem is fixed is one nobody reads a week later.
 *
 * The worst thing is first. Sorted by the stored word, "critical" lands after "high" alphabetically,
 * and the most urgent thing on a seller's morning is second in the list.
 *
 * And a seller may decline a suggestion but not hide that their account is at risk.
 */
class SellerInsightEngineTest extends TestCase
{
    use BuildsIssueSchema;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('seller_insights');
        $this->createIssueTable();
    }

    /** A producer that says exactly what the test tells it to. */
    private function producer(string $type, array $drafts): InsightProducer
    {
        return new class($type, $drafts) implements InsightProducer {
            public function __construct(private string $type, private array $drafts)
            {
            }

            public function type(): string
            {
                return $this->type;
            }

            public function produce(int|string $sellerId): iterable
            {
                return $this->drafts;
            }
        };
    }

    private function draft(array $overrides = []): InsightDraft
    {
        return new InsightDraft(
            sellerId: $overrides['sellerId'] ?? 1,
            type: $overrides['type'] ?? 'STOCK',
            severity: $overrides['severity'] ?? 'high',
            title: $overrides['title'] ?? 'running_out',
            body: $overrides['body'] ?? null,
            entityType: $overrides['entityType'] ?? 'product',
            entityId: $overrides['entityId'] ?? 7,
            metric: $overrides['metric'] ?? 3.0,
        );
    }

    public function test_running_a_producer_twice_updates_one_row_rather_than_stacking_copies(): void
    {
        $engine = new SellerInsightEngine([
            $this->producer('STOCK', [$this->draft(['metric' => 3.0])]),
        ]);
        $engine->refresh(1);

        // The same problem, worse than before: still one warning, with the new number.
        $engine = new SellerInsightEngine([
            $this->producer('STOCK', [$this->draft(['metric' => 1.0, 'severity' => 'critical'])]),
        ]);
        $engine->refresh(1);

        $this->assertSame(1, SellerInsight::count(), 'The hourly run stacked a second copy.');
        $this->assertSame(1.0, (float) SellerInsight::first()->metric);
        $this->assertSame('critical', SellerInsight::first()->severity);
    }

    public function test_a_warning_resolves_itself_when_it_stops_being_true(): void
    {
        (new SellerInsightEngine([$this->producer('STOCK', [$this->draft()])]))->refresh(1);
        $this->assertCount(1, (new SellerInsightEngine())->open(1)->all() ?: []);

        // The seller restocked, so the producer no longer reports it.
        $result = (new SellerInsightEngine([$this->producer('STOCK', [])]))->refresh(1);

        $this->assertSame(1, $result['resolved']);
        $this->assertNotNull(SellerInsight::first()->resolved_at);
        $this->assertTrue((new SellerInsightEngine())->open(1)->isEmpty());
    }

    public function test_one_producer_resolving_does_not_touch_another_producers_warnings(): void
    {
        $engine = new SellerInsightEngine([
            $this->producer('STOCK', [$this->draft(['type' => 'STOCK'])]),
            $this->producer('QUALITY', [$this->draft(['type' => 'QUALITY', 'entityId' => 9])]),
        ]);
        $engine->refresh(1);

        // Only STOCK runs, and finds nothing. QUALITY's warning is not its business.
        (new SellerInsightEngine([$this->producer('STOCK', [])]))->refresh(1, ['STOCK']);

        $open = (new SellerInsightEngine())->open(1);
        $this->assertCount(1, $open);
        $this->assertSame('QUALITY', $open->first()->type);
    }

    public function test_the_worst_thing_is_first(): void
    {
        // Written worst-first so severity runs *opposite* to insertion order. A sort that quietly
        // ranks by id, or by only one of two keys, passes when the two happen to agree — which is
        // how a medium insight reached the top of a real seller's list above a high one.
        $engine = new SellerInsightEngine([
            $this->producer('STOCK', [
                $this->draft(['severity' => 'critical', 'entityId' => 1]),
                $this->draft(['severity' => 'high', 'entityId' => 2]),
                $this->draft(['severity' => 'medium', 'entityId' => 3]),
                $this->draft(['severity' => 'low', 'entityId' => 4]),
            ]),
        ]);
        $engine->refresh(1);

        $open = (new SellerInsightEngine())->open(1);

        // Sorted by the stored word, "critical" would also land after "high".
        $this->assertSame(['critical', 'high', 'medium', 'low'], $open->pluck('severity')->all());
        $this->assertSame(['1', '2', '3', '4'], $open->pluck('entity_id')->all());
    }

    public function test_two_warnings_of_the_same_kind_are_ranked_by_severity_not_by_age(): void
    {
        // The narrowest form of the same defect: the worse one was written first, so it has the
        // lower id, and any sort that falls back to recency puts the milder one on top.
        (new SellerInsightEngine([
            $this->producer('STOCK', [
                $this->draft(['severity' => 'high', 'entityId' => 1]),
                $this->draft(['severity' => 'medium', 'entityId' => 2]),
            ]),
        ]))->refresh(1);

        $this->assertSame(['high', 'medium'], (new SellerInsightEngine())->open(1)->pluck('severity')->all());
    }

    public function test_a_seller_sees_only_their_own_warnings(): void
    {
        (new SellerInsightEngine([
            $this->producer('STOCK', [
                $this->draft(['sellerId' => 1, 'entityId' => 1]),
                $this->draft(['sellerId' => 2, 'entityId' => 2]),
            ]),
        ]))->refresh(1);

        $this->assertCount(1, (new SellerInsightEngine())->open(1));
        $this->assertSame(1, (new SellerInsightEngine())->open(1)->first()->seller_id);
    }

    public function test_a_seller_cannot_dismiss_another_sellers_warning(): void
    {
        (new SellerInsightEngine([
            $this->producer('STOCK', [$this->draft(['sellerId' => 2])]),
        ]))->refresh(2);

        $rival = SellerInsight::first();
        $engine = new SellerInsightEngine();

        // Refused, and refused the same way a nonexistent id is: an id is not a way to find out
        // what other sellers have been warned about.
        $this->assertFalse($engine->dismiss(1, $rival->id));
        $this->assertFalse($engine->dismiss(1, 999999));
        $this->assertNull($rival->fresh()->dismissed_at);
    }

    public function test_a_critical_warning_cannot_be_dismissed(): void
    {
        (new SellerInsightEngine([
            $this->producer('STOCK', [$this->draft(['severity' => 'critical'])]),
        ]))->refresh(1);

        $insight = SellerInsight::first();

        $this->assertFalse((new SellerInsightEngine())->dismiss(1, $insight->id));
        $this->assertNull($insight->fresh()->dismissed_at);
    }

    public function test_a_dismissed_warning_leaves_the_list_but_is_not_deleted(): void
    {
        (new SellerInsightEngine([$this->producer('STOCK', [$this->draft()])]))->refresh(1);
        $engine = new SellerInsightEngine();

        $this->assertTrue($engine->dismiss(1, SellerInsight::first()->id));

        $this->assertTrue($engine->open(1)->isEmpty());
        $this->assertSame(1, SellerInsight::count(), 'History is kept; only the list is quieter.');
    }

    public function test_an_expired_warning_stops_showing_without_anyone_acting(): void
    {
        (new SellerInsightEngine([
            $this->producer('STOCK', [
                new InsightDraft(
                    sellerId: 1, type: 'STOCK', severity: 'high', title: 'was_urgent_once',
                    entityType: 'order', entityId: 5, expiresAt: now()->subDay(),
                ),
            ]),
        ]))->refresh(1);

        $this->assertTrue((new SellerInsightEngine())->open(1)->isEmpty());
    }

    public function test_a_producer_returning_something_malformed_is_ignored_rather_than_shown(): void
    {
        // A severity nothing can sort, and a type that is not this producer's, are bugs — and a bug
        // here would put an unsortable or misattributed row in front of a seller.
        (new SellerInsightEngine([
            $this->producer('STOCK', [
                $this->draft(['severity' => 'apocalyptic', 'entityId' => 1]),
                $this->draft(['type' => 'SOMETHING_ELSE', 'entityId' => 2]),
                $this->draft(['entityId' => 3]),
            ]),
        ]))->refresh(1);

        $open = (new SellerInsightEngine())->open(1);
        $this->assertCount(1, $open);
        $this->assertSame('3', $open->first()->entity_id);
    }

    public function test_counts_are_what_a_home_badge_needs(): void
    {
        (new SellerInsightEngine([
            $this->producer('STOCK', [
                $this->draft(['severity' => 'critical', 'entityId' => 1]),
                $this->draft(['severity' => 'critical', 'entityId' => 2]),
                $this->draft(['severity' => 'low', 'entityId' => 3]),
            ]),
        ]))->refresh(1);

        $counts = (new SellerInsightEngine())->counts(1);

        $this->assertSame(2, $counts['critical']);
        $this->assertSame(0, $counts['high']);
        $this->assertSame(1, $counts['low']);
        $this->assertSame(3, $counts['total']);
    }

    public function test_a_measured_severity_overrides_the_one_the_detector_declared(): void
    {
        // A detector that supplies signals is no longer the authority on how bad its own finding is.
        // It says "high"; the engine measures a tenth of a shop's catalogue affected and nothing
        // else, which is medium, and the engine wins.
        $engine = new SellerInsightEngine(
            producers: [$this->producer('STOCK', [
                new InsightDraft(
                    sellerId: 1,
                    type: 'STOCK',
                    severity: SellerInsight::SEVERITY_HIGH,
                    title: 'running_out',
                    entityType: 'product',
                    entityId: 7,
                    category: SellerInsight::CATEGORY_INVENTORY,
                    signals: new ImpactSignals(affectedCount: 1, sellerTotalCount: 10),
                ),
            ])],
            severity: new SeverityEngine(),
        );

        $engine->refresh(1);

        $insight = SellerInsight::first();
        $this->assertSame(SellerInsight::SEVERITY_MEDIUM, $insight->severity);
        $this->assertSame(25, $insight->impact_score);
        // And it shows its working, because "why is this medium" has to have an answer.
        $this->assertEquals(25, $insight->metadata['score_breakdown']['volume']);
    }

    public function test_a_detector_that_measures_nothing_keeps_the_severity_it_declared(): void
    {
        $engine = new SellerInsightEngine(
            producers: [$this->producer('STOCK', [
                new InsightDraft(
                    sellerId: 1, type: 'STOCK', severity: SellerInsight::SEVERITY_CRITICAL,
                    title: 'rejected', entityType: 'product', entityId: 7,
                ),
            ])],
            severity: new SeverityEngine(),
        );

        $engine->refresh(1);

        // No signals means the finding is not a matter of degree, not that it is unimportant.
        $this->assertSame(SellerInsight::SEVERITY_CRITICAL, SellerInsight::first()->severity);
    }

    public function test_an_issue_seen_again_keeps_its_history_and_the_sellers_own_status(): void
    {
        $draft = new InsightDraft(
            sellerId: 1, type: 'STOCK', severity: SellerInsight::SEVERITY_HIGH,
            title: 'running_out', entityType: 'product', entityId: 7,
        );
        $engine = new SellerInsightEngine(producers: [$this->producer('STOCK', [$draft])]);

        $engine->refresh(1);
        $first = SellerInsight::first();
        $first->forceFill([
            'status' => SellerInsight::STATUS_IN_PROGRESS,
            'first_detected_at' => now()->subDays(3),
        ])->save();

        $engine->refresh(1);
        $again = SellerInsight::first();

        // Someone working on a problem should not find it back at the beginning because a sweep ran.
        $this->assertSame(SellerInsight::STATUS_IN_PROGRESS, $again->status);
        // And "how long has this been true" — what escalation runs on — has to survive the upsert.
        $this->assertSame(3, (int) $again->first_detected_at->diffInDays(now()));
        $this->assertSame(2, $again->detection_count);
    }

    public function test_a_closed_issue_that_comes_back_is_open_again(): void
    {
        $draft = new InsightDraft(
            sellerId: 1, type: 'STOCK', severity: SellerInsight::SEVERITY_HIGH,
            title: 'running_out', entityType: 'product', entityId: 7,
        );
        $engine = new SellerInsightEngine(producers: [$this->producer('STOCK', [$draft])]);

        $engine->refresh(1);
        SellerInsight::first()->forceFill([
            'status' => SellerInsight::STATUS_RESOLVED,
            'resolved_at' => now()->subHour(),
            'resolution_type' => SellerInsight::RESOLUTION_AUTO,
        ])->save();

        $engine->refresh(1);

        // The problem is real again regardless of what anybody decided about it last time.
        $insight = SellerInsight::first();
        $this->assertSame(SellerInsight::STATUS_OPEN, $insight->status);
        $this->assertNull($insight->resolved_at);
        $this->assertNull($insight->resolution_type);
    }
}
