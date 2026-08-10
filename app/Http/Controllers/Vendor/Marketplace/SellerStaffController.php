<?php

namespace App\Http\Controllers\Vendor\Marketplace;

use App\Http\Controllers\BaseController;
use App\Models\SellerRole;
use App\Models\SellerStaff;
use App\Services\Marketplace\SellerPermissionService;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * The seller manages their own staff and roles (Phase 3, Stage A). Identity is always auth('seller'),
 * and every row is scoped to that seller, so one shop can never see or touch another's team.
 *
 * Note: staff sign-in is not yet wired — these are managed records and a resolver
 * (SellerPermissionService) the deferred staff-login guard will enforce. That is stated in the UI.
 */
class SellerStaffController extends BaseController
{
    public function __construct(private readonly SellerPermissionService $permissions)
    {
    }

    public function index(Request|null $request, ?string $type = null): View
    {
        $sellerId = auth('seller')->id();

        return view('vendor-views.marketplace.staff', [
            'roles' => $this->permissions->rolesFor($sellerId),
            'staff' => $this->permissions->staffFor($sellerId),
            'catalog' => $this->permissions->catalog(),
        ]);
    }

    public function storeRole(Request $request): RedirectResponse
    {
        $v = $request->validate([
            'name' => 'required|string|max:120',
            'permissions' => 'nullable|array',
        ]);

        SellerRole::create([
            'seller_id' => auth('seller')->id(),
            'name' => $v['name'],
            'permissions' => $this->permissions->sanitize($v['permissions'] ?? []),
            'status' => SellerRole::STATUS_ACTIVE,
        ]);
        ToastMagic::success(translate('role_created'));

        return back();
    }

    public function updateRole(Request $request, int $id): RedirectResponse
    {
        $role = SellerRole::where(['id' => $id, 'seller_id' => auth('seller')->id()])->firstOrFail();
        $v = $request->validate([
            'name' => 'required|string|max:120',
            'permissions' => 'nullable|array',
            'status' => 'nullable|in:active,inactive',
        ]);

        $role->update([
            'name' => $v['name'],
            'permissions' => $this->permissions->sanitize($v['permissions'] ?? []),
            'status' => $v['status'] ?? 'active',
        ]);
        ToastMagic::success(translate('role_updated'));

        return back();
    }

    public function destroyRole(int $id): RedirectResponse
    {
        $sellerId = auth('seller')->id();
        $role = SellerRole::where(['id' => $id, 'seller_id' => $sellerId])->firstOrFail();

        // Detach the role from any staff before removing it, so no one is left pointing at nothing.
        SellerStaff::where(['seller_id' => $sellerId, 'seller_role_id' => $id])->update(['seller_role_id' => null]);
        $role->delete();
        ToastMagic::success(translate('role_deleted'));

        return back();
    }

    public function storeStaff(Request $request): RedirectResponse
    {
        $sellerId = auth('seller')->id();
        $v = $request->validate([
            'name' => 'required|string|max:120',
            'email' => 'required|email|max:191',
            'password' => 'required|string|min:6|max:100',
            'seller_role_id' => 'nullable|integer',
        ]);

        if (SellerStaff::where(['seller_id' => $sellerId, 'email' => $v['email']])->exists()) {
            ToastMagic::error(translate('a_staff_member_with_this_email_already_exists'));
            return back();
        }
        $this->assertRoleOwned($v['seller_role_id'] ?? null, $sellerId);

        SellerStaff::create([
            'seller_id' => $sellerId,
            'seller_role_id' => $v['seller_role_id'] ?? null,
            'name' => $v['name'],
            'email' => $v['email'],
            'password' => Hash::make($v['password']),   // stored for the deferred staff login
            'status' => SellerStaff::STATUS_ACTIVE,
        ]);
        ToastMagic::success(translate('staff_member_added'));

        return back();
    }

    public function updateStaff(Request $request, int $id): RedirectResponse
    {
        $sellerId = auth('seller')->id();
        $staff = SellerStaff::where(['id' => $id, 'seller_id' => $sellerId])->firstOrFail();
        $v = $request->validate([
            'name' => 'required|string|max:120',
            'seller_role_id' => 'nullable|integer',
            'status' => 'nullable|in:active,inactive',
            'password' => 'nullable|string|min:6|max:100',
        ]);
        $this->assertRoleOwned($v['seller_role_id'] ?? null, $sellerId);

        $attributes = [
            'name' => $v['name'],
            'seller_role_id' => $v['seller_role_id'] ?? null,
            'status' => $v['status'] ?? 'active',
        ];
        if (!empty($v['password'])) {
            $attributes['password'] = Hash::make($v['password']);
        }
        $staff->update($attributes);
        ToastMagic::success(translate('staff_member_updated'));

        return back();
    }

    public function destroyStaff(int $id): RedirectResponse
    {
        SellerStaff::where(['id' => $id, 'seller_id' => auth('seller')->id()])->firstOrFail()->delete();
        ToastMagic::success(translate('staff_member_removed'));

        return back();
    }

    /** A staff member may only be assigned a role that belongs to the same seller. */
    private function assertRoleOwned(int|string|null $roleId, int|string $sellerId): void
    {
        if ($roleId && !SellerRole::where(['id' => $roleId, 'seller_id' => $sellerId])->exists()) {
            abort(403);
        }
    }
}
