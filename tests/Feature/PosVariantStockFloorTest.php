<?php

namespace Tests\Feature;

use App\Services\POSService;
use Tests\TestCase;

/**
 * Regression for the variant-JSON stock floor (stabilization P2).
 *
 * POS decrements the product total atomically with a >= guard, but the per-variant qty inside the
 * `variation` JSON was subtracted with no floor. Because one variant's stock can mask another's
 * shortfall past the product-total check, ordering more of a single variant than it holds persisted a
 * NEGATIVE qty in the JSON. getVariantData now floors the matched variant at zero.
 */
class PosVariantStockFloorTest extends TestCase
{
    public function test_getVariantData_floors_the_short_variant_at_zero(): void
    {
        $variation = [
            ['type' => 'Red-S', 'qty' => 2, 'price' => 10],
            ['type' => 'Blue-M', 'qty' => 5, 'price' => 12],
        ];

        // Order 5 of a variant that only holds 2 -> floored to 0, never negative.
        $out = (new POSService())->getVariantData('Red-S', $variation, 5);

        $this->assertSame(0, $out[0]['qty'], 'the short variant is floored at zero, not negative');
        $this->assertSame(5, $out[1]['qty'], 'other variants are untouched');
    }

    public function test_getVariantData_subtracts_normally_when_stock_is_sufficient(): void
    {
        $variation = [['type' => 'Red-S', 'qty' => 10, 'price' => 10]];

        $out = (new POSService())->getVariantData('Red-S', $variation, 3);

        $this->assertSame(7, $out[0]['qty']);
    }
}
