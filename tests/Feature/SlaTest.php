<?php

namespace Tests\Feature;

use App\Models\SellerSlaBreach;
use App\Services\Marketplace\SellerScorecardService;
use App\Services\Marketplace\SlaService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Seller SLA policy and breach ledger (Phase 3, Stage E).
 *
 * The policy comparison is pure and asserted directly; the ledger reconcile (open on breach, clear on
 * recovery, one row per line) is asserted through a stubbed scorecard so the SLA logic is tested in
 * isolation from how the metrics are computed.
 */
class SlaTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('seller_sla_breaches');
        Schema::create('seller_sla_breaches', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('seller_id'); $t->string('metric', 40);
            $t->decimal('threshold', 12, 4); $t->decimal('actual_value', 12, 4);
            $t->string('status', 12)->default('open'); $t->timestamp('cleared_at')->nullable(); $t->timestamps();
        });
    }

    /** An SLA service whose scorecard returns whatever metrics a test hands it. */
    private function slaReturning(array $metrics): SlaService
    {
        $scorecard = new class($metrics) extends SellerScorecardService {
            public function __construct(private array $fixed) { parent::__construct(); }
            public function scorecard(int|string $sellerId): array { return $this->fixed; }
        };

        return new SlaService($scorecard);
    }

    // ---- the policy comparison is pure ----

    public function test_a_rate_above_its_ceiling_is_a_breach(): void
    {
        $sla = new SlaService();
        $t = ['cancellation_rate' => 0.10, 'return_rate' => 0.10, 'refund_rate' => 0.15, 'avg_rating' => 3.5];

        $breaches = $sla->breachesFor(['cancellation_rate' => 0.22, 'return_rate' => 0.0, 'refund_rate' => 0.0, 'review_count' => 0], $t);

        $this->assertCount(1, $breaches);
        $this->assertSame('cancellation_rate', $breaches[0]['metric']);
        $this->assertSame(0.22, $breaches[0]['actual']);
    }

    public function test_everything_within_policy_is_no_breach(): void
    {
        $sla = new SlaService();
        $t = ['cancellation_rate' => 0.10, 'return_rate' => 0.10, 'refund_rate' => 0.15, 'avg_rating' => 3.5];

        $breaches = $sla->breachesFor(['cancellation_rate' => 0.03, 'return_rate' => 0.01, 'refund_rate' => 0.02, 'avg_rating' => 4.6, 'review_count' => 20], $t);

        $this->assertSame([], $breaches);
    }

    public function test_a_low_rating_only_breaches_with_enough_reviews(): void
    {
        $sla = new SlaService();
        $t = ['cancellation_rate' => 0.10, 'return_rate' => 0.10, 'refund_rate' => 0.15, 'avg_rating' => 3.5];

        // 2.0 rating but only 3 reviews — too few to enforce.
        $this->assertSame([], $sla->breachesFor(['avg_rating' => 2.0, 'review_count' => 3], $t));

        // Same rating, 8 reviews — now it breaches the floor.
        $breaches = $sla->breachesFor(['avg_rating' => 2.0, 'review_count' => 8], $t);
        $this->assertCount(1, $breaches);
        $this->assertSame('avg_rating', $breaches[0]['metric']);
    }

    public function test_defaults_apply_when_nothing_is_configured(): void
    {
        $t = (new SlaService())->thresholds();
        $this->assertSame(0.10, $t['cancellation_rate']);
        $this->assertSame(3.5, $t['avg_rating']);
    }

    // ---- the ledger reconcile ----

    public function test_evaluate_opens_a_breach_and_is_idempotent(): void
    {
        $sla = $this->slaReturning(['cancellation_rate' => 0.30, 'return_rate' => 0.0, 'refund_rate' => 0.0, 'review_count' => 0]);

        $sla->evaluate(7);
        $sla->evaluate(7); // running it again must not open a second row for the same line

        $this->assertSame(1, SellerSlaBreach::where('seller_id', 7)->where('metric', 'cancellation_rate')->open()->count());
    }

    public function test_evaluate_clears_a_breach_once_the_seller_recovers(): void
    {
        // First evaluation: out of policy -> a breach opens.
        $this->slaReturning(['cancellation_rate' => 0.30, 'review_count' => 0])->evaluate(7);
        $this->assertSame(1, SellerSlaBreach::where('seller_id', 7)->open()->count());

        // Second evaluation with the metric back in policy -> the breach clears.
        $this->slaReturning(['cancellation_rate' => 0.02, 'review_count' => 0])->evaluate(7);

        $this->assertSame(0, SellerSlaBreach::where('seller_id', 7)->open()->count());
        $cleared = SellerSlaBreach::where('seller_id', 7)->where('status', 'cleared')->first();
        $this->assertNotNull($cleared);
        $this->assertNotNull($cleared->cleared_at);
    }

    public function test_a_missing_table_yields_no_breaches_rather_than_fataling(): void
    {
        Schema::dropIfExists('seller_sla_breaches');

        $this->assertSame([], $this->slaReturning(['cancellation_rate' => 0.9, 'review_count' => 0])->evaluate(7));
    }
}
