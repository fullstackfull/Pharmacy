<?php

namespace App\Services\Marketplace;

use App\Models\SellerRole;
use App\Models\SellerStaff;
use Illuminate\Support\Facades\Schema;

/**
 * Seller staff roles & permissions (Phase 3, Stage A — Seller Center).
 *
 * This owns the permission *catalog* (the vendor capabilities a role can grant) and the resolver that
 * answers "does this staff member have permission X?". That resolver is the seam the future staff-login
 * enforcement will call — it is built and tested now so the deferred guard only has to invoke it, not
 * define the model.
 *
 * The seller (the owner account) is intentionally not represented as a staff row; the owner implicitly
 * holds every permission, and `staffCan()` speaks only to delegated staff.
 */
class SellerPermissionService
{
    /**
     * The permission catalog, grouped for the management UI. Keys are the stable identifiers a role
     * stores and enforcement will check; the labels are translatable.
     *
     * @return array<string, array<int, string>>
     */
    public function catalog(): array
    {
        return [
            'products' => ['products.view', 'products.manage'],
            'orders' => ['orders.view', 'orders.manage'],
            'inventory' => ['inventory.manage'],
            'promotions' => ['promotions.manage'],
            'finance' => ['finance.view', 'payouts.request'],
            'reviews' => ['reviews.view'],
            'settings' => ['shop_settings.manage', 'staff.manage'],
        ];
    }

    /** Every valid permission key, flat. */
    public function allKeys(): array
    {
        return array_values(array_merge(...array_values($this->catalog())));
    }

    /** Keep only keys that exist in the catalog — so a role can never store an unknown permission. */
    public function sanitize(array $permissions): array
    {
        $valid = $this->allKeys();

        return array_values(array_intersect(array_values($permissions), $valid));
    }

    public function rolesFor(int|string $sellerId)
    {
        if (!Schema::hasTable('seller_roles')) {
            return collect();
        }

        return SellerRole::where('seller_id', $sellerId)->orderBy('name')->get();
    }

    public function staffFor(int|string $sellerId)
    {
        if (!Schema::hasTable('seller_staff')) {
            return collect();
        }

        return SellerStaff::with('role')->where('seller_id', $sellerId)->orderBy('name')->get();
    }

    /** Does a role grant a permission? A role counts as active unless explicitly set otherwise
     *  (a freshly-created model may not carry the persisted 'active' default yet). */
    public function roleHas(?SellerRole $role, string $permission): bool
    {
        if ($role === null) {
            return false;
        }

        $active = ($role->status ?? SellerRole::STATUS_ACTIVE) === SellerRole::STATUS_ACTIVE;

        return $active && $role->grants($permission);
    }

    /**
     * Can a staff member perform an action? Resolves through their assigned role. An inactive staff
     * member, or one with no role, can do nothing — the safe default for a permission check.
     */
    public function staffCan(int|string $staffId, string $permission): bool
    {
        if (!Schema::hasTable('seller_staff')) {
            return false;
        }

        $staff = SellerStaff::with('role')->find($staffId);
        if (!$staff || $staff->status !== SellerStaff::STATUS_ACTIVE) {
            return false;
        }

        return $this->roleHas($staff->role, $permission);
    }
}
