<?php

namespace App\Http\Controllers\Seller;

use App\Services\Marketplace\SellerPermissionService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Who works in this shop, and what each of them may do.
 *
 * The team exists and has since Phase 3 — roles, staff, per-permission grants, their own sign-in.
 * What was missing is a reading of it: the classic page is a set of forms, which answers "how do I
 * add somebody" and never "who can approve a payout". A shop that cannot answer the second question
 * cannot review its own access, which is the only reason to have roles at all.
 *
 * Writing stays on the classic forms. They work, they are audited, and a second form writing the
 * same role is how two people end up disagreeing about what `orders.manage` means.
 */
class TeamController extends SellerCenterController
{
    public function __construct(private readonly SellerPermissionService $permissions)
    {
    }

    public function index(Request $request): View
    {
        $sellerId = $this->sellerId($request);
        $staff = $this->permissions->staffFor($sellerId);

        return view('seller-views.team.index', [
            'staff' => $staff,
            'roles' => $this->permissions->rolesFor($sellerId),
            'catalog' => $this->permissions->catalog(),
            'state' => $this->listState($staff->count(), false),
        ]);
    }

    /**
     * The roles themselves, read as what they grant rather than as what they are called.
     *
     * A grid of role against permission is the only form in which "these two roles are the same
     * role with different names" is visible, and that is the finding an access review is for.
     */
    public function roles(Request $request): View
    {
        $sellerId = $this->sellerId($request);
        $roles = $this->permissions->rolesFor($sellerId);

        return view('seller-views.team.roles', [
            'roles' => $roles,
            'catalog' => $this->permissions->catalog(),
            'staff' => $this->permissions->staffFor($sellerId),
            'state' => $this->listState($roles->count(), false),
        ]);
    }
}
