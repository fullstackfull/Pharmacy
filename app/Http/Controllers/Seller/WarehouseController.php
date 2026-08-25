<?php

namespace App\Http\Controllers\Seller;

use App\Models\Product;
use App\Models\Warehouse;
use App\Services\Marketplace\WarehouseService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Where this shop's stock physically is.
 *
 * `current_stock` says how much a seller has; it never said where any of it was, so a seller with
 * two locations could not tell which one to pick from. The phone app gained this in Wave B and the
 * browser never did, because the navigation named a route nobody wrote.
 *
 * The invariant this screen renders is the one the service enforces: every operation preserves
 * `current_stock` and only partitions it, so placed plus unallocated always equals what the shop
 * has. Showing the unallocated remainder beside the warehouses is what makes that visible rather
 * than something a seller has to take on trust.
 */
class WarehouseController extends SellerCenterController
{
    /** Enough rows to see the shape of the shop's stock without paging a warehouse view. */
    private const PRODUCT_ROWS = 50;

    public function __construct(private readonly WarehouseService $warehouses)
    {
    }

    public function index(Request $request): View
    {
        $sellerId = $this->sellerId($request);
        $available = Schema::hasTable('warehouses') && Schema::hasTable('warehouse_stock');

        $locations = $available
            ? Warehouse::where('seller_id', $sellerId)->orderByDesc('is_default')->orderBy('name')->get()
            : collect();

        $products = $this->products($sellerId, $request);

        return view('seller-views.warehouse.index', [
            'available' => $available,
            'warehouses' => $locations,
            'products' => $products,
            'placement' => $available ? $this->placement($products->pluck('id')->all(), $locations->pluck('id')->all()) : [],
            'held' => $available ? $this->heldPerWarehouse($locations->pluck('id')->all()) : [],
            'search' => trim((string) $request->query('q', '')),
            'state' => $this->listState($products->count(), trim((string) $request->query('q', '')) !== ''),
        ]);
    }

    /** @return \Illuminate\Support\Collection<int, Product> */
    private function products(int $sellerId, Request $request)
    {
        if (!Schema::hasTable('products')) {
            return collect();
        }

        $search = trim((string) $request->query('q', ''));

        return Product::withoutGlobalScope('translate')
            ->where(['added_by' => 'seller', 'user_id' => $sellerId, 'product_type' => 'physical'])
            ->when($search !== '', fn ($query) => $query->where(function ($where) use ($search) {
                $where->where('name', 'like', '%' . $search . '%')->orWhere('code', 'like', $search . '%');
            }))
            ->orderBy('name')
            ->limit(self::PRODUCT_ROWS)
            ->get(['id', 'name', 'code', 'current_stock']);
    }

    /**
     * How many units of each product sit in each warehouse, in one query.
     *
     * @return array<int, array<int, int>>  product id => warehouse id => quantity
     */
    private function placement(array $productIds, array $warehouseIds): array
    {
        if ($productIds === [] || $warehouseIds === []) {
            return [];
        }

        $rows = DB::table('warehouse_stock')
            ->whereIn('product_id', $productIds)
            ->whereIn('warehouse_id', $warehouseIds)
            ->get(['product_id', 'warehouse_id', 'quantity']);

        $placement = [];
        foreach ($rows as $row) {
            $placement[(int) $row->product_id][(int) $row->warehouse_id] = (int) $row->quantity;
        }

        return $placement;
    }

    /** @return array<int, int> warehouse id => total units held there */
    private function heldPerWarehouse(array $warehouseIds): array
    {
        if ($warehouseIds === []) {
            return [];
        }

        return DB::table('warehouse_stock')
            ->whereIn('warehouse_id', $warehouseIds)
            ->groupBy('warehouse_id')
            ->selectRaw('warehouse_id, SUM(quantity) as units')
            ->pluck('units', 'warehouse_id')
            ->map(fn ($units) => (int) $units)
            ->all();
    }
}
