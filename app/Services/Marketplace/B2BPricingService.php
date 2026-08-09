<?php

namespace App\Services\Marketplace;

use App\Models\CustomerGroup;
use App\Models\CustomerGroupPrice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * B2B / wholesale price resolution (Phase 3, Stage E).
 *
 * The contract that makes this safe to add to a live store: `priceFor()` returns the **base price
 * unchanged** for a customer in no group, or a group with no rule for the product. So a retail buyer,
 * and any checkout that has not adopted this, sees exactly today's price. Group pricing only ever
 * *lowers* the effective price, and where a customer belongs to several groups the **best (lowest)**
 * price wins.
 *
 * Resolution per group: a fixed `price` override, else a per-product `discount_percent`, else the
 * group's `default_discount_percent`, applied to the base.
 */
class B2BPricingService
{
    /** The active groups a customer belongs to. */
    public function groupsFor(int|string|null $customerId): array
    {
        if (!$customerId || !Schema::hasTable('customer_group_customer') || !Schema::hasTable('customer_groups')) {
            return [];
        }

        $groupIds = DB::table('customer_group_customer')->where('customer_id', $customerId)->pluck('customer_group_id');
        if ($groupIds->isEmpty()) {
            return [];
        }

        return CustomerGroup::whereIn('id', $groupIds)->where('status', CustomerGroup::STATUS_ACTIVE)->get()->all();
    }

    /**
     * The effective price for a product and customer.
     *
     * @return array{price: float, base: float, group_id: int|null, source: string}
     */
    public function priceFor(int|string $productId, int|string|null $customerId, float $basePrice): array
    {
        $base = round($basePrice, 2);
        $best = ['price' => $base, 'base' => $base, 'group_id' => null, 'source' => 'base'];

        if ($base <= 0) {
            return $best;   // nothing to discount
        }

        foreach ($this->groupsFor($customerId) as $group) {
            $candidate = $this->priceForGroup($group, $productId, $base);
            if ($candidate['price'] < $best['price']) {
                $best = $candidate;
            }
        }

        return $best;
    }

    /**
     * What one group would charge for a product: a fixed override, else a per-product discount, else
     * the group's default discount, applied to the base.
     *
     * @return array{price: float, base: float, group_id: int, source: string}
     */
    public function priceForGroup(CustomerGroup $group, int|string $productId, float $base): array
    {
        $row = Schema::hasTable('customer_group_prices')
            ? CustomerGroupPrice::where(['customer_group_id' => $group->id, 'product_id' => $productId])->first()
            : null;

        if ($row && $row->price !== null) {
            return ['price' => round((float) $row->price, 2), 'base' => $base, 'group_id' => $group->id, 'source' => 'fixed'];
        }

        $discount = $row && $row->discount_percent !== null
            ? (float) $row->discount_percent
            : (float) $group->default_discount_percent;

        $discount = max(0.0, min(100.0, $discount));
        $price = round($base * (1 - $discount / 100), 2);

        return ['price' => $price, 'base' => $base, 'group_id' => $group->id, 'source' => $discount > 0 ? 'discount' : 'base'];
    }
}
