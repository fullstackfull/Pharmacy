<?php

namespace Tests\Feature;

use App\Services\DeliverySyria\DeliverySyriaStatus;
use Tests\TestCase;

/**
 * The courier status vocabulary and its safe mapping onto the platform order_status.
 *
 * The load-bearing assertion is isFinancialTransition(): the webhook relies on it to know which courier
 * states it may reflect into order_status (the side-effect-free intermediate ones) and which it must
 * only record (delivered/canceled, which would move money and stock). If that line moves, an external
 * call could mature earnings or restock inventory — so it is pinned here.
 */
class DeliverySyriaStatusTest extends TestCase
{
    public function test_each_code_has_the_documented_label(): void
    {
        $this->assertSame('Pending', DeliverySyriaStatus::label(0));
        $this->assertSame('Delivered', DeliverySyriaStatus::label(1));
        $this->assertSame('Canceled', DeliverySyriaStatus::label(2));
        $this->assertSame('In Transit', DeliverySyriaStatus::label(3));
        $this->assertSame('Confirmed', DeliverySyriaStatus::label(4));
        $this->assertSame('Picked Up', DeliverySyriaStatus::label(5));
        $this->assertSame('Shipped', DeliverySyriaStatus::label(6));
        $this->assertSame('Unknown', DeliverySyriaStatus::label(99));
    }

    public function test_codes_map_onto_the_real_order_status_vocabulary(): void
    {
        $this->assertSame('pending', DeliverySyriaStatus::toOrderStatus(0));
        $this->assertSame('confirmed', DeliverySyriaStatus::toOrderStatus(4));
        $this->assertSame('processing', DeliverySyriaStatus::toOrderStatus(5));
        $this->assertSame('out_for_delivery', DeliverySyriaStatus::toOrderStatus(6));
        $this->assertSame('out_for_delivery', DeliverySyriaStatus::toOrderStatus(3));
        $this->assertSame('delivered', DeliverySyriaStatus::toOrderStatus(1));
        $this->assertSame('canceled', DeliverySyriaStatus::toOrderStatus(2));
    }

    public function test_only_delivered_and_canceled_are_financial_transitions(): void
    {
        // Intermediate courier states are safe to reflect into order_status.
        foreach ([0, 3, 4, 5, 6] as $intermediate) {
            $this->assertFalse(DeliverySyriaStatus::isFinancialTransition($intermediate), "code $intermediate must be safe");
        }

        // Delivered and canceled must never be applied by the webhook.
        $this->assertTrue(DeliverySyriaStatus::isFinancialTransition(1));
        $this->assertTrue(DeliverySyriaStatus::isFinancialTransition(2));
    }

    public function test_validity_check(): void
    {
        foreach (range(0, 6) as $code) {
            $this->assertTrue(DeliverySyriaStatus::isValid($code));
        }
        $this->assertFalse(DeliverySyriaStatus::isValid(7));
        $this->assertFalse(DeliverySyriaStatus::isValid(-1));
    }
}
