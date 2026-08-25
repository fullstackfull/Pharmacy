<?php

namespace App\Http\Middleware;

use App\Models\SellerStaff;
use App\Services\Marketplace\SellerPermissionService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Enforces a seller staff member's permissions across the vendor panel (Phase 3, Stage A).
 *
 * Runs after the `seller` guard on the vendor route group. A **real seller** (no `seller_staff_id` in
 * session) passes straight through — this middleware never changes owner behavior. A **staff** session
 * is one where the owner signed in through the staff login, which logs the staff in as their parent
 * seller (so all existing `auth('seller')` scoping keeps working) and stamps `seller_staff_id`.
 *
 * For a staff request the required permission is derived from the vendor URL, and `staffCan()` decides.
 * The map is **deny-by-default**: a navigation/self area is allowed to any active staff, a core domain
 * requires its catalog permission, and anything unmapped is refused (403) — so a gap fails closed, never
 * open. Read (GET) needs the `.view` permission where a domain has one; writes need `.manage`.
 */
class SellerStaffAccessMiddleware
{
    private const ALLOW = '__allow__';
    private const DENY = '__deny__';

    public function __construct(private readonly SellerPermissionService $permissions)
    {
    }

    public function handle(Request $request, Closure $next): mixed
    {
        $staffId = session('seller_staff_id');
        if (!$staffId) {
            return $next($request);   // a real seller (owner) — untouched
        }

        // Stale / tampered session: the staff row must still exist, be active, and belong to the seller
        // currently signed in. Otherwise drop the staff context and send them back to the staff login.
        $staff = SellerStaff::find($staffId);
        if (!$staff || $staff->status !== SellerStaff::STATUS_ACTIVE || (int) $staff->seller_id !== (int) auth('seller')->id()) {
            session()->forget('seller_staff_id');
            Auth::guard('seller')->logout();

            return redirect()->route('vendor.staff-auth.login');
        }

        $required = $this->requiredPermission($request);
        if ($required === self::ALLOW) {
            return $next($request);
        }
        if ($required !== self::DENY && $this->permissions->staffCan($staffId, $required)) {
            return $next($request);
        }

        abort(403, translate('you_do_not_have_permission_for_this_action'));
    }

    /**
     * The permission a vendor URL requires of a staff member: ALLOW (navigation/self), a catalog key, or
     * DENY (unmapped → refused).
     */
    private function requiredPermission(Request $request): string
    {
        // Money movement first — withdrawals, payout requests, and bank/withdraw-method details — needs
        // the finance permission regardless of the segment it otherwise falls under. Without this these
        // paths (e.g. dashboard/withdraw-request, shop/payment-information, business-settings/payouts)
        // would inherit their segment's ALLOW / shop_settings mapping and let a staffer with no finance
        // rights move funds or redirect payouts.
        if ($this->isMoneyMovementPath($request->path())) {
            return 'payouts.request';
        }

        $area = $request->segment(2);          // the part after /vendor/
        $isWrite = !$request->isMethod('get') && !$request->isMethod('head');

        return match ($area) {
            // Navigation, self-service and read-only cockpit — any active staff member.
            'auth', 'staff-auth', 'dashboard', 'profile', 'notification', 'messages', 'v2', 'system', 'shop', 'seller-center'
                => self::ALLOW,

            // The Seller Center's own screens. They sit on the same `/vendor` prefix as the classic
            // panel, so this map decides whether a staff member reaches them at all — and being
            // deny-by-default, every segment absent from here was a staff member locked out of the
            // whole redesign.
            //
            // The cockpit is allowed to any active staff for the same reason the dashboard is: it
            // shows them what is already theirs to see. Each screen behind it still declares its own
            // `seller_can:` gate on the route, which is the real enforcement — this is the coarse
            // pre-filter, not the decision.
            'overview', 'control-tower', 'issues', 'opportunities', 'search', 'help', 'preferences', 'foundation'
                => self::ALLOW,

            // Stock is catalogue work, and the routes gate it as products.view / products.manage.
            'inventory' => $isWrite ? 'products.manage' : 'products.view',

            // Reading a rule and the record of what it did is catalogue history; writing one changes
            // the catalogue. The same split the routes declare.
            'automation' => $isWrite ? 'products.manage' : 'products.view',

            // Catalogue.
            'products', 'product' => $isWrite ? 'products.manage' : 'products.view',

            // Orders and everything that acts on an order.
            'orders' => $isWrite ? 'orders.manage' : 'orders.view',
            'pos', 'refund' => 'orders.manage',
            'customer' => 'orders.view',

            // Promotions.
            'coupon', 'clearance-sale' => 'promotions.manage',

            // Reviews.
            'reviews' => 'reviews.view',

            // Money. `analytics` is the shop's own numbers — the same screen the API already serves
            // this staff member under finance.view. It was absent from this map, so deny-by-default
            // 403'd every staff member on the web page while their token reached the identical data.
            'transaction', 'report', 'analytics', 'finance' => 'finance.view',

            // Wave 4's fulfilment screens. Reading a return, a refund or the warehouse is order and
            // catalogue work; moving a return or advancing a fulfilment changes stock, and the
            // routes declare that split too.
            'returns', 'refunds', 'shipments', 'picking', 'packing' => $isWrite ? 'orders.manage' : 'orders.view',
            'warehouse', 'bulk-jobs', 'pricing' => $isWrite ? 'products.manage' : 'products.view',

            // Everything waiting for this shop. Allowed to any active staff for the same reason the
            // cockpit is: it shows them what is already theirs to see, and each entry links to a
            // screen that gates itself.
            'actions' => self::ALLOW,

            // Wave 6's trust screens. A shop's own standing, its brand authorisations and the
            // issues that escalated are things any active staff member should be able to read —
            // the same reasoning as the cockpit, and each write beyond them is gated on its route.
            'performance', 'compliance', 'brands', 'incidents' => self::ALLOW,
            'approvals' => 'staff.manage',

            // Wave 7's enterprise screens. Team and the access review are staff work by definition;
            // integrations are shop settings, because a key or an endpoint acts as the whole shop
            // rather than as one area of it. The routes declare the same, and this is the coarse
            // pre-filter rather than the decision.
            'team', 'security' => 'staff.manage',
            'integrations' => 'shop_settings.manage',

            // Delivery team is an order-fulfilment concern.
            'delivery-man' => 'orders.manage',

            // The shop's own history. Same permission as the team screens it sits beside, and the
            // route declares it too — this is the coarse pre-filter, not the decision.
            'audit' => 'staff.manage',

            // Settings — the staff area itself needs staff.manage; the rest needs shop settings.
            'business-settings' => $request->segment(3) === 'staff' ? 'staff.manage' : 'shop_settings.manage',

            // Anything not explicitly mapped is refused for staff.
            default => self::DENY,
        };
    }

    /**
     * Whether the path moves money or manages the details funds are paid to — withdrawals, payout
     * requests, and withdraw-method/bank information — wherever it lives in the vendor URL tree.
     */
    private function isMoneyMovementPath(string $path): bool
    {
        foreach (['withdraw', 'payout', 'payment-information', 'bank-info', 'money-withdraw'] as $needle) {
            if (str_contains($path, $needle)) {
                return true;
            }
        }

        return false;
    }
}
