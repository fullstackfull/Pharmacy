<?php

namespace App\Services\Telemetry;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Reads telemetry_daily (plus today's raw rows, so "today" is never blank)
 * into everything the Analytics page shows. All queries hit the compact
 * rollup table; the only raw-table read is the current day's totals.
 */
class AnalyticsService
{
    /** @return array<string, mixed> */
    public function overview(int $days): array
    {
        $from = Carbon::today()->subDays($days - 1);

        return [
            'days' => $days,
            'trend' => $this->trend($from),
            'totals' => $this->totals($from),
            'sources' => $this->top('source', $from, 10),
            'utm_sources' => $this->top('utm_source', $from, 10),
            'devices' => $this->top('device', $from, 6),
            'products' => $this->topProducts($from, 15),
            'shops' => $this->topShops($from, 15),
            'api_groups' => $this->top('api_group', $from, 12),
            'paths' => $this->top('path', $from, 15),
        ];
    }

    private function trend(Carbon $from): array
    {
        $rows = DB::table('telemetry_daily')
            ->where('scope', 'totals')->whereIn('key', ['web', 'api', 'orders'])
            ->where('date', '>=', $from->toDateString())
            ->get()->groupBy('date');

        $days = [];
        $cursor = $from->copy();
        $today = Carbon::today();
        while ($cursor <= $today) {
            $key = $cursor->toDateString();
            $dayRows = $rows->get($key, collect())->keyBy('key');
            $days[] = [
                'date' => $key,
                'web' => (int) optional($dayRows->get('web'))->hits,
                'visitors' => (int) optional($dayRows->get('web'))->visitors,
                'api' => (int) optional($dayRows->get('api'))->hits,
                'orders' => (int) optional($dayRows->get('orders'))->orders,
                'revenue' => (float) (optional($dayRows->get('orders'))->revenue ?? 0),
            ];
            $cursor->addDay();
        }

        // Today's rollup may be an hour old; splice live numbers in.
        $todayKey = $today->toDateString();
        $live = DB::table('telemetry_requests')->where('created_at', '>=', $today)
            ->selectRaw("channel, COUNT(*) hits, COUNT(DISTINCT visitor_id) visitors")->groupBy('channel')->get()->keyBy('channel');
        $liveOrders = DB::table('orders')->where('created_at', '>=', $today)
            ->selectRaw('COUNT(*) c, COALESCE(SUM(order_amount), 0) amount')->first();
        foreach ($days as &$day) {
            if ($day['date'] === $todayKey) {
                $day['web'] = max($day['web'], (int) optional($live->get('web'))->hits);
                $day['visitors'] = max($day['visitors'], (int) optional($live->get('web'))->visitors);
                $day['api'] = max($day['api'], (int) optional($live->get('api'))->hits);
                $day['orders'] = max($day['orders'], (int) $liveOrders->c);
                $day['revenue'] = max($day['revenue'], (float) $liveOrders->amount);
            }
        }

        return $days;
    }

    private function totals(Carbon $from): array
    {
        $trend = $this->trend($from);
        return [
            'web_hits' => array_sum(array_column($trend, 'web')),
            'visitors' => array_sum(array_column($trend, 'visitors')),
            'api_hits' => array_sum(array_column($trend, 'api')),
            'orders' => array_sum(array_column($trend, 'orders')),
            'revenue' => array_sum(array_column($trend, 'revenue')),
        ];
    }

    private function top(string $scope, Carbon $from, int $limit): array
    {
        return DB::table('telemetry_daily')
            ->where('scope', $scope)->where('date', '>=', $from->toDateString())
            ->selectRaw('`key`, SUM(hits) hits, SUM(visitors) visitors, SUM(errors) errors, ROUND(AVG(avg_duration_ms)) avg_ms')
            ->groupBy('key')->orderByDesc(DB::raw('SUM(hits)'))->limit($limit)->get()
            ->map(fn ($row) => [
                'key' => $row->key,
                'hits' => (int) $row->hits,
                'visitors' => (int) $row->visitors,
                'errors' => (int) $row->errors,
                'avg_ms' => $row->avg_ms !== null ? (int) $row->avg_ms : null,
            ])->all();
    }

    /** Views joined with the catalogue and each product's sold count for conversion. */
    private function topProducts(Carbon $from, int $limit): array
    {
        $views = $this->top('product', $from, $limit);
        if ($views === []) {
            return [];
        }
        $ids = array_map(fn ($row) => (int) $row['key'], $views);
        $products = DB::table('products')->whereIn('id', $ids)->pluck('name', 'id');
        $sold = DB::table('order_details')->whereIn('product_id', $ids)
            ->where('created_at', '>=', $from)->selectRaw('product_id, COUNT(*) c')
            ->groupBy('product_id')->pluck('c', 'product_id');

        return array_values(array_filter(array_map(function ($row) use ($products, $sold) {
            $id = (int) $row['key'];
            if (!isset($products[$id])) {
                return null;
            }
            $orders = (int) ($sold[$id] ?? 0);
            return [
                'id' => $id,
                'name' => $products[$id],
                'views' => $row['hits'],
                'visitors' => $row['visitors'],
                'orders' => $orders,
                'conversion_pct' => $row['visitors'] > 0 ? round(100 * $orders / $row['visitors'], 1) : null,
            ];
        }, $views)));
    }

    /** Shop visits joined with each vendor's sales over the same window. */
    private function topShops(Carbon $from, int $limit): array
    {
        $visits = $this->top('shop', $from, $limit);
        if ($visits === []) {
            return [];
        }
        $ids = array_map(fn ($row) => (int) $row['key'], $visits);
        $shops = DB::table('shops')->whereIn('id', $ids)->get(['id', 'name', 'slug', 'seller_id'])->keyBy('id');
        $sellerIds = $shops->pluck('seller_id')->filter()->all();
        $sales = DB::table('orders')->whereIn('seller_id', $sellerIds)
            ->where('seller_is', 'seller')->where('created_at', '>=', $from)
            ->selectRaw('seller_id, COUNT(*) c, COALESCE(SUM(order_amount), 0) amount')
            ->groupBy('seller_id')->get()->keyBy('seller_id');

        return array_values(array_filter(array_map(function ($row) use ($shops, $sales) {
            $shop = $shops->get((int) $row['key']);
            if (!$shop) {
                return null;
            }
            $sale = $sales->get($shop->seller_id);
            return [
                'id' => $shop->id,
                'seller_id' => $shop->seller_id,
                'name' => $shop->name,
                'slug' => $shop->slug,
                'visits' => $row['hits'],
                'visitors' => $row['visitors'],
                'orders' => (int) optional($sale)->c,
                'revenue' => (float) (optional($sale)->amount ?? 0),
            ];
        }, $visits)));
    }
}
