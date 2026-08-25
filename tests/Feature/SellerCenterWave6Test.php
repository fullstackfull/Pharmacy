<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\BrandClaim;
use App\Models\SellerInsight;
use App\Models\VendorPayoutRequest;
use App\Services\ApprovalEngine;
use App\Services\Marketplace\BrandRegistryService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Wave 6's definition of done: everything the marketplace could act on, read from the seller's side.
 *
 * These screens exist because the platform already holds the record a suspension would rest on and
 * the seller could see none of it. That makes accuracy the whole product — a trust screen that
 * overstates is worse than no screen, because a seller who is told their listings are at risk when
 * they are not will spend a day on it.
 *
 * So the three rules tested here are the three that would be easiest to get quietly wrong:
 *
 *   1. **Exposure is counted in listings, from the seller's own catalogue.** Not in brands, and
 *      never from another shop's products.
 *   2. **An approved claim that has expired stops entitling anybody**, and an unclaimed brand stays
 *      open — turning the registry on must not empty the storefront.
 *   3. **A requester reads only their own approvals**, resolved through their own subjects.
 */
class SellerCenterWave6Test extends TestCase
{
    private const SELLER = 1;
    private const RIVAL = 2;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['brand_claims', 'brands', 'products', 'seller_insights', 'approval_requests', 'vendor_payout_requests', 'business_settings'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('business_settings', function (Blueprint $t) {
            $t->id();
            $t->string('type')->nullable();
            $t->text('value')->nullable();
            $t->timestamps();
        });

        Schema::create('brands', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->timestamps();
        });

