<?php

namespace App\Http\Controllers\Seller;

use App\Models\Product;
use App\Models\ProductPriceChange;
use App\Models\SellerPricingPolicy;
use App\Services\Marketplace\PricingPolicyService;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * The shop's own price floor, and the record of every price that has moved.
 *
 * Two screens the navigation has named since Wave 1 with no route behind either. The floor is the
 * one that stops a bad afternoon: a seller who sets a minimum margin cannot then be talked, bulk-
 * imported or automated below it, and the price history is what answers "who moved this and when"
 * on a catalogue several people and three automations can write to.
 *
 * The floor is advisory unless the seller enforces it. That choice belongs to them and is rendered
 * as one: a marketplace deciding for a shop what it may not charge is a different product.
 */
class PricingController extends SellerCenterController
{
    public function __construct(private readonly PricingPolicyService $pricing)
    {
    }

    public function index(Request $request): View
    {
        $sellerId = $this->sellerId($request);
        // Recent movement, so the floor is set beside what it would have caught rather than in the
        // abstract — a threshold chosen against nothing is a threshold chosen at random.
        $recent = $this->recentChanges($sellerId, limit: 8);

        return view('seller-views.pricing.index', [
            'policy' => $this->pricing->forSeller($sellerId),
            'available' => Schema::hasTable('seller_pricing_policies'),
            'recent' => $recent,
            'names' => $this->productNames($recent->pluck('product_id')->all(), $sellerId),
        ]);
    }

    public function save(Request $request): RedirectResponse
    {
        $request->validate([
            'min_margin_percent' => 'nullable|numeric|min:0|max:100',
            'min_price' => 'nullable|numeric|min:0',
        ]);

        $this->pricing->save($this->principal($request), [
            'min_margin_percent' => $request->get('min_margin_percent') === '' ? null : $request->get('min_margin_percent'),
            'min_price' => $request->get('min_price') === '' ? null : $request->get('min_price'),
            'enforce' => $request->boolean('enforce'),
        ]);

        ToastMagic::success(translate('your_price_floor_was_saved'));

        return back();
    }

    public function history(Request $request): View
    {
        $sellerId = $this->sellerId($request);
        $available = Schema::hasTable('product_price_changes');

        $changes = $available
            ? $this->changes($sellerId, $request)->paginate($this->pageSize($request))->withQueryString()
            : new \Illuminate\Pagination\LengthAwarePaginator([], 0, 25);

        return view('seller-views.pricing.history', [
            'changes' => $changes,
            'names' => $this->productNames(collect($changes->items())->pluck('product_id')->all(), $sellerId),
            'sources' => ProductPriceChange::SOURCES,
            'source' => $this->source($request),
            'available' => $available,
            'state' => $this->listState($changes->total(), $this->source($request) !== null),
        ]);
    }

    private function changes(int $sellerId, Request $request)
    {
        return ProductPriceChange::where('seller_id', $sellerId)
            ->when($this->source($request), fn ($query, $source) => $query->where('source', $source))
            ->orderByDesc('id');
    }

    /** @return \Illuminate\Support\Collection<int, ProductPriceChange> */
    private function recentChanges(int $sellerId, int $limit)
    {
        if (!Schema::hasTable('product_price_changes')) {
            return collect();
        }

        return ProductPriceChange::where('seller_id', $sellerId)->orderByDesc('id')->limit($limit)->get();
    }

    /** @return array<int, string> */
    private function productNames(array $productIds, int $sellerId): array
    {
        $ids = array_values(array_filter(array_unique($productIds)));

        if ($ids === [] || !Schema::hasTable('products')) {
            return [];
        }

        return Product::withoutGlobalScope('translate')
            ->whereIn('id', $ids)
            ->where(['added_by' => 'seller', 'user_id' => $sellerId])
            ->pluck('name', 'id')
            ->all();
    }

    private function source(Request $request): ?string
    {
        $source = (string) $request->query('source', '');

        return in_array($source, ProductPriceChange::SOURCES, true) ? $source : null;
    }

    private function pageSize(Request $request): int
    {
        $size = (int) $request->query('size', 25);

        return in_array($size, [25, 50, 100], true) ? $size : 25;
    }
}
