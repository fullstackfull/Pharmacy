<?php

namespace Tests\Feature;

use App\Models\BrandClaim;
use App\Models\BrandClaimDocument;
use App\Models\Seller;
use App\Services\Marketplace\BrandRegistryService;
use App\Services\Marketplace\SellerPrincipal;
use App\Services\SellerIntelligence\Producers\BrandComplianceProducer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Who may sell under a brand.
 *
 * Two failures the registry exists to prevent, and one it must not cause.
 *
 * It must stop a second shop listing under a name somebody has proved they own — that is the point.
 * It must not sweep up a legitimate authorised reseller, which is why a claim has a type and expiry
 * and why an approval is a person's decision rather than a computation. And it must not empty the
 * storefront on the day it ships: nine brands exist in this marketplace and none is claimed by
 * anybody, so a gate that refused everything unclaimed would take a working shop offline.
 *
 * The last of those is why enforcement is a switch that starts off, and why most of what is asserted
 * here is about what the registry declines to do.
 */
class BrandRegistryTest extends TestCase
{
    private const SELLER = 1;
    private const RIVAL = 2;
    private const BRAND = 10;
    private const OTHER_BRAND = 11;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'brand_claim_documents', 'brand_claims', 'brands', 'products',
            'sellers', 'business_settings', 'audit_logs', 'seller_insights',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('business_settings', function (Blueprint $table) {
            $table->id();
            $table->string('type')->nullable();
            $table->text('value')->nullable();
            $table->timestamps();
        });
        Schema::create('sellers', function (Blueprint $table) {
            $table->id();
            $table->string('f_name')->nullable();
            $table->string('l_name')->nullable();
            $table->string('status', 20)->default('approved');
            $table->timestamps();
        });
        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('slug')->nullable();
            $table->integer('status')->default(1);
            $table->timestamps();
        });
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('added_by', 20)->default('seller');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('brand_id')->nullable();
            $table->string('name')->nullable();
            $table->timestamps();
        });
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('actor_type', 30)->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_name', 191)->nullable();
            $table->string('action', 60);
            $table->string('subject_type', 60)->nullable();
            $table->string('subject_id', 60)->nullable();
            $table->text('before')->nullable();
            $table->text('after')->nullable();
            $table->text('context')->nullable();
            $table->string('ip_address', 60)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();
        });

        (require base_path('database/migrations/2026_09_13_000001_create_brand_claims_tables.php'))->up();

        Seller::insert([
            ['id' => self::SELLER, 'f_name' => 'Owner', 'status' => 'approved'],
            ['id' => self::RIVAL, 'f_name' => 'Rival', 'status' => 'approved'],
        ]);
        DB::table('brands')->insert([
            ['id' => self::BRAND, 'name' => 'Medi', 'slug' => 'medi', 'status' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => self::OTHER_BRAND, 'name' => 'Other', 'slug' => 'other', 'status' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);

        Storage::fake('local');
    }

    private function registry(): BrandRegistryService
    {
        return app(BrandRegistryService::class);
    }

    private function principal(int $sellerId = self::SELLER): SellerPrincipal
    {
        return SellerPrincipal::owner(Seller::find($sellerId));
    }

    private function approvedClaim(int $sellerId, int $brandId = self::BRAND, ?string $expiresAt = null): BrandClaim
    {
        $claim = BrandClaim::create([
            'seller_id' => $sellerId,
            'brand_id' => $brandId,
            'claim_type' => BrandClaim::TYPE_OWNER,
            'status' => BrandClaim::STATUS_APPROVED,
        ]);

        if ($expiresAt) {
            $claim->forceFill(['expires_at' => $expiresAt])->save();
        }

        return $claim->refresh();
    }

    private function product(int $sellerId, ?int $brandId): void
    {
        DB::table('products')->insert([
            'added_by' => 'seller', 'user_id' => $sellerId, 'brand_id' => $brandId,
            'name' => 'A product', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function enforce(bool $on): void
    {
        DB::table('business_settings')->updateOrInsert(
            ['type' => BrandRegistryService::ENFORCEMENT_SETTING],
            ['value' => $on ? '1' : '0', 'created_at' => now(), 'updated_at' => now()],
        );
    }

    public function test_a_brand_nobody_has_claimed_stays_open_to_everybody(): void
    {
        // The state this marketplace is actually in. A registry that closed everything on the day it
        // shipped would take a working storefront offline.
        $this->assertTrue($this->registry()->mayList(self::SELLER, self::BRAND));
        $this->assertTrue($this->registry()->mayList(self::RIVAL, self::BRAND));
    }

    public function test_a_product_with_no_brand_is_never_gated(): void
    {
        $this->approvedClaim(self::RIVAL);

        $this->assertTrue($this->registry()->mayList(self::SELLER, null));
    }

    public function test_a_claimed_brand_is_closed_to_everybody_else(): void
    {
        $this->approvedClaim(self::SELLER);

        $this->assertTrue($this->registry()->mayList(self::SELLER, self::BRAND));
        $this->assertFalse($this->registry()->mayList(self::RIVAL, self::BRAND));
        // And only that brand: claiming one name does not claim the shelf.
        $this->assertTrue($this->registry()->mayList(self::RIVAL, self::OTHER_BRAND));
    }

    public function test_an_authority_that_has_run_out_stops_entitling_anybody(): void
    {
        $this->approvedClaim(self::SELLER, expiresAt: now()->subDay()->toDateTimeString());

        // Not merely "the holder loses it": the brand goes back to being unclaimed, because a lapsed
        // letter of authority is not evidence that somebody else owns the name.
        $this->assertTrue($this->registry()->mayList(self::SELLER, self::BRAND));
        $this->assertTrue($this->registry()->mayList(self::RIVAL, self::BRAND));
    }

    public function test_a_claim_that_is_only_submitted_entitles_nobody(): void
    {
        BrandClaim::create([
            'seller_id' => self::SELLER, 'brand_id' => self::BRAND,
            'claim_type' => BrandClaim::TYPE_OWNER, 'status' => BrandClaim::STATUS_SUBMITTED,
        ]);

        // Uploading paperwork is not the same as somebody having read it.
        $this->assertTrue($this->registry()->mayList(self::RIVAL, self::BRAND));
    }

    public function test_a_revoked_claim_stops_entitling_the_seller_who_held_it(): void
    {
        $claim = $this->approvedClaim(self::SELLER);
        $this->registry()->revoke($claim, reviewer: 1, note: 'Authority withdrawn');

        $this->assertFalse($claim->fresh()->entitles());
        $this->assertTrue($this->registry()->mayList(self::RIVAL, self::BRAND));
    }

    public function test_revocation_is_recorded_as_different_from_rejection(): void
    {
        $claim = $this->approvedClaim(self::SELLER);
        $this->registry()->revoke($claim, reviewer: 1);

        // "We agreed and no longer do" is a different fact from "we never agreed", and a seller
        // reading their own history is entitled to the difference.
        $this->assertSame(BrandClaim::STATUS_REVOKED, $claim->fresh()->status);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'marketplace.brand_claim_revoked')->count());
    }

    public function test_a_claim_that_was_never_approved_cannot_be_revoked(): void
    {
        $claim = BrandClaim::create([
            'seller_id' => self::SELLER, 'brand_id' => self::BRAND,
            'claim_type' => BrandClaim::TYPE_OWNER, 'status' => BrandClaim::STATUS_SUBMITTED,
        ]);

        $result = $this->registry()->revoke($claim, reviewer: 1);

        $this->assertFalse($result['ok']);
        $this->assertSame(BrandClaim::STATUS_SUBMITTED, $claim->fresh()->status);
    }

    public function test_a_claim_with_nothing_behind_it_cannot_be_submitted(): void
    {
        $draft = $this->registry()->draft(self::SELLER, self::BRAND, BrandClaim::TYPE_OWNER, 'We own it');

        $result = $this->registry()->submit($draft['claim'], $this->principal());

        // A queue full of empty forms is how the claims with real documents in them stop being read.
        $this->assertFalse($result['ok']);
        $this->assertSame('brand_claim_needs_evidence', $result['reason']);
        $this->assertSame(BrandClaim::STATUS_DRAFT, $draft['claim']->fresh()->status);
    }

    public function test_a_claim_with_evidence_reaches_the_marketplace(): void
    {
        $draft = $this->registry()->draft(self::SELLER, self::BRAND, BrandClaim::TYPE_OWNER, 'We own it');
        $this->registry()->attachDocument(
            $draft['claim'],
            UploadedFile::fake()->create('trademark.pdf', 12),
            BrandClaimDocument::TYPE_TRADEMARK_CERTIFICATE,
        );

        $result = $this->registry()->submit($draft['claim']->refresh(), $this->principal());

        $this->assertTrue($result['ok']);
        $this->assertSame(BrandClaim::STATUS_SUBMITTED, $draft['claim']->fresh()->status);
        $this->assertNotNull($draft['claim']->fresh()->submitted_at);
    }

    public function test_a_claim_on_the_marketplaces_desk_cannot_be_rewritten_underneath_it(): void
    {
        $draft = $this->registry()->draft(self::SELLER, self::BRAND, BrandClaim::TYPE_OWNER, 'First words');
        $this->registry()->attachDocument(
            $draft['claim'],
            UploadedFile::fake()->create('trademark.pdf', 12),
            BrandClaimDocument::TYPE_TRADEMARK_CERTIFICATE,
        );
        $this->registry()->submit($draft['claim']->refresh(), $this->principal());

        $again = $this->registry()->draft(self::SELLER, self::BRAND, BrandClaim::TYPE_DISTRIBUTOR, 'Different words');

        $this->assertFalse($again['ok']);
        $this->assertSame('First words', $draft['claim']->fresh()->statement);
    }

    public function test_rewriting_a_rejected_claim_clears_the_decision_it_earned(): void
    {
        $draft = $this->registry()->draft(self::SELLER, self::BRAND, BrandClaim::TYPE_OWNER, 'First try');
        $this->registry()->reject($draft['claim'], reviewer: 1, note: 'Not enough');

        $again = $this->registry()->draft(self::SELLER, self::BRAND, BrandClaim::TYPE_AUTHORIZED_RESELLER, 'Second try');

        $this->assertTrue($again['ok']);
        // The previous decision was about different words and possibly different documents.
        $this->assertSame(BrandClaim::STATUS_DRAFT, $again['claim']->status);
        $this->assertNull($again['claim']->review_note);
        $this->assertNull($again['claim']->reviewed_at);
    }

    public function test_a_seller_gets_one_claim_per_brand_not_a_second_opinion(): void
    {
        $this->registry()->draft(self::SELLER, self::BRAND, BrandClaim::TYPE_OWNER, 'First');
        $this->registry()->draft(self::SELLER, self::BRAND, BrandClaim::TYPE_DISTRIBUTOR, 'Second');

        $this->assertSame(1, BrandClaim::where(['seller_id' => self::SELLER, 'brand_id' => self::BRAND])->count());
        $this->assertSame(BrandClaim::TYPE_DISTRIBUTOR, BrandClaim::first()->claim_type);
    }

    public function test_evidence_is_stored_privately_under_a_name_the_client_did_not_choose(): void
    {
        $draft = $this->registry()->draft(self::SELLER, self::BRAND, BrandClaim::TYPE_OWNER, null);

        $this->registry()->attachDocument(
            $draft['claim'],
            UploadedFile::fake()->create('../../etc/passwd.php', 12),
            BrandClaimDocument::TYPE_OTHER,
        );

        $document = BrandClaimDocument::first();

        // The extension is mapped onto a server-controlled whitelist, so an upload cannot smuggle an
        // executable one, and the stored name carries nothing the client supplied.
        $this->assertStringEndsWith('.pdf', $document->file_path);
        $this->assertStringNotContainsString('passwd', $document->file_path);
        Storage::disk('local')->assertExists('seller/brand-claims/' . $document->file_path);
    }

    public function test_evidence_cannot_be_added_to_a_claim_already_under_review(): void
    {
        $draft = $this->registry()->draft(self::SELLER, self::BRAND, BrandClaim::TYPE_OWNER, null);
        $this->registry()->attachDocument(
            $draft['claim'],
            UploadedFile::fake()->create('a.pdf', 12),
            BrandClaimDocument::TYPE_TRADEMARK_CERTIFICATE,
        );
        $this->registry()->submit($draft['claim']->refresh(), $this->principal());

        $result = $this->registry()->attachDocument(
            $draft['claim']->refresh(),
            UploadedFile::fake()->create('b.pdf', 12),
            BrandClaimDocument::TYPE_INVOICE,
        );

        $this->assertFalse($result['ok']);
        $this->assertSame(1, BrandClaimDocument::count());
    }

    public function test_exposure_counts_the_sellers_own_catalogue_not_the_claims_table(): void
    {
        $this->product(self::SELLER, self::BRAND);
        $this->product(self::SELLER, self::BRAND);
        $this->product(self::SELLER, null);
        $this->product(self::RIVAL, self::BRAND);

        $exposure = $this->registry()->brandExposure(self::SELLER);

        $this->assertCount(1, $exposure);
        $this->assertSame(self::BRAND, $exposure[0]['brand_id']);
        // Two, not three and not four: unbranded listings are not exposure and another shop's are
        // not this shop's.
        $this->assertSame(2, $exposure[0]['products']);
        $this->assertNull($exposure[0]['claim_status']);
        $this->assertTrue($exposure[0]['may_list']);
    }

    public function test_enforcement_is_off_until_somebody_turns_it_on(): void
    {
        $this->assertFalse($this->registry()->isEnforcing());

        $this->enforce(true);

        $this->assertTrue($this->registry()->isEnforcing());
    }

    public function test_the_detector_says_nothing_about_a_brand_nobody_has_claimed(): void
    {
        $this->product(self::SELLER, self::BRAND);

        $drafts = iterator_to_array(app(BrandComplianceProducer::class)->produce(self::SELLER));

        // Inventing a compliance problem for an unclaimed brand would be exactly the fabricated
        // finding the brief rules out.
        $this->assertSame([], $drafts);
    }

    public function test_the_detector_reports_listings_under_somebody_elses_brand(): void
    {
        $this->approvedClaim(self::RIVAL);
        $this->product(self::SELLER, self::BRAND);
        $this->product(self::SELLER, self::BRAND);

        $drafts = iterator_to_array(app(BrandComplianceProducer::class)->produce(self::SELLER));

        $this->assertCount(1, $drafts);
        $this->assertSame('insight_brand_not_claimed', $drafts[0]->title);
        $this->assertSame(2, $drafts[0]->affectedCount);
        // Not measured against the shop's size — it is the same problem in a shop of two and a shop
        // of two hundred.
        $this->assertSame('high', $drafts[0]->signals->severityFloor);
    }

    public function test_the_detector_says_something_different_once_listings_are_actually_blocked(): void
    {
        $this->approvedClaim(self::RIVAL);
        $this->product(self::SELLER, self::BRAND);
        $this->enforce(true);

        $drafts = iterator_to_array(app(BrandComplianceProducer::class)->produce(self::SELLER));

        // "You will need paperwork" and "your listings are refused" are different problems and the
        // seller can only act on one of them the same way.
        $this->assertSame('insight_brand_listings_blocked', $drafts[0]->title);
        $this->assertSame('critical', $drafts[0]->signals->severityFloor);
    }

    public function test_approving_one_claim_does_not_silently_revoke_another(): void
    {
        $first = $this->approvedClaim(self::RIVAL);
        $second = BrandClaim::create([
            'seller_id' => self::SELLER, 'brand_id' => self::BRAND,
            'claim_type' => BrandClaim::TYPE_DISTRIBUTOR, 'status' => BrandClaim::STATUS_SUBMITTED,
        ]);

        $result = $this->registry()->approve($second, reviewer: 1);

        // A brand owner and their distributor is a real arrangement. Revoking one behind the
        // reviewer's back would make a review a two-party decision taken with one party's paperwork.
        $this->assertSame(BrandClaim::STATUS_APPROVED, $first->fresh()->status);
        $this->assertSame([self::RIVAL], $result['conflicts']);
        $this->assertTrue($this->registry()->mayList(self::SELLER, self::BRAND));
        $this->assertTrue($this->registry()->mayList(self::RIVAL, self::BRAND));
    }

    public function test_an_unknown_claim_type_is_refused_rather_than_stored(): void
    {
        $result = $this->registry()->draft(self::SELLER, self::BRAND, 'sole_ruler_of_all_brands', null);

        $this->assertFalse($result['ok']);
        $this->assertSame(0, BrandClaim::count());
    }
}
