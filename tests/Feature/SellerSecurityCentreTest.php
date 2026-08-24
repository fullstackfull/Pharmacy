<?php

namespace Tests\Feature;

use App\Http\Middleware\SellerApiAuthMiddleware;
use App\Models\Seller;
use App\Models\SellerRole;
use App\Models\SellerStaff;
use App\Services\AuditLogger;
use App\Services\Marketplace\SellerAuditTrailService;
use App\Services\Marketplace\SellerPrincipal;
use App\Services\Marketplace\SellerTeamService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * The shop's security desk: who works here, and what has been done.
 *
 * The team rules are asserted through the service both surfaces call, rather than through either
 * one, because the point of extracting it was that the panel and the app cannot disagree. A test
 * that went through only the API would pass while the panel granted something different.
 *
 * The audit trail is mostly tested for what it must *not* return. It reads one table that holds the
 * whole platform's history, so the interesting question is never "does it find the row" but "does it
 * find only this shop's rows" — including the near-miss of shop 1 while looking for shop 11.
 */
class SellerSecurityCentreTest extends TestCase
{
    private const SELLER = 1;
    private const RIVAL = 2;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['seller_staff', 'seller_roles', 'sellers', 'audit_logs', 'business_settings'] as $table) {
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
            $table->string('auth_token')->nullable();
            $table->timestamps();
        });
        Schema::create('seller_roles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id');
            $table->string('name', 120);
            $table->text('permissions')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });
        Schema::create('seller_staff', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id');
            $table->unsignedBigInteger('seller_role_id')->nullable();
            $table->string('name', 120);
            $table->string('email', 191);
            $table->string('password')->nullable();
            $table->text('auth_token')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamp('last_login_at')->nullable();
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

        Seller::insert([
            ['id' => self::SELLER, 'f_name' => 'Owner', 'l_name' => 'One', 'status' => 'approved', 'auth_token' => 'owner-token-long-enough-to-clear-the-gate'],
            ['id' => self::RIVAL, 'f_name' => 'Rival', 'l_name' => 'Two', 'status' => 'approved', 'auth_token' => null],
            ['id' => 11, 'f_name' => 'Eleven', 'l_name' => 'Shop', 'status' => 'approved', 'auth_token' => null],
        ]);
    }

    private function team(): SellerTeamService
    {
        return app(SellerTeamService::class);
    }

    private function trail(): SellerAuditTrailService
    {
        return app(SellerAuditTrailService::class);
    }

    private function staff(array $attributes = []): SellerStaff
    {
        return SellerStaff::create(array_merge([
            'seller_id' => self::SELLER,
            'name' => 'Clerk',
            'email' => 'clerk@example.com',
            'password' => Hash::make('ClerkPass123'),
            'status' => SellerStaff::STATUS_ACTIVE,
        ], $attributes));
    }

    public function test_a_role_cannot_be_given_a_permission_that_does_not_exist(): void
    {
        $role = $this->team()->createRole(self::SELLER, [
            'name' => 'Warehouse',
            'permissions' => ['orders.view', 'everything.always'],
        ]);

        // Stored, not merely ignored at read time: a row that claims an authority is a row somebody
        // will eventually trust.
        $this->assertSame(['orders.view'], $role->permissions);
    }

    public function test_a_staff_member_cannot_be_given_another_shops_role(): void
    {
        $rivalRole = SellerRole::create(['seller_id' => self::RIVAL, 'name' => 'Theirs', 'permissions' => ['orders.manage']]);

        $this->expectException(ValidationException::class);

        $this->team()->createStaff(self::SELLER, [
            'name' => 'Clerk',
            'email' => 'clerk@example.com',
            'password' => 'ClerkPass123',
            'seller_role_id' => $rivalRole->id,
        ]);
    }

    public function test_two_people_in_one_shop_cannot_share_an_email(): void
    {
        $this->staff();

        $this->expectException(ValidationException::class);

        $this->team()->createStaff(self::SELLER, [
            'name' => 'Someone else',
            'email' => 'clerk@example.com',
            'password' => 'ClerkPass123',
        ]);
    }

    public function test_the_same_email_may_work_for_two_different_shops(): void
    {
        $this->staff();

        $other = $this->team()->createStaff(self::RIVAL, [
            'name' => 'Clerk',
            'email' => 'clerk@example.com',
            'password' => 'ClerkPass123',
        ]);

        // One person can hold two jobs. Uniqueness is per shop, which is what the sign-in reads.
        $this->assertSame(self::RIVAL, (int) $other->seller_id);
    }

    public function test_switching_somebody_off_ends_the_session_they_are_already_in(): void
    {
        $staff = $this->staff(['auth_token' => 'staff-token-long-enough-to-clear-the-gate']);

        $this->team()->updateStaff($staff, ['name' => 'Clerk', 'status' => 'inactive']);

        // Not merely "cannot sign in again": the token they are holding right now stops working.
        $this->assertNull($staff->fresh()->auth_token);
    }

    public function test_signing_somebody_out_leaves_them_employed(): void
    {
        $staff = $this->staff(['auth_token' => 'staff-token-long-enough-to-clear-the-gate']);

        $this->team()->signOutStaff($staff);

        $this->assertNull($staff->fresh()->auth_token);
        // The answer when a phone is lost and the employee is still employed tomorrow.
        $this->assertSame(SellerStaff::STATUS_ACTIVE, $staff->fresh()->status);
    }

    public function test_deleting_a_role_leaves_nobody_pointing_at_nothing(): void
    {
        $role = $this->team()->createRole(self::SELLER, ['name' => 'Warehouse', 'permissions' => ['orders.view']]);
        $staff = $this->staff(['seller_role_id' => $role->id]);

        $this->team()->deleteRole($role);

        $this->assertNull($staff->fresh()->seller_role_id);
        // A member with no role can sign in and do nothing, which is a state somebody reasoned about.
        $this->assertSame(SellerStaff::STATUS_ACTIVE, $staff->fresh()->status);
    }

    public function test_the_trail_shows_what_the_shops_own_people_did(): void
    {
        $staff = $this->staff();

        DB::table('audit_logs')->insert([
            ['actor_type' => 'seller', 'actor_id' => self::SELLER, 'action' => 'seller.role_created', 'created_at' => now(), 'updated_at' => now()],
            ['actor_type' => 'seller_staff', 'actor_id' => $staff->id, 'action' => 'seller.staff_updated', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->assertSame(2, $this->trail()->recent(self::SELLER)['total']);
    }

    public function test_an_action_from_the_seller_app_is_attributed_to_whoever_took_it(): void
    {
        // The app carries a token rather than logging a guard in, so nothing here is authenticated
        // in the sense the logger used to look for. Everything a seller did arrived as "System".
        $staff = $this->staff();
        $seller = Seller::find(self::SELLER);

        request()->attributes->set(
            SellerApiAuthMiddleware::PRINCIPAL,
            SellerPrincipal::staff($seller, $staff, ['orders.manage']),
        );

        app(AuditLogger::class)->record(action: 'seller.order_status_changed');

        $entry = DB::table('audit_logs')->latest('id')->first();
        $this->assertSame('seller_staff', $entry->actor_type);
        $this->assertSame($staff->id, (int) $entry->actor_id);
        $this->assertStringContainsString('Clerk', (string) $entry->actor_name);

        // And it reaches the shop's own history, which is the point of recording it.
        $this->assertSame(1, $this->trail()->recent(self::SELLER)['total']);
    }

    public function test_an_action_taken_by_the_owners_token_is_attributed_to_the_owner(): void
    {
        request()->attributes->set(
            SellerApiAuthMiddleware::PRINCIPAL,
            SellerPrincipal::owner(Seller::find(self::SELLER)),
        );

        app(AuditLogger::class)->record(action: 'seller.shop_updated');

        $entry = DB::table('audit_logs')->latest('id')->first();
        $this->assertSame('seller', $entry->actor_type);
        $this->assertSame(self::SELLER, (int) $entry->actor_id);
    }

    public function test_the_trail_never_shows_another_shops_history(): void
    {
        $rivalStaff = $this->staff(['seller_id' => self::RIVAL, 'email' => 'theirs@example.com']);

        // Every row carries the same keys: a batch insert takes its column list from the first row,
        // so a later row with an extra key silently loses it.
        DB::table('audit_logs')->insert([
            ['actor_type' => 'seller', 'actor_id' => self::RIVAL, 'action' => 'seller.role_created', 'context' => null, 'created_at' => now(), 'updated_at' => now()],
            ['actor_type' => 'seller_staff', 'actor_id' => $rivalStaff->id, 'action' => 'seller.staff_updated', 'context' => null, 'created_at' => now(), 'updated_at' => now()],
            ['actor_type' => 'admin', 'actor_id' => 1, 'action' => 'settlement.approved', 'context' => json_encode(['seller_id' => self::RIVAL]), 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->assertSame(0, $this->trail()->recent(self::SELLER)['total']);
    }

    public function test_the_trail_shows_what_the_marketplace_decided_about_the_shop(): void
    {
        DB::table('audit_logs')->insert([
            'actor_type' => 'admin', 'actor_id' => 1, 'action' => 'settlement.approved',
            'context' => json_encode(['seller_id' => self::SELLER]),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // A seller should be able to see the decisions taken about them, not only their own actions.
        $this->assertSame(1, $this->trail()->recent(self::SELLER)['total']);
    }

    public function test_shop_one_does_not_match_shop_eleven(): void
    {
        DB::table('audit_logs')->insert([
            'actor_type' => 'admin', 'actor_id' => 1, 'action' => 'settlement.approved',
            'context' => json_encode(['seller_id' => 11]),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // The near miss a bare LIKE on the id would have made: "1" is a prefix of "11".
        $this->assertSame(0, $this->trail()->recent(self::SELLER)['total']);
        $this->assertSame(1, $this->trail()->recent(11)['total']);
    }

    public function test_somebody_who_has_left_still_appears_in_what_they_did(): void
    {
        $staff = $this->staff();
        DB::table('audit_logs')->insert([
            'actor_type' => 'seller_staff', 'actor_id' => $staff->id, 'action' => 'seller.staff_updated',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->team()->updateStaff($staff, ['name' => 'Clerk', 'status' => 'inactive']);

        // Exactly when a seller most wants to look. Counted on the row they left behind rather than
        // on the total, which also holds the audit line for switching them off.
        $entries = $this->trail()->recent(self::SELLER)['entries'];
        $byThem = array_filter($entries, fn (array $entry) => (int) $entry['id'] === 1);
        $this->assertCount(1, $byThem);
    }

    public function test_the_trail_can_be_narrowed_to_one_kind_of_action(): void
    {
        DB::table('audit_logs')->insert([
            ['actor_type' => 'seller', 'actor_id' => self::SELLER, 'action' => 'seller.staff_added', 'created_at' => now(), 'updated_at' => now()],
            ['actor_type' => 'seller', 'actor_id' => self::SELLER, 'action' => 'seller.automation_rule_created', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->assertSame(1, $this->trail()->recent(self::SELLER, action: 'seller.staff')['total']);
        $this->assertSame(2, $this->trail()->recent(self::SELLER, action: 'seller.')['total']);
    }

    public function test_who_holds_a_way_in_never_includes_the_way_in_itself(): void
    {
        $this->staff(['auth_token' => 'staff-token-long-enough-to-clear-the-gate']);

        $holders = $this->trail()->accessHolders(self::SELLER, 'Owner One', ownerHasToken: true);

        $this->assertCount(2, $holders);
        $this->assertTrue($holders[0]['signed_in']);
        $this->assertTrue($holders[1]['signed_in']);

        // The token's existence is the useful fact. Its value is the shop.
        $encoded = json_encode($holders);
        $this->assertStringNotContainsString('staff-token-long-enough', $encoded);
        $this->assertStringNotContainsString('auth_token', $encoded);
    }

    public function test_who_holds_a_way_in_lists_only_this_shops_people(): void
    {
        $this->staff();
        $this->staff(['seller_id' => self::RIVAL, 'email' => 'theirs@example.com']);

        $this->assertCount(2, $this->trail()->accessHolders(self::SELLER, 'Owner One', ownerHasToken: true));
    }

    public function test_the_endpoint_refuses_a_staff_member_without_staff_manage(): void
    {
        $role = SellerRole::create(['seller_id' => self::SELLER, 'name' => 'Clerk', 'permissions' => ['orders.view']]);
        $this->staff(['seller_role_id' => $role->id, 'auth_token' => 'staff-token-long-enough-to-clear-the-gate']);

        $headers = ['Authorization' => 'Bearer staff-token-long-enough-to-clear-the-gate', 'Accept' => 'application/json'];

        // staff.manage is the permission that can grant every other permission, so a role without it
        // must not be able to write itself one.
        $refused = $this->withHeaders($headers)->postJson(
            '/api/v3/seller/seller-center/security/roles',
            ['name' => 'Everything', 'permissions' => ['payouts.request']],
        );

        $refused->assertStatus(403);
        $this->assertSame('permission', data_get($refused->json(), 'errors.0.code'));
        $this->assertSame(1, SellerRole::count());
    }

    public function test_reading_the_team_needs_the_same_permission_as_changing_it(): void
    {
        $role = SellerRole::create(['seller_id' => self::SELLER, 'name' => 'Clerk', 'permissions' => ['orders.view']]);
        $this->staff(['seller_role_id' => $role->id, 'auth_token' => 'staff-token-long-enough-to-clear-the-gate']);

        $headers = ['Authorization' => 'Bearer staff-token-long-enough-to-clear-the-gate', 'Accept' => 'application/json'];

        // The audit trail carries the before and after of a bank-details change, and the team
        // list carries every colleague's email and last login. Reading them was open while
        // writing them was gated, which meant a role with no rights at all could read the shop's
        // account number out of its own history.
        foreach (['audit', 'staff', 'access', 'roles', 'permissions'] as $read) {
            $response = $this->withHeaders($headers)->getJson("/api/v3/seller/seller-center/security/{$read}");

            $response->assertStatus(403);
            $this->assertSame('permission', data_get($response->json(), 'errors.0.code'), $read);
        }
    }
}