        Schema::create('brand_claims', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('brand_id');
            $t->unsignedBigInteger('seller_id');
            $t->string('claim_type', 32)->default(BrandClaim::TYPE_OWNER);
            $t->string('status', 24)->default(BrandClaim::STATUS_DRAFT);
            $t->text('statement')->nullable();
            $t->timestamp('submitted_at')->nullable();
            $t->timestamp('reviewed_at')->nullable();
            $t->timestamp('expires_at')->nullable();
            $t->timestamps();
        });

        Schema::create('products', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->unsignedBigInteger('brand_id')->nullable();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('added_by', 16)->default('seller');
            $t->timestamps();
        });

        Schema::create('seller_insights', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('seller_id');
            $t->string('type', 64)->nullable();
            $t->string('category', 64)->nullable();
            $t->string('severity', 16)->default(SellerInsight::SEVERITY_MEDIUM);
            $t->string('status', 24)->default('open');
            $t->string('title')->nullable();
            $t->text('body')->nullable();
            $t->unsignedInteger('escalation_level')->default(0);
            $t->unsignedInteger('impact_score')->default(0);
            $t->timestamp('expires_at')->nullable();
            $t->timestamp('due_at')->nullable();
            $t->timestamp('first_detected_at')->nullable();
            $t->timestamp('dismissed_at')->nullable();
            $t->timestamp('resolved_at')->nullable();
            $t->timestamps();
        });

        Schema::create('vendor_payout_requests', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('seller_id');
            $t->string('seller_is', 12)->default('seller');
            $t->string('reference', 64)->nullable();
            $t->decimal('amount', 24, 2)->default(0);
            $t->string('status', 24)->default('pending');
            $t->timestamps();
        });

        Schema::create('approval_requests', function (Blueprint $t) {
            $t->id();
            $t->string('reference', 64)->nullable();
            $t->string('workflow', 64)->nullable();
            $t->string('subject_type')->nullable();
            $t->unsignedBigInteger('subject_id')->nullable();
            $t->string('status', 24)->default(ApprovalRequest::STATUS_PENDING);
            $t->decimal('amount', 24, 2)->nullable();
            $t->string('currency', 8)->nullable();
            $t->unsignedInteger('required_approvals')->default(1);
            $t->unsignedInteger('approvals_count')->default(0);
            $t->string('requested_by_type', 24)->nullable();
            $t->unsignedBigInteger('requested_by')->nullable();
            $t->text('request_note')->nullable();
            $t->text('payload')->nullable();
            $t->timestamp('decided_at')->nullable();
            $t->text('decision_note')->nullable();
            $t->timestamps();
        });
    }

    private function brand(string $name): int
    {
        return (int) DB::table('brands')->insertGetId(['name' => $name, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function product(int $brandId, int $sellerId): void
    {
        DB::table('products')->insert([
            'name' => 'p' . $brandId . '-' . $sellerId,
            'brand_id' => $brandId,
            'user_id' => $sellerId,
            'added_by' => 'seller',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function claim(int $brandId, int $sellerId, string $status, ?string $expiresAt = null): BrandClaim
    {
        return BrandClaim::create([
            'brand_id' => $brandId,
            'seller_id' => $sellerId,
            'claim_type' => BrandClaim::TYPE_OWNER,
            'status' => $status,
            'expires_at' => $expiresAt,
        ]);
    }

    private function registry(): BrandRegistryService
    {
        return app(BrandRegistryService::class);
    }

    public function test_an_unclaimed_brand_stays_open_so_turning_the_registry_on_empties_nothing(): void
    {
        $brand = $this->brand('Unclaimed');

        $this->assertTrue($this->registry()->mayList(self::SELLER, $brand));
    }

    public function test_a_brand_somebody_else_holds_is_closed_to_this_shop(): void
    {
        $brand = $this->brand('Held');
        $this->claim($brand, self::RIVAL, BrandClaim::STATUS_APPROVED);

        $this->assertFalse($this->registry()->mayList(self::SELLER, $brand));
        $this->assertTrue($this->registry()->mayList(self::RIVAL, $brand));
    }

    public function test_an_approved_claim_that_expired_last_night_entitles_nobody_this_morning(): void
    {
        $brand = $this->brand('Lapsed');
        $claim = $this->claim($brand, self::RIVAL, BrandClaim::STATUS_APPROVED, now()->subDay()->toDateTimeString());

        $this->assertFalse($claim->entitles());
        // And with nobody entitled, the brand falls back to open rather than closing to everybody.
        $this->assertTrue($this->registry()->mayList(self::SELLER, $brand));
    }

    public function test_exposure_is_counted_in_listings_not_in_brands(): void
    {
        $held = $this->brand('Held');
        $this->claim($held, self::RIVAL, BrandClaim::STATUS_APPROVED);
        $this->product($held, self::SELLER);
        $this->product($held, self::SELLER);
        $this->product($held, self::SELLER);

        $exposure = $this->registry()->brandExposure(self::SELLER);
        $atRisk = collect($exposure)->reject(fn (array $row) => $row['may_list']);

        $this->assertCount(1, $atRisk);
        // One brand, three listings. The figure that matters is the second one.
        $this->assertSame(3, (int) $atRisk->sum('products'));
    }

    public function test_exposure_never_counts_another_shops_listings(): void
    {
        $brand = $this->brand('Shared');
        $this->product($brand, self::SELLER);
        $this->product($brand, self::RIVAL);
        $this->product($brand, self::RIVAL);

        $exposure = collect($this->registry()->brandExposure(self::SELLER));

        $this->assertCount(1, $exposure);
        $this->assertSame(1, (int) $exposure->first()['products']);
    }

    public function test_a_claim_of_this_shops_own_is_reported_alongside_the_exposure_it_covers(): void
    {
        $brand = $this->brand('Mine');
        $this->claim($brand, self::SELLER, BrandClaim::STATUS_UNDER_REVIEW);
        $this->product($brand, self::SELLER);

        $row = collect($this->registry()->brandExposure(self::SELLER))->first();

        $this->assertSame(BrandClaim::STATUS_UNDER_REVIEW, $row['claim_status']);
        // Under review is not approved, and nobody else holds the brand, so listing is still open —
        // the claim status and the permission are two different facts and are reported as two.
        $this->assertTrue($row['may_list']);
    }

    private function insight(array $attributes = []): SellerInsight
    {
        return SellerInsight::create(array_merge([
            'seller_id' => self::SELLER,
            'type' => 'late_dispatch',
            'category' => 'orders',
            'severity' => SellerInsight::SEVERITY_MEDIUM,
            'status' => 'open',
            'title' => 'An order has not moved',
            'escalation_level' => 0,
            'first_detected_at' => now()->subDays(3),
        ], $attributes));
    }

    public function test_only_what_the_platform_promoted_is_an_incident(): void
    {
        $this->insight(['escalation_level' => 0]);
        $escalated = $this->insight(['escalation_level' => 2]);

        $rows = SellerInsight::forSeller(self::SELLER)->escalated()->get();

        $this->assertCount(1, $rows);
        $this->assertSame($escalated->id, $rows->first()->id);
    }

    public function test_a_rival_shops_escalations_are_never_this_shops_incidents(): void
    {
        $this->insight(['seller_id' => self::RIVAL, 'escalation_level' => 3]);

        $this->assertCount(0, SellerInsight::forSeller(self::SELLER)->escalated()->get());
    }

    private function payout(int $sellerId, string $reference): VendorPayoutRequest
    {
        return VendorPayoutRequest::create([
            'seller_id' => $sellerId,
            'seller_is' => 'seller',
            'reference' => $reference,
            'amount' => 900,
            'status' => 'pending',
        ]);
    }

    private function approval(VendorPayoutRequest $payout): ApprovalRequest
    {
        return ApprovalRequest::create([
            'reference' => 'AP-' . $payout->id,
            'workflow' => 'payout',
            'subject_type' => VendorPayoutRequest::class,
            'subject_id' => $payout->id,
            'status' => ApprovalRequest::STATUS_PENDING,
            'amount' => $payout->amount,
            'required_approvals' => 2,
            'approvals_count' => 1,
        ]);
    }

    public function test_a_requester_reads_only_the_approvals_opened_against_their_own_subjects(): void
    {
        $mine = $this->approval($this->payout(self::SELLER, 'P-MINE'));
        $this->approval($this->payout(self::RIVAL, 'P-THEIRS'));

        $ownPayoutIds = VendorPayoutRequest::where('seller_id', self::SELLER)->pluck('id')->all();
        $rows = app(ApprovalEngine::class)->forSubjects(VendorPayoutRequest::class, $ownPayoutIds);

        $this->assertCount(1, $rows);
        $this->assertSame($mine->id, $rows->first()->id);
    }

    public function test_a_shop_with_no_subjects_asks_for_nothing_rather_than_for_everything(): void
    {
        $this->approval($this->payout(self::RIVAL, 'P-THEIRS'));

        // The empty-set guard matters: `whereIn('subject_id', [])` is safe, but an implementation
        // that skipped the filter on an empty list would hand one seller the whole queue.
        $this->assertCount(0, app(ApprovalEngine::class)->forSubjects(VendorPayoutRequest::class, []));
    }
}
