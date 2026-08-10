<?php

namespace Tests\Feature;

use App\Models\BusinessSetting;
use App\Models\SellerVerificationDocument;
use App\Services\Marketplace\SellerVerificationService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Seller KYC verification (Phase 3, Stage A).
 *
 * The two properties that must hold throughout: (1) a seller's standing is *derived* from their
 * documents against a required set, and (2) the payout gate is off until an admin arms it — so an
 * unconfigured store withdraws exactly as it does today. Everything else (verified/pending/rejected
 * transitions, expiry, the missing-required case) is built on those two.
 */
class SellerVerificationTest extends TestCase
{
    private SellerVerificationService $svc;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['seller_verification_documents', 'business_settings'] as $t) {
            Schema::dropIfExists($t);
        }

        Schema::create('seller_verification_documents', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('seller_id');
            $t->string('document_type', 60);
            $t->string('document_number', 120)->nullable();
            $t->string('file_path', 255)->nullable();
            $t->string('status', 20)->default('pending');
            $t->string('rejection_reason', 512)->nullable();
            $t->unsignedBigInteger('reviewed_by')->nullable();
            $t->timestamp('reviewed_at')->nullable();
            $t->date('expires_at')->nullable();
            $t->string('notes', 512)->nullable();
            $t->timestamps();
        });
        Schema::create('business_settings', function (Blueprint $t) {
            $t->id(); $t->string('type')->nullable(); $t->text('value')->nullable(); $t->timestamps();
        });

        // Default: two required documents, gate OFF.
        BusinessSetting::updateOrCreate(['type' => 'kyc_required_documents'], ['value' => 'identity,business_license']);
        BusinessSetting::updateOrCreate(['type' => 'require_kyc_for_payout'], ['value' => '0']);
        cache()->flush();

        $this->svc = new SellerVerificationService();
    }

    private function doc(int $sellerId, string $type, string $status, ?string $expires = null): SellerVerificationDocument
    {
        return SellerVerificationDocument::create([
            'seller_id' => $sellerId, 'document_type' => $type, 'status' => $status, 'expires_at' => $expires,
        ]);
    }

    // ---- the required set ----

    public function test_it_reads_the_required_documents_from_config(): void
    {
        $this->assertEqualsCanonicalizing(['identity', 'business_license'], $this->svc->requiredDocumentTypes());
    }

    public function test_an_empty_required_set_means_nothing_is_required(): void
    {
        BusinessSetting::updateOrCreate(['type' => 'kyc_required_documents'], ['value' => '']);
        cache()->flush();

        $this->assertSame([], $this->svc->requiredDocumentTypes());
        $this->assertSame(SellerVerificationService::STATUS_NOT_REQUIRED, $this->svc->overallStatus(1));
    }

    public function test_it_falls_back_to_the_default_set_when_unconfigured(): void
    {
        BusinessSetting::where('type', 'kyc_required_documents')->delete();
        cache()->flush();

        $this->assertEqualsCanonicalizing(
            SellerVerificationService::DEFAULT_REQUIRED_DOCUMENTS,
            $this->svc->requiredDocumentTypes()
        );
    }

    // ---- overall status is derived from documents ----

    public function test_a_seller_with_nothing_submitted_is_unverified(): void
    {
        $this->assertSame(SellerVerificationService::STATUS_UNVERIFIED, $this->svc->overallStatus(7));
    }

    public function test_a_seller_is_pending_while_documents_are_under_review(): void
    {
        $this->doc(7, 'identity', 'pending');
        $this->doc(7, 'business_license', 'pending');

        $this->assertSame(SellerVerificationService::STATUS_PENDING, $this->svc->overallStatus(7));
    }

    public function test_a_seller_is_verified_only_when_every_required_type_is_approved(): void
    {
        $this->doc(7, 'identity', 'approved');
        $this->assertSame(SellerVerificationService::STATUS_UNVERIFIED, $this->svc->overallStatus(7),
            'one approved but business_license not submitted at all -> an outstanding requirement, not under review');

        $this->doc(7, 'business_license', 'approved');
        $this->assertSame(SellerVerificationService::STATUS_VERIFIED, $this->svc->overallStatus(7));
    }

    public function test_a_missing_required_type_keeps_the_seller_unverified_even_with_another_approved(): void
    {
        $this->doc(7, 'identity', 'approved');
        // business_license neither submitted nor pending -> the seller has an outstanding requirement.
        $this->assertSame(SellerVerificationService::STATUS_UNVERIFIED, $this->svc->overallStatus(7));
    }

    public function test_a_rejected_required_document_makes_the_seller_rejected(): void
    {
        $this->doc(7, 'identity', 'approved');
        $this->doc(7, 'business_license', 'rejected');

        $this->assertSame(SellerVerificationService::STATUS_REJECTED, $this->svc->overallStatus(7));
    }

    public function test_a_newer_approval_supersedes_an_older_rejection_of_the_same_type(): void
    {
        $this->doc(7, 'identity', 'approved');
        $old = $this->doc(7, 'business_license', 'rejected');
        $old->created_at = Carbon::now()->subDays(2); $old->save();
        $this->doc(7, 'business_license', 'approved'); // newer, and approved

        $this->assertSame(SellerVerificationService::STATUS_VERIFIED, $this->svc->overallStatus(7),
            'any approved document of a type satisfies it, regardless of an older rejection');
    }

    public function test_an_expired_approval_no_longer_satisfies_its_requirement(): void
    {
        $this->doc(7, 'identity', 'approved');
        $this->doc(7, 'business_license', 'approved', Carbon::now()->subDay()->toDateString());

        $this->assertNotSame(SellerVerificationService::STATUS_VERIFIED, $this->svc->overallStatus(7),
            'an approved-but-expired licence must not count as verified');
    }

    public function test_a_future_expiry_still_counts_as_approved(): void
    {
        $this->doc(7, 'identity', 'approved');
        $this->doc(7, 'business_license', 'approved', Carbon::now()->addYear()->toDateString());

        $this->assertSame(SellerVerificationService::STATUS_VERIFIED, $this->svc->overallStatus(7));
    }

    // ---- the payout gate: off by default, and only bites the unverified when armed ----

    public function test_payout_is_eligible_by_default_because_the_gate_is_off(): void
    {
        // Gate off (default). Even a seller with nothing submitted can withdraw — behaviour today.
        $this->assertFalse($this->svc->isKycRequiredForPayout());
        $this->assertTrue($this->svc->isPayoutEligible(7));
    }

    public function test_an_armed_gate_blocks_an_unverified_seller(): void
    {
        BusinessSetting::updateOrCreate(['type' => 'require_kyc_for_payout'], ['value' => '1']);
        cache()->flush();

        $this->assertTrue($this->svc->isKycRequiredForPayout());
        $this->assertFalse($this->svc->isPayoutEligible(7), 'unverified seller cannot withdraw once the gate is on');
    }

    public function test_an_armed_gate_allows_a_verified_seller(): void
    {
        BusinessSetting::updateOrCreate(['type' => 'require_kyc_for_payout'], ['value' => '1']);
        cache()->flush();
        $this->doc(7, 'identity', 'approved');
        $this->doc(7, 'business_license', 'approved');

        $this->assertTrue($this->svc->isPayoutEligible(7));
    }

    // ---- write paths ----

    public function test_submit_creates_a_pending_document(): void
    {
        $doc = $this->svc->submit(7, 'identity', 'ID-123', 'file.pdf');

        $this->assertSame('pending', $doc->status);
        $this->assertSame('ID-123', $doc->document_number);
        $this->assertDatabaseHas('seller_verification_documents', ['seller_id' => 7, 'document_type' => 'identity', 'status' => 'pending']);
    }

    public function test_approve_and_reject_move_a_document_through_its_lifecycle(): void
    {
        $doc = $this->svc->submit(7, 'identity');
        $this->svc->approve($doc, 99, Carbon::now()->addYear()->toDateString());
        $this->assertSame('approved', $doc->fresh()->status);
        $this->assertSame(99, $doc->fresh()->reviewed_by);

        $doc2 = $this->svc->submit(7, 'business_license');
        $this->svc->reject($doc2, 99, 'blurry scan');
        $this->assertSame('rejected', $doc2->fresh()->status);
        $this->assertSame('blurry scan', $doc2->fresh()->rejection_reason);
    }

    public function test_missing_table_is_treated_as_no_documents_rather_than_fataling(): void
    {
        Schema::dropIfExists('seller_verification_documents');

        $this->assertTrue($this->svc->documentsFor(7)->isEmpty());
        // required set still resolves, so a seller with no reachable documents is unverified, not a 500.
        $this->assertSame(SellerVerificationService::STATUS_UNVERIFIED, $this->svc->overallStatus(7));
    }
}
