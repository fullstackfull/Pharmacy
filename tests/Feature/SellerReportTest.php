<?php

namespace Tests\Feature;

use App\Services\Reports\ReportWindow;
use App\Services\Reports\SellerReportService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The seller reports, and the period they are read over.
 *
 * The properties asserted: a period resolves to the calendar range it names and to a bucket size
 * that suits it; a chart is drawn from the calendar rather than from the rows, so an empty month is
 * a flat line and not a gap; a seller's report contains that seller's rows and no one else's; and
 * the payment breakdown counts what an order edit added and takes back what it returned.
 *
 * These matter because the vendor panel and the seller app now read the same service. A figure that
 * is wrong here is wrong in two places at once — and a seller who sees one number in the app and
 * another in the panel trusts neither.
 */
class SellerReportTest extends TestCase
{
    private SellerReportService $reports;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['order_details', 'orders', 'products', 'translations', 'reviews', 'categories', 'sellers', 'order_edit_histories', 'business_settings'] as $table) {
            Schema::dropIfExists($table);
        }

        // The product model resolves settings and translations while hydrating, so the report cannot
        // be exercised against products alone.
        Schema::create('business_settings', function (Blueprint $table) {
            $table->id();
            $table->string('type')->nullable();
            $table->text('value')->nullable();
            $table->timestamps();
        });
        Schema::create('translations', function (Blueprint $table) {
            $table->id();
            $table->string('translationable_type')->nullable();
            $table->unsignedBigInteger('translationable_id')->nullable();
            $table->string('locale', 10)->nullable();
            $table->string('key')->nullable();
            $table->text('value')->nullable();
            $table->timestamps();
        });
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->integer('rating')->default(0);
            $table->boolean('status')->default(1);
            $table->timestamps();
        });
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->integer('position')->default(0);
            $table->timestamps();
        });
        Schema::create('sellers', function (Blueprint $table) {
            $table->id();
            $table->string('f_name')->nullable();
            $table->integer('stock_limit')->default(0);
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('added_by', 20)->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('product_type', 20)->default('physical');
            $table->integer('request_status')->default(0);
            $table->integer('current_stock')->default(0);
            $table->decimal('unit_price', 24, 3)->default(0);
            $table->string('thumbnail')->nullable();
            $table->json('category_ids')->nullable();
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('seller_is', 20)->nullable();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->string('order_status', 30)->nullable();
            $table->string('payment_method', 40)->nullable();
            $table->decimal('order_amount', 24, 3)->default(0);
            $table->decimal('init_order_amount', 24, 3)->default(0);
            $table->timestamps();
        });

        // Amounts added or returned after an order was placed live here, not on the order row.
        Schema::create('order_edit_histories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->string('order_due_payment_method', 40)->nullable();
            $table->string('order_due_payment_status', 20)->nullable();
            $table->decimal('order_due_amount', 24, 3)->default(0);
            $table->string('order_return_payment_status', 20)->nullable();
            $table->decimal('order_return_amount', 24, 3)->default(0);
            $table->timestamps();
        });

        Schema::create('order_details', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('product_id');
            $table->string('delivery_status', 30)->nullable();
            $table->integer('qty')->default(0);
            $table->decimal('price', 24, 3)->default(0);
            $table->decimal('discount', 24, 3)->default(0);
            $table->decimal('tax', 24, 3)->default(0);
            $table->timestamps();
        });

        $this->reports = app(SellerReportService::class);
    }

    private function order(array $attributes = []): int
    {
        return DB::table('orders')->insertGetId(array_merge([
            'seller_is' => 'seller',
            'seller_id' => 1,
            'order_status' => 'delivered',
            'payment_method' => 'cash_on_delivery',
            'order_amount' => 100,
            'init_order_amount' => 100,
            'created_at' => Carbon::now()->startOfMonth()->addDay(),
            'updated_at' => Carbon::now(),
        ], $attributes));
    }

    private function product(array $attributes = []): int
    {
        return DB::table('products')->insertGetId(array_merge([
            'name' => 'A product',
            'added_by' => 'seller',
            'user_id' => 1,
            'product_type' => 'physical',
            'request_status' => 1,
            'current_stock' => 5,
            'unit_price' => 10,
            'created_at' => Carbon::now()->startOfMonth()->addDay(),
            'updated_at' => Carbon::now(),
        ], $attributes));
    }

    public function test_a_period_resolves_to_the_calendar_range_it_names(): void
    {
        $month = ReportWindow::make('this_month');
        $this->assertSame(Carbon::now()->startOfMonth()->toDateString(), $month->from->toDateString());
        $this->assertSame(Carbon::now()->endOfMonth()->toDateString(), $month->to->toDateString());

        $year = ReportWindow::make('this_year');
        $this->assertSame(Carbon::now()->startOfYear()->toDateString(), $year->from->toDateString());

        // An unknown type is the panel's long-standing default, not an unbounded query.
        $this->assertSame(ReportWindow::THIS_YEAR, ReportWindow::make('last_decade')->type);
        $this->assertSame(ReportWindow::THIS_YEAR, ReportWindow::make(null)->type);
    }

    public function test_a_custom_range_is_ordered_bounded_and_bucketed_by_what_it_spans(): void
    {
        // Reversed by a user who picked the end date first.
        $reversed = ReportWindow::make('custom_date', '2026-08-31', '2026-08-01');
        $this->assertSame('2026-08-01', $reversed->from->toDateString());
        $this->assertSame('2026-08-31', $reversed->to->toDateString());

        // A whole calendar month is 31 days, not 32: the fractional day an end-of-day boundary adds
        // used to push it past the day-bucket threshold and draw a month as two bars.
        $this->assertSame(ReportWindow::BUCKET_DAY, ReportWindow::make('custom_date', '2026-01-20', '2026-02-20')->bucket);
        $this->assertSame(ReportWindow::BUCKET_MONTH, ReportWindow::make('custom_date', '2026-01-01', '2026-08-01')->bucket);
        $this->assertSame(ReportWindow::BUCKET_YEAR, ReportWindow::make('custom_date', '2024-01-01', '2026-06-01')->bucket);

        // A range whose ends cannot be parsed is not a range.
        $this->assertSame(ReportWindow::THIS_YEAR, ReportWindow::make('custom_date', 'not-a-date', 'x')->type);
        $this->assertSame(ReportWindow::THIS_YEAR, ReportWindow::make('custom_date', '2026-01-01', null)->type);
    }

    public function test_a_day_bucket_carries_its_month_so_two_days_are_never_one_bar(): void
    {
        $window = ReportWindow::make('custom_date', '2026-01-20', '2026-02-20');

        // 20 Jan through 20 Feb is 32 days. Labelled by bare day number, the two 20ths would collide
        // and the chart would silently fold them together.
        $this->assertCount(32, $window->emptySeries());
        $this->assertSame(32, count(array_unique(array_keys($window->emptySeries()))));
    }

    public function test_a_chart_is_drawn_from_the_calendar_not_from_the_rows(): void
    {
        $window = ReportWindow::make('this_year');
        $series = $window->emptySeries();

        // Twelve months whether or not anything happened in them: an empty month must read as
        // "nothing sold", not disappear as "no data".
        $this->assertCount(12, $series);
        $this->assertSame(0, array_sum($series));
        $this->assertSame(array_keys($series), $window->seriesLabels());
    }

    public function test_the_report_covers_the_seller_asking_and_nobody_else(): void
    {
        $this->order(['order_amount' => 100, 'init_order_amount' => 100]);
        $this->order(['seller_id' => 2, 'order_amount' => 999, 'init_order_amount' => 999]);
        $this->order(['seller_is' => 'admin', 'seller_id' => 1, 'order_amount' => 555, 'init_order_amount' => 555]);

        $report = $this->reports->orderReport(1, ReportWindow::make('this_year'));

        $this->assertSame(1, $report['counts']['delivered']);
        $this->assertSame(100.0, $report['amounts']['settled']);
        $this->assertSame(100.0, $report['payments']['total']);
    }

    public function test_orders_are_counted_by_what_state_they_are_in(): void
    {
        $this->order(['order_status' => 'delivered']);
        $this->order(['order_status' => 'processing', 'order_amount' => 30]);
        $this->order(['order_status' => 'pending', 'order_amount' => 20]);
        $this->order(['order_status' => 'canceled', 'order_amount' => 70]);

        $report = $this->reports->orderReport(1, ReportWindow::make('this_year'));

        $this->assertSame(1, $report['counts']['delivered']);
        $this->assertSame(2, $report['counts']['ongoing']);
        $this->assertSame(1, $report['counts']['canceled']);
        $this->assertSame(4, $report['counts']['total']);

        // Due is what is still owed. A cancelled order is not owed, and a delivered one is settled.
        $this->assertSame(50.0, $report['amounts']['due']);
        $this->assertSame(100.0, $report['amounts']['settled']);
    }

    public function test_the_payment_breakdown_splits_by_how_the_money_arrived(): void
    {
        $this->order(['payment_method' => 'cash_on_delivery', 'init_order_amount' => 100]);
        $this->order(['payment_method' => 'pay_by_wallet', 'init_order_amount' => 40]);
        $this->order(['payment_method' => 'offline_payment', 'init_order_amount' => 25]);
        $this->order(['payment_method' => 'stripe', 'init_order_amount' => 35]);
        // Not delivered, so not yet money in hand.
        $this->order(['order_status' => 'processing', 'payment_method' => 'stripe', 'init_order_amount' => 500]);

        $payments = $this->reports->paymentBreakdown(1, ReportWindow::make('this_year'));

        $this->assertSame(100.0, $payments['cash']);
        $this->assertSame(40.0, $payments['wallet']);
        $this->assertSame(25.0, $payments['offline']);
        $this->assertSame(35.0, $payments['digital']);
        $this->assertSame(200.0, $payments['total']);
    }

    public function test_an_order_edited_after_delivery_is_counted_by_what_was_added_and_returned(): void
    {
        $orderId = $this->order(['payment_method' => 'cash_on_delivery', 'init_order_amount' => 100]);

        DB::table('order_edit_histories')->insert([
            // An item added afterwards and paid for by card: a different method from the order's own.
            ['order_id' => $orderId, 'order_due_payment_method' => 'stripe', 'order_due_payment_status' => 'paid',
                'order_due_amount' => 60, 'order_return_payment_status' => null, 'order_return_amount' => 0,
                'created_at' => Carbon::now(), 'updated_at' => Carbon::now()],
            // Added but never paid: not money the seller has.
            ['order_id' => $orderId, 'order_due_payment_method' => 'stripe', 'order_due_payment_status' => 'unpaid',
                'order_due_amount' => 500, 'order_return_payment_status' => null, 'order_return_amount' => 0,
                'created_at' => Carbon::now(), 'updated_at' => Carbon::now()],
            // An item sent back.
            ['order_id' => $orderId, 'order_due_payment_method' => null, 'order_due_payment_status' => null,
                'order_due_amount' => 0, 'order_return_payment_status' => 'returned', 'order_return_amount' => 25,
                'created_at' => Carbon::now(), 'updated_at' => Carbon::now()],
        ]);

        $payments = $this->reports->paymentBreakdown(1, ReportWindow::make('this_year'));

        $this->assertSame(100.0, $payments['cash']);
        $this->assertSame(60.0, $payments['digital']);
        $this->assertSame(25.0, $payments['returned']);
        // Reading only the order row would report 100 and miss both the addition and the return.
        $this->assertSame(135.0, $payments['total']);
    }

    public function test_a_chart_puts_each_order_in_the_period_it_happened_in(): void
    {
        $window = ReportWindow::make('this_year');
        $january = Carbon::now()->startOfYear()->addDays(5);
        $this->order(['order_amount' => 100, 'created_at' => $january]);
        $this->order(['order_amount' => 250, 'created_at' => $january->copy()->addDay()]);

        $report = $this->reports->orderReport(1, $window);

        $this->assertSame(350.0, (float) $report['chart'][$january->format('M')]);
        $this->assertSame(350.0, (float) array_sum($report['chart']));
    }

    public function test_the_product_report_counts_listings_by_their_approval_state(): void
    {
        $this->product(['request_status' => 1]);
        $this->product(['request_status' => 1]);
        $this->product(['request_status' => 0]);
        $this->product(['request_status' => 2]);
        $this->product(['request_status' => 1, 'user_id' => 2]);

        $report = $this->reports->productReport(1, ReportWindow::make('this_year'));

        $this->assertSame(2, $report['counts']['active']);
        $this->assertSame(1, $report['counts']['pending']);
        $this->assertSame(1, $report['counts']['rejected']);
    }

    public function test_the_product_report_totals_only_what_was_actually_delivered(): void
    {
        $productId = $this->product();
        $orderId = $this->order();

        DB::table('order_details')->insert([
            ['order_id' => $orderId, 'product_id' => $productId, 'delivery_status' => 'delivered',
                'qty' => 3, 'price' => 50, 'discount' => 10, 'tax' => 5,
                'created_at' => Carbon::now(), 'updated_at' => Carbon::now()],
            ['order_id' => $orderId, 'product_id' => $productId, 'delivery_status' => 'processing',
                'qty' => 9, 'price' => 50, 'discount' => 99, 'tax' => 5,
                'created_at' => Carbon::now(), 'updated_at' => Carbon::now()],
        ]);

        $report = $this->reports->productReport(1, ReportWindow::make('this_year'));

        $this->assertSame(3.0, $report['totals']['sold_quantity']);
        $this->assertSame(150.0, $report['totals']['sold_amount']);
        $this->assertSame(10.0, $report['totals']['discount_given']);
    }

    public function test_the_stock_report_is_about_now_not_about_a_period(): void
    {
        $this->product(['current_stock' => 2, 'created_at' => Carbon::now()->subYears(3)]);
        $this->product(['current_stock' => 40]);
        $this->product(['current_stock' => 0, 'product_type' => 'digital']);
        $this->product(['current_stock' => 7, 'user_id' => 2]);

        $products = $this->reports->stockQuery(1)->get();

        // The three-year-old product is still stock the seller holds; the digital one has none to
        // hold; and another seller's is not theirs.
        $this->assertCount(2, $products);
        $this->assertSame([2, 40], $products->pluck('current_stock')->map(fn ($n) => (int) $n)->all());
        $this->assertSame([40, 2], $this->reports->stockQuery(1, sort: 'desc')
            ->get()->pluck('current_stock')->map(fn ($n) => (int) $n)->all());
    }

    public function test_a_search_narrows_within_the_seller_rather_than_reaching_past_them(): void
    {
        $this->product(['name' => 'Vitamin C Serum']);
        $this->product(['name' => 'Hydrating Cream']);
        $this->product(['name' => 'Vitamin C Serum', 'user_id' => 2]);

        $found = $this->reports->productQuery(1, ReportWindow::make('this_year'), 'Vitamin')->get();

        $this->assertCount(1, $found);
        $this->assertSame(1, (int) $found->first()->user_id);
    }
}
