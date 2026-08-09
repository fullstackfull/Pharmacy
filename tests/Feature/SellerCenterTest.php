<?php

namespace Tests\Feature;

use App\Models\SellerSlaBreach;
use App\Services\Marketplace\SellerCenterService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Seller Center hub (Phase 3, Stage A).
 *
 * The Center composes the seller-facing services; these assert it assembles the four sections without
 * re-deriving anything, that it degrades to sensible defaults on an install missing the newer tables,
 * and that a seeded SLA breach surfaces through it — proving the composition is live, not stubbed.
 */
class SellerCenterTest extends TestCase
{
    private function service(): SellerCenterService
    {
        return app(SellerCenterService::class);
    }

    public function test_the_overview_assembles_four_sections(): void
    {
        // No marketplace tables present — every sub-service must degrade, not fatal.
        foreach (['seller_sla_breaches', 'vendor_ledger_entries', 'seller_verification_documents'] as $t) {
            Schema::dropIfExists($t);
        }

        $overview = $this->service()->overview(7);

        $this->assertArrayHasKey('verification', $overview);
        $this->assertArrayHasKey('performance', $overview);
        $this->assertArrayHasKey('finance', $overview);
        $this->assertArrayHasKey('sla', $overview);
        $this->assertSame(0, $overview['sla']['open_breach_count']);
        $this->assertArrayHasKey('withdrawable', $overview['finance']);
    }

    public function test_an_open_sla_breach_surfaces_through_the_center(): void
    {
        Schema::dropIfExists('seller_sla_breaches');
        Schema::create('seller_sla_breaches', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('seller_id'); $t->string('metric', 40);
            $t->decimal('threshold', 12, 4)->default(0); $t->decimal('actual_value', 12, 4)->default(0);
            $t->string('status', 12)->default('open'); $t->timestamp('cleared_at')->nullable(); $t->timestamps();
        });
        DB::table('seller_sla_breaches')->insert([
            'seller_id' => 7, 'metric' => 'cancellation_rate', 'threshold' => 0.1, 'actual_value' => 0.3, 'status' => 'open',
        ]);

        $overview = $this->service()->overview(7);

        $this->assertSame(1, $overview['sla']['open_breach_count']);
        $this->assertSame('cancellation_rate', $overview['sla']['open_breaches'][0]->metric);
    }
}
