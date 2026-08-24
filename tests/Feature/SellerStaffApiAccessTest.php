<?php

namespace Tests\Feature;

use App\Models\Seller;
use App\Models\SellerRole;
use App\Models\SellerStaff;
use App\Services\Marketplace\SellerPermissionService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * A staff member's identity on the API.
 *
 * `seller_staff` has carried credentials since it was created, but only the web panel ever used
 * them: the staff login writes a session key, and the API has no session to read. So a warehouse or
 * finance employee had no way into the seller app at all — the only route in was to be handed the
 * owner's own token, which carries owner rights with none of the permission matrix applied.
 *
 * The properties that make this safe rather than merely possible:
 *
 * The shop and the person are separate. `$request->seller` is still the shop, so every query that
 * scopes on it keeps meaning what it meant; a staff member resolves to their *employer's* shop, not
 * one of their own.
 *
 * Permissions are read at request time, not baked into the token, so revoking one takes effect on
 * the member's next request rather than their next sign-in.
 *
 * And an account that should not be able to act — inactive, role switched off, employer no longer
 * approved — cannot authenticate at all, which is the safe answer for a credentials check.
 */
class SellerStaffApiAccessTest extends TestCase
{
    private const OWNER_TOKEN = 'owner-token-long-enough-to-clear-the-length-gate';
    private const STAFF_TOKEN = 'staff-token-long-enough-to-clear-the-length-gate';

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['seller_staff', 'seller_roles', 'sellers', 'business_settings'] as $table) {
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
            $table->string('email')->nullable();
            $table->string('password')->nullable();
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

        Seller::insert([
            ['id' => 1, 'f_name' => 'Owner', 'l_name' => 'One', 'email' => 'owner@example.com',
                'status' => 'approved', 'auth_token' => self::OWNER_TOKEN],
            ['id' => 2, 'f_name' => 'Rival', 'l_name' => 'Two', 'email' => 'rival@example.com',
                'status' => 'approved', 'auth_token' => 'rival-token-long-enough-to-clear-the-gate!!'],
        ]);
    }

    private function role(array $permissions, string $status = 'active'): SellerRole
    {
        return SellerRole::create([
            'seller_id' => 1, 'name' => 'Clerk', 'permissions' => $permissions, 'status' => $status,
        ]);
    }

    private function staff(?SellerRole $role, string $status = 'active', ?string $token = self::STAFF_TOKEN): SellerStaff
    {
        return SellerStaff::create([
            'seller_id' => 1,
            'seller_role_id' => $role?->id,
            'name' => 'Clerk',
            'email' => 'clerk@example.com',
            'password' => Hash::make('ClerkPass123'),
            'auth_token' => $token,
            'status' => $status,
        ]);
    }

    private function permissions(): SellerPermissionService
    {
        return app(SellerPermissionService::class);
    }

    public function test_a_staff_token_resolves_to_the_employers_shop_not_a_shop_of_its_own(): void
    {
        $this->staff($this->role(['finance.view']));

        $principal = $this->permissions()->principalFor(self::STAFF_TOKEN);

        $this->assertNotNull($principal);
        // The shop is the employer's. Every existing query scopes on this, so it has to keep
        // meaning what it meant before staff could hold a token at all.
        $this->assertSame(1, $principal->sellerId());
        $this->assertFalse($principal->isOwner());
        $this->assertNotNull($principal->staffId());
    }

    public function test_an_owner_may_do_anything_in_their_own_shop(): void
    {
        $principal = $this->permissions()->principalFor(self::OWNER_TOKEN);

        $this->assertTrue($principal->isOwner());
        $this->assertNull($principal->staffId());
        // There is no role above an owner to grant them anything, so nothing is withheld.
        $this->assertTrue($principal->can('payouts.request'));
        $this->assertTrue($principal->can('anything.at.all'));
    }

    public function test_a_staff_member_may_do_only_what_their_role_lists(): void
    {
        $this->staff($this->role(['finance.view', 'orders.view']));

        $principal = $this->permissions()->principalFor(self::STAFF_TOKEN);

        $this->assertTrue($principal->can('finance.view'));
        $this->assertTrue($principal->can('orders.view'));
        // Reading the books is not the same as withdrawing from them.
        $this->assertFalse($principal->can('payouts.request'));
        $this->assertFalse($principal->can('staff.manage'));
    }

    public function test_a_role_with_no_permissions_can_sign_in_and_do_nothing(): void
    {
        $this->staff($this->role([]));

        $principal = $this->permissions()->principalFor(self::STAFF_TOKEN);

        $this->assertNotNull($principal, 'An empty role is a real account, not a broken one.');
        $this->assertFalse($principal->can('finance.view'));
    }

    public function test_revoking_a_permission_takes_effect_on_the_next_request(): void
    {
        $role = $this->role(['finance.view']);
        $this->staff($role);

        $this->assertTrue($this->permissions()->principalFor(self::STAFF_TOKEN)->can('finance.view'));

        // Not baked into the token at sign-in: the same token, read again.
        $role->forceFill(['permissions' => []])->save();

        $this->assertFalse($this->permissions()->principalFor(self::STAFF_TOKEN)->can('finance.view'));
    }

    public function test_switching_off_the_role_takes_everything_with_it(): void
    {
        $this->staff($this->role(['finance.view'], status: 'inactive'));

        $this->assertFalse($this->permissions()->principalFor(self::STAFF_TOKEN)->can('finance.view'));
    }

    public function test_a_staff_member_with_no_role_can_do_nothing(): void
    {
        $this->staff(null);

        $this->assertFalse($this->permissions()->principalFor(self::STAFF_TOKEN)->can('finance.view'));
    }

    public function test_an_inactive_staff_member_cannot_authenticate_at_all(): void
    {
        $this->staff($this->role(['finance.view']), status: 'inactive');

        // Not "authenticated with no permissions" — not authenticated. An account that has been
        // switched off should not be able to read the shop either.
        $this->assertNull($this->permissions()->principalFor(self::STAFF_TOKEN));
    }

    public function test_a_staff_account_cannot_outlive_its_employers_standing(): void
    {
        $this->staff($this->role(['finance.view']));
        Seller::where('id', 1)->update(['status' => 'suspended']);

        $this->assertNull($this->permissions()->principalFor(self::STAFF_TOKEN));
    }

    public function test_a_permission_a_role_should_not_have_is_not_granted_by_storing_it(): void
    {
        // Roles are sanitised against the catalogue, so a row edited to claim something invented
        // grants nothing.
        $this->staff($this->role(['finance.view', 'everything.always']));

        $principal = $this->permissions()->principalFor(self::STAFF_TOKEN);

        $this->assertTrue($principal->can('finance.view'));
        $this->assertFalse($principal->can('everything.always'));
    }

    public function test_a_token_that_belongs_to_nobody_resolves_to_nobody(): void
    {
        $this->assertNull($this->permissions()->principalFor('a-token-that-was-never-issued-to-anyone'));
    }

    public function test_the_endpoint_gate_refuses_a_staff_member_without_the_permission(): void
    {
        $this->staff($this->role(['finance.view']));

        $headers = ['Authorization' => 'Bearer ' . self::STAFF_TOKEN, 'Accept' => 'application/json'];

        // Asserted on the gate, not on what is behind it: the gate runs before the controller, so
        // this says what it means without dragging the whole ledger schema into a permission test.
        // Moving the money is refused, and refused with 403 — the client can tell "your role does
        // not allow this" from "there is nothing here".
        $refused = $this->withHeaders($headers)->postJson('/api/v3/seller/seller-center/payouts', ['amount' => 1]);
        $refused->assertStatus(403);
        // The gate's own refusal, distinguishable from any 403 the endpoint might raise for its own
        // reasons — and it says which, rather than pretending the endpoint is not there.
        $this->assertSame('permission', data_get($refused->json(), 'errors.0.code'));

        // Reading is allowed, so whatever answer comes back is the endpoint's, not the gate's.
        $this->assertNotSame(
            'permission',
            data_get($this->withHeaders($headers)->getJson('/api/v3/seller/seller-center/payouts')->json(), 'errors.0.code'),
            'A role holding finance.view was refused the books.',
        );
    }

    public function test_the_owner_passes_the_same_gate(): void
    {
        $headers = ['Authorization' => 'Bearer ' . self::OWNER_TOKEN, 'Accept' => 'application/json'];

        // Asserted on the refusal, not the status: an endpoint may answer 403 for reasons of its
        // own — payouts do, when the ledger is not configured — and a test that only counted the
        // number could not tell the gate's refusal from the controller's.
        foreach ([
            $this->withHeaders($headers)->getJson('/api/v3/seller/seller-center/payouts'),
            $this->withHeaders($headers)->postJson('/api/v3/seller/seller-center/payouts', ['amount' => 1]),
        ] as $response) {
            $this->assertNotSame(
                'permission',
                data_get($response->json(), 'errors.0.code'),
                'The owner was refused by the permission gate in their own shop.',
            );
        }
    }

    public function test_a_request_with_no_credential_never_reaches_the_permission_gate(): void
    {
        // The gate fails closed if it is ever reached without a principal, but it should not be:
        // authentication answers first, and answers 401.
        $this->postJson('/api/v3/seller/seller-center/payouts', ['amount' => 1])->assertStatus(401);
    }
}
