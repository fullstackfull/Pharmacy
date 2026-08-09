<?php

namespace Tests\Feature;

use App\Models\ShippingZone;
use App\Services\Marketplace\ShippingRateService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Destination-based shipping zones (Phase 3, Stage C).
 *
 * The resolver's contract: a country-specific zone beats a catch-all, priority breaks ties, a
 * region-scoped zone only matches its regions, and — the non-breaking guarantee — an unmatched
 * destination returns null so the caller falls back to the existing flat shipping. The rate itself is
 * base + per-kg, or free above a threshold.
 */
class ShippingZoneTest extends TestCase
{
    private ShippingRateService $svc;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('shipping_zones');
        Schema::create('shipping_zones', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->json('countries')->nullable(); $t->json('regions')->nullable();
            $t->decimal('base_cost', 24, 2)->default(0); $t->decimal('per_kg_cost', 24, 2)->default(0); $t->decimal('free_over', 24, 2)->nullable();
            $t->unsignedInteger('priority')->default(10); $t->string('status')->default('active'); $t->unsignedBigInteger('created_by')->nullable(); $t->timestamps();
        });

        $this->svc = new ShippingRateService();
    }

    public function test_a_country_specific_zone_beats_a_catch_all(): void
    {
        ShippingZone::create(['name' => 'Rest of world', 'countries' => [], 'base_cost' => 20, 'priority' => 99]);
        ShippingZone::create(['name' => 'Syria', 'countries' => ['Syria'], 'base_cost' => 5, 'priority' => 10]);

        $zone = $this->svc->resolveZone('Syria');

        $this->assertNotNull($zone);
        $this->assertSame('Syria', $zone->name);
    }

    public function test_a_catch_all_serves_a_country_with_no_specific_zone(): void
    {
        ShippingZone::create(['name' => 'Rest of world', 'countries' => [], 'base_cost' => 20, 'priority' => 99]);
        ShippingZone::create(['name' => 'Syria', 'countries' => ['Syria'], 'base_cost' => 5]);

        $zone = $this->svc->resolveZone('Jordan');

        $this->assertSame('Rest of world', $zone->name);
    }

    public function test_no_zone_and_no_catch_all_returns_null_so_the_caller_falls_back(): void
    {
        ShippingZone::create(['name' => 'Syria', 'countries' => ['Syria'], 'base_cost' => 5]);

        $this->assertNull($this->svc->resolveZone('Jordan'));
        $this->assertNull($this->svc->rateFor('Jordan', 2.0, 50.0), 'null lets the caller use existing flat shipping');
    }

    public function test_priority_breaks_a_tie_between_specific_zones(): void
    {
        ShippingZone::create(['name' => 'Syria B', 'countries' => ['Syria'], 'base_cost' => 8, 'priority' => 20]);
        ShippingZone::create(['name' => 'Syria A', 'countries' => ['Syria'], 'base_cost' => 5, 'priority' => 5]);

        $this->assertSame('Syria A', $this->svc->resolveZone('Syria')->name);
    }

    public function test_a_region_scoped_zone_only_matches_its_regions(): void
    {
        ShippingZone::create(['name' => 'Coast', 'countries' => ['Syria'], 'regions' => ['Latakia', 'Tartus'], 'base_cost' => 4, 'priority' => 5]);
        ShippingZone::create(['name' => 'Syria general', 'countries' => ['Syria'], 'base_cost' => 8, 'priority' => 10]);

        $this->assertSame('Coast', $this->svc->resolveZone('Syria', 'Latakia')->name, 'region-scoped wins for its region');
        $this->assertSame('Syria general', $this->svc->resolveZone('Syria', 'Damascus')->name, 'other regions fall to the general zone');
    }

    public function test_the_rate_is_base_plus_per_kg(): void
    {
        ShippingZone::create(['name' => 'Syria', 'countries' => ['Syria'], 'base_cost' => 5, 'per_kg_cost' => 2]);

        $rate = $this->svc->rateFor('Syria', 3.0, 40.0);

        $this->assertFalse($rate['free']);
        $this->assertSame(11.0, $rate['cost'], '5 + 2*3');
    }

    public function test_shipping_is_free_over_the_threshold(): void
    {
        ShippingZone::create(['name' => 'Syria', 'countries' => ['Syria'], 'base_cost' => 5, 'per_kg_cost' => 2, 'free_over' => 100]);

        $under = $this->svc->rateFor('Syria', 3.0, 50.0);
        $over = $this->svc->rateFor('Syria', 3.0, 120.0);

        $this->assertFalse($under['free']);
        $this->assertSame(11.0, $under['cost']);
        $this->assertTrue($over['free']);
        $this->assertSame(0.0, $over['cost']);
    }

    public function test_an_inactive_zone_is_ignored(): void
    {
        ShippingZone::create(['name' => 'Syria', 'countries' => ['Syria'], 'base_cost' => 5, 'status' => 'inactive']);

        $this->assertNull($this->svc->resolveZone('Syria'));
    }
}
