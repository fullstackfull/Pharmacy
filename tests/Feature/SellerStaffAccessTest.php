<?php

namespace Tests\Feature;

use App\Http\Middleware\SellerStaffAccessMiddleware;
use App\Services\Marketplace\SellerPermissionService;
use Illuminate\Http\Request;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Seller staff access enforcement (Phase 3, Stage A).
 *
 * The security-critical surface here is the URL → permission map: which vendor area demands which
 * catalog permission of a staff member. These assert it deterministically, including its two guarantees
 * — navigation is allowed, and anything unmapped is **denied** (fails closed). The map must only ever
 * name real catalog keys, so a role that was granted a key actually unlocks the matching area.
 */
class SellerStaffAccessTest extends TestCase
{
    private function requiredPermissionFor(string $method, string $path): string
    {
        $middleware = new SellerStaffAccessMiddleware(new SellerPermissionService());
        $ref = new ReflectionMethod($middleware, 'requiredPermission');
        $ref->setAccessible(true);

        return $ref->invoke($middleware, Request::create($path, $method));
    }

    public function test_navigation_areas_are_allowed_to_any_staff(): void
    {
        foreach (['/vendor/dashboard', '/vendor/profile', '/vendor/notification', '/vendor/messages/list', '/vendor/seller-center'] as $path) {
            $this->assertSame('__allow__', $this->requiredPermissionFor('GET', $path), $path);
        }
    }

    public function test_products_read_needs_view_and_write_needs_manage(): void
    {
        $this->assertSame('products.view', $this->requiredPermissionFor('GET', '/vendor/products/list'));
        $this->assertSame('products.manage', $this->requiredPermissionFor('POST', '/vendor/products/add'));
        $this->assertSame('products.manage', $this->requiredPermissionFor('DELETE', '/vendor/products/delete/5'));
    }

    public function test_orders_read_needs_view_and_acting_needs_manage(): void
    {
        $this->assertSame('orders.view', $this->requiredPermissionFor('GET', '/vendor/orders/list'));
        $this->assertSame('orders.manage', $this->requiredPermissionFor('POST', '/vendor/orders/status'));
        $this->assertSame('orders.manage', $this->requiredPermissionFor('GET', '/vendor/pos/index'));
        $this->assertSame('orders.manage', $this->requiredPermissionFor('POST', '/vendor/refund/approve/1'));
    }

    public function test_settings_area_splits_staff_management_from_shop_settings(): void
    {
        $this->assertSame('staff.manage', $this->requiredPermissionFor('GET', '/vendor/business-settings/staff'));
        $this->assertSame('shop_settings.manage', $this->requiredPermissionFor('GET', '/vendor/business-settings/shop'));
    }

    public function test_promotions_finance_and_reviews_map_to_their_keys(): void
    {
        $this->assertSame('promotions.manage', $this->requiredPermissionFor('POST', '/vendor/coupon/add'));
        $this->assertSame('promotions.manage', $this->requiredPermissionFor('GET', '/vendor/clearance-sale/list'));
        $this->assertSame('finance.view', $this->requiredPermissionFor('GET', '/vendor/transaction/statement'));
        $this->assertSame('reviews.view', $this->requiredPermissionFor('GET', '/vendor/reviews/list'));
    }

    public function test_an_unmapped_area_is_denied_fails_closed(): void
    {
        $this->assertSame('__deny__', $this->requiredPermissionFor('GET', '/vendor/some-future-area/thing'));
        $this->assertSame('__deny__', $this->requiredPermissionFor('POST', '/vendor/auction/create'));
    }

    public function test_money_movement_paths_need_the_finance_permission_wherever_they_live(): void
    {
        // These sit under ALLOW (dashboard/shop) or shop_settings (business-settings) segments, but must
        // still demand payouts.request — otherwise a staffer with no finance rights could move funds.
        $this->assertSame('payouts.request', $this->requiredPermissionFor('POST', '/vendor/dashboard/withdraw-request'));
        $this->assertSame('payouts.request', $this->requiredPermissionFor('POST', '/vendor/dashboard/withdraw-request-update'));
        $this->assertSame('payouts.request', $this->requiredPermissionFor('POST', '/vendor/shop/payment-information/add'));
        $this->assertSame('payouts.request', $this->requiredPermissionFor('GET', '/vendor/shop/payment-information/edit/5'));
        $this->assertSame('payouts.request', $this->requiredPermissionFor('POST', '/vendor/business-settings/payouts'));
        $this->assertSame('payouts.request', $this->requiredPermissionFor('GET', '/vendor/business-settings/withdraw/close/5'));
    }

    public function test_plain_dashboard_and_shop_navigation_stay_allowed(): void
    {
        // The finance gate must not over-reach: the dashboard landing and shop profile stay navigable.
        $this->assertSame('__allow__', $this->requiredPermissionFor('GET', '/vendor/dashboard'));
        $this->assertSame('__allow__', $this->requiredPermissionFor('GET', '/vendor/shop'));
    }

    public function test_every_mapped_permission_is_a_real_catalog_key(): void
    {
        $catalog = (new SellerPermissionService())->allKeys();

        $mapped = [
            $this->requiredPermissionFor('GET', '/vendor/products/list'),
            $this->requiredPermissionFor('POST', '/vendor/products/add'),
            $this->requiredPermissionFor('GET', '/vendor/orders/list'),
            $this->requiredPermissionFor('POST', '/vendor/orders/status'),
            $this->requiredPermissionFor('POST', '/vendor/coupon/add'),
            $this->requiredPermissionFor('GET', '/vendor/reviews/list'),
            $this->requiredPermissionFor('GET', '/vendor/transaction/statement'),
            $this->requiredPermissionFor('GET', '/vendor/business-settings/staff'),
            $this->requiredPermissionFor('GET', '/vendor/business-settings/shop'),
        ];

        foreach ($mapped as $key) {
            $this->assertContains($key, $catalog, "mapped permission '$key' must exist in the catalog");
        }
    }
}
