<?php

namespace Tests\Feature;

use App\Models\SellerRole;
use App\Models\SellerStaff;
use App\Services\Marketplace\SellerPermissionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Seller staff roles & permissions (Phase 3, Stage A).
 *
 * The resolver the future staff login will call: a role grants only the keys it holds, unknown keys
 * can never be stored, and a staff member's permission resolves through their role — with an inactive
 * staff member or one with no role able to do nothing (the safe default).
 */
class SellerPermissionTest extends TestCase
{
    private SellerPermissionService $svc;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['seller_staff', 'seller_roles'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::create('seller_roles', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('seller_id'); $t->string('name'); $t->json('permissions')->nullable();
            $t->string('status')->default('active'); $t->unsignedBigInteger('created_by')->nullable(); $t->timestamps();
        });
        Schema::create('seller_staff', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('seller_id'); $t->unsignedBigInteger('seller_role_id')->nullable();
            $t->string('name'); $t->string('email'); $t->string('password')->nullable(); $t->string('status')->default('active');
            $t->timestamp('last_login_at')->nullable(); $t->timestamps();
        });

        $this->svc = new SellerPermissionService();
    }

    public function test_the_catalog_is_non_empty_and_flat_keys_are_unique(): void
    {
        $keys = $this->svc->allKeys();

        $this->assertNotEmpty($keys);
        $this->assertSame($keys, array_values(array_unique($keys)), 'no duplicate permission keys');
        $this->assertContains('orders.manage', $keys);
    }

    public function test_sanitize_strips_unknown_permission_keys(): void
    {
        $clean = $this->svc->sanitize(['orders.manage', 'not_a_real_permission', 'products.view']);

        $this->assertEqualsCanonicalizing(['orders.manage', 'products.view'], $clean);
    }

    public function test_a_role_grants_only_the_keys_it_holds(): void
    {
        $role = SellerRole::create(['seller_id' => 7, 'name' => 'Catalog', 'permissions' => ['products.view', 'products.manage']]);

        $this->assertTrue($this->svc->roleHas($role, 'products.manage'));
        $this->assertFalse($this->svc->roleHas($role, 'orders.manage'));
    }

    public function test_an_inactive_role_grants_nothing(): void
    {
        $role = SellerRole::create(['seller_id' => 7, 'name' => 'Old', 'permissions' => ['orders.manage'], 'status' => 'inactive']);

        $this->assertFalse($this->svc->roleHas($role, 'orders.manage'));
    }

    public function test_staff_can_resolves_through_the_assigned_role(): void
    {
        $role = SellerRole::create(['seller_id' => 7, 'name' => 'Orders', 'permissions' => ['orders.view', 'orders.manage']]);
        $staff = SellerStaff::create(['seller_id' => 7, 'seller_role_id' => $role->id, 'name' => 'Sara', 'email' => 'sara@shop.test', 'status' => 'active']);

        $this->assertTrue($this->svc->staffCan($staff->id, 'orders.manage'));
        $this->assertFalse($this->svc->staffCan($staff->id, 'finance.view'), 'not in the role');
    }

    public function test_inactive_staff_and_staff_with_no_role_can_do_nothing(): void
    {
        $role = SellerRole::create(['seller_id' => 7, 'name' => 'All', 'permissions' => $this->svc->allKeys()]);
        $inactive = SellerStaff::create(['seller_id' => 7, 'seller_role_id' => $role->id, 'name' => 'X', 'email' => 'x@shop.test', 'status' => 'inactive']);
        $noRole = SellerStaff::create(['seller_id' => 7, 'seller_role_id' => null, 'name' => 'Y', 'email' => 'y@shop.test', 'status' => 'active']);

        $this->assertFalse($this->svc->staffCan($inactive->id, 'orders.manage'), 'inactive -> nothing');
        $this->assertFalse($this->svc->staffCan($noRole->id, 'orders.manage'), 'no role -> nothing');
    }

    public function test_lists_are_scoped_to_the_seller(): void
    {
        SellerRole::create(['seller_id' => 7, 'name' => 'A', 'permissions' => []]);
        SellerRole::create(['seller_id' => 8, 'name' => 'B', 'permissions' => []]);

        $this->assertCount(1, $this->svc->rolesFor(7));
    }
}
