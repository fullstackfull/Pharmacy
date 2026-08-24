<?php

namespace App\Services\Marketplace;

use App\Models\Seller;
use App\Models\SellerStaff;

/**
 * Who is acting, and on whose shop.
 *
 * Every seller endpoint scopes its work to a shop. Until now that shop and the person asking were
 * the same thing, because only an owner could hold an API token — which is also why a staff member
 * could not use the seller app at all, and why anyone holding an owner's token got owner rights with
 * none of the permission matrix applied.
 *
 * Separating the two is the whole point: `sellerId` stays the shop, so every existing query that
 * scopes on it keeps meaning exactly what it meant; `staffId` and `permissions` say what this
 * particular person may do inside it.
 */
final class SellerPrincipal
{
    /**
     * @param  array<int, string>  $permissions  empty for an owner, who is not permission-checked
     */
    private function __construct(
        public readonly Seller $seller,
        public readonly ?SellerStaff $staff,
        public readonly array $permissions,
    ) {
    }

    /** The shop owner: their own account, and no permission they do not have. */
    public static function owner(Seller $seller): self
    {
        return new self($seller, null, []);
    }

    /**
     * A staff member acting inside their employer's shop.
     *
     * @param  array<int, string>  $permissions
     */
    public static function staff(Seller $seller, SellerStaff $staff, array $permissions): self
    {
        return new self($seller, $staff, array_values(array_unique($permissions)));
    }

    /** The shop. Not the person — this is what every query scopes on. */
    public function sellerId(): int
    {
        return (int) $this->seller->id;
    }

    public function isOwner(): bool
    {
        return $this->staff === null;
    }

    public function staffId(): ?int
    {
        return $this->staff?->id;
    }

    /**
     * May this principal do that?
     *
     * An owner may do anything in their own shop — there is no role above them to grant it. A staff
     * member may do only what their role lists, which is why a role with no permissions is a real
     * and useful thing: an account that can sign in and see nothing.
     */
    public function can(string $permission): bool
    {
        return $this->isOwner() || in_array($permission, $this->permissions, true);
    }

    /** For an audit line: who did this, in whose shop. */
    public function actorLabel(): string
    {
        return $this->isOwner()
            ? trim("{$this->seller->f_name} {$this->seller->l_name}")
            : "{$this->staff->name} ({$this->seller->f_name})";
    }
}
