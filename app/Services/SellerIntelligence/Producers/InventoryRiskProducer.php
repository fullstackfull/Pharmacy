<?php

namespace App\Services\SellerIntelligence\Producers;

use App\Models\Product;
use App\Models\Seller;
use App\Services\SellerIntelligence\InsightDraft;
use App\Services\SellerIntelligence\InsightProducer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stock about to cost the seller sales.
 *
 * Ranked by what it is worth, not by how low it is: a best-seller at three units is a bigger problem
 * than a product nobody buys at zero, and a seller with fifty low-stock lines needs to know which
 * one to restock first. "Worth" here is real — units actually sold in the last thirty days, from
 * delivered order details.
 */
class InventoryRiskProducer implements InsightProducer
{
    public const TYPE = 'INVENTORY_RISK';

    /** Below this many days of remaining supply, a moving product is worth flagging. */
    private const DAYS_OF_SUPPLY_THRESHOLD = 7;

    private const LOOKBACK_DAYS = 30;

    public function type(): string
    {
        return self::TYPE;
    }

    public function produce(int|string $sellerId): iterable
    {
        if (!Schema::hasTable('products') || !Schema::hasTable('order_details')) {
            return [];
        }

        $stockLimit = $this->stockLimitFor($sellerId);
        $sales = $this->recentSales($sellerId);

        $products = Product::query()
            ->where(['added_by' => 'seller', 'user_id' => $sellerId, 'product_type' => 'physical'])
            ->where('current_stock', '<=', max($stockLimit, 0))
            ->get(['id', 'name', 'current_stock', 'unit_price']);

        foreach ($products as $product) {
            $sold = (float) ($sales[$product->id] ?? 0);
            $stock = (int) $product->current_stock;

            // A product that has not sold in a month is not urgent, however little is left of it.
            if ($sold <= 0 && $stock > 0) {
                continue;
            }

            $perDay = $sold / self::LOOKBACK_DAYS;
            $daysLeft = $perDay > 0 ? $stock / $perDay : null;

            if ($daysLeft !== null && $daysLeft > self::DAYS_OF_SUPPLY_THRESHOLD) {
                continue;
            }

            yield new InsightDraft(
                sellerId: $sellerId,
                type: self::TYPE,
                severity: $this->severityFor($stock, $sold),
                title: $stock <= 0 ? 'insight_out_of_stock' : 'insight_running_out',
                body: $product->name,
                entityType: 'product',
                entityId: $product->id,
                metric: $stock,
                // What a month at the current rate would have been worth, so the list can be read
                // as "fix this one first".
                impact: round($sold * (float) $product->unit_price, 2),
                actionKey: 'open_product',
                actionParams: ['product_id' => $product->id, 'days_of_supply' => $daysLeft === null ? null : round($daysLeft, 1)],
            );
        }
    }

    /** Units of each product actually delivered in the lookback window. */
    private function recentSales(int|string $sellerId): array
    {
        return DB::table('order_details')
            ->join('products', 'products.id', '=', 'order_details.product_id')
            ->where(['products.added_by' => 'seller', 'products.user_id' => $sellerId])
            ->where('order_details.delivery_status', 'delivered')
            ->where('order_details.created_at', '>=', now()->subDays(self::LOOKBACK_DAYS))
            ->groupBy('order_details.product_id')
            // Aliased, then plucked by that alias: pluck reads a property off the result row, so a
            // raw expression as the column name finds nothing.
            ->selectRaw('order_details.product_id as product_id, SUM(order_details.qty) as units')
            ->pluck('units', 'product_id')
            ->all();
    }

    /** The seller's own threshold, or the platform default when they have not set one. */
    private function stockLimitFor(int|string $sellerId): int
    {
        $limit = (int) Seller::where('id', $sellerId)->value('stock_limit');

        return $limit > 0 ? $limit : (int) getWebConfig(name: 'stock_limit');
    }

    private function severityFor(int $stock, float $sold): string
    {
        if ($stock <= 0 && $sold > 0) {
            // Out of stock and selling: every day is a lost sale.
            return 'critical';
        }

        return $stock <= 0 ? 'medium' : 'high';
    }
}
