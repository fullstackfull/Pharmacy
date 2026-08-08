<?php

namespace Tests\Feature;

use App\Services\Vendor\VendorDashboardStatsService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class VendorDashboardStatsTest extends TestCase
{
    private VendorDashboardStatsService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new VendorDashboardStatsService();

        Schema::dropIfExists('orders');
        Schema::dropIfExists('products');

        Schema::create('orders', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('seller_id')->nullable();
            $t->decimal('order_amount', 20, 6)->default(0);
            $t->timestamps();
        });
        Schema::create('products', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('added_by')->nullable();
            $t->integer('current_stock')->default(0);
            $t->timestamps();
        });
    }

    private function order(int $sellerId, float $amount, string $createdAt): void
    {
        DB::table('orders')->insert([
            'seller_id' => $sellerId, 'order_amount' => $amount,
            'created_at' => $createdAt, 'updated_at' => $createdAt,
        ]);
    }

    // ---- honest comparison (the brief's explicit requirement) ----

    public function test_no_baseline_returns_null_change_not_a_fake_percentage(): void
    {
        $r = $this->svc->compare(500.0, null);

        $this->assertNull($r['change']);
        $this->assertNull($r['direction']);
        $this->assertSame('no_baseline', $r['state']);
    }

    public function test_growth_from_zero_is_reported_as_new_not_infinite(): void
    {
        $r = $this->svc->compare(300.0, 0.0);

        $this->assertNull($r['change']);          // no meaningless "+∞%" or "+100%"
        $this->assertSame('new', $r['state']);
        $this->assertSame(VendorDashboardStatsService::DIR_UP, $r['direction']);
    }

    public function test_normal_growth_and_decline_are_computed(): void
    {
        $up   = $this->svc->compare(150.0, 100.0);
        $down = $this->svc->compare(80.0, 100.0);

        $this->assertSame(50.0, $up['change']);
        $this->assertSame('up', $up['direction']);
        $this->assertSame(-20.0, $down['change']);
        $this->assertSame('down', $down['direction']);
    }

    public function test_unchanged_value_is_flat(): void
    {
        $r = $this->svc->compare(100.0, 100.0);

        $this->assertSame(0.0, $r['change']);
        $this->assertSame('flat', $r['direction']);
    }

    public function test_zero_current_against_positive_baseline_is_minus_one_hundred(): void
    {
        $r = $this->svc->compare(0.0, 200.0);

        $this->assertSame(-100.0, $r['change']);
        $this->assertSame('down', $r['direction']);
    }

    // ---- inventory alerts ----

    public function test_inventory_alerts_count_low_and_out_of_stock(): void
    {
        foreach ([[3, 'seller'], [0, 'seller'], [50, 'seller'], [2, 'seller'], [-1, 'seller']] as [$stock, $by]) {
            DB::table('products')->insert(['user_id' => 7, 'added_by' => $by, 'current_stock' => $stock, 'created_at' => now(), 'updated_at' => now()]);
        }
        // another seller's stock must not leak in
        DB::table('products')->insert(['user_id' => 8, 'added_by' => 'seller', 'current_stock' => 1, 'created_at' => now(), 'updated_at' => now()]);

        $alerts = $this->svc->inventoryAlerts(7);

        $this->assertSame(2, $alerts['low_stock']);     // 3 and 2
        $this->assertSame(2, $alerts['out_of_stock']);  // 0 and -1
    }

    public function test_inventory_alerts_respect_custom_threshold(): void
    {
        DB::table('products')->insert(['user_id' => 7, 'added_by' => 'seller', 'current_stock' => 9, 'created_at' => now(), 'updated_at' => now()]);

        $this->assertSame(0, $this->svc->inventoryAlerts(7, 5)['low_stock']);
        $this->assertSame(1, $this->svc->inventoryAlerts(7, 10)['low_stock']);
    }

    // ---- sales ----

    public function test_sales_summary_sums_only_this_sellers_orders_in_window(): void
    {
        $this->order(7, 100, '2026-08-05 10:00:00');
        $this->order(7, 50,  '2026-08-06 10:00:00');
        $this->order(8, 999, '2026-08-06 10:00:00');           // other seller
        $this->order(7, 500, '2026-07-01 10:00:00');           // outside window

        $summary = $this->svc->salesSummary(7, '2026-08-01 00:00:00', '2026-08-31 23:59:59');

        $this->assertSame(150.0, $summary['revenue']);
        $this->assertSame(2, $summary['orders']);
    }

    public function test_sales_comparison_reports_no_baseline_for_a_brand_new_shop(): void
    {
        // orders only inside the current window — nothing before it
        $this->order(7, 100, '2026-08-05 10:00:00');

        $r = $this->svc->salesWithComparison(7, '2026-08-01 00:00:00', '2026-08-31 23:59:59');

        $this->assertSame('no_baseline', $r['revenue']['state']);
        $this->assertNull($r['revenue']['change']);
    }

    public function test_sales_comparison_computes_against_the_previous_window(): void
    {
        $this->order(7, 100, '2026-07-10 10:00:00'); // previous window
        $this->order(7, 200, '2026-08-10 10:00:00'); // current window

        $r = $this->svc->salesWithComparison(7, '2026-08-01 00:00:00', '2026-08-31 23:59:59');

        $this->assertSame('comparable', $r['revenue']['state']);
        $this->assertSame(100.0, $r['revenue']['change']); // 100 -> 200 = +100%
        $this->assertSame('up', $r['revenue']['direction']);
    }

    public function test_missing_tables_degrade_to_zero_instead_of_fataling(): void
    {
        Schema::dropIfExists('orders');
        Schema::dropIfExists('products');

        $this->assertSame(['revenue' => 0.0, 'orders' => 0], $this->svc->salesSummary(7, '2026-01-01', '2026-12-31'));
        $this->assertSame(0, $this->svc->inventoryAlerts(7)['low_stock']);
    }
}
