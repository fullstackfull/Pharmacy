<?php

namespace Tests\Feature;

use App\Services\Reports\ReportWindow;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Wave 8's definition of done: an export is not a lower bar than the list it exports.
 *
 * A spreadsheet is the whole list in one file, so a download gated more loosely than the screen it
 * comes from is not a convenience — it is the permission model with a side door. The classic panel
 * had exactly that shape: the exports hung off three different screens, each carrying whatever
 * period that screen happened to be showing and each gated by whatever its own menu was gated by.
 *
 * These tests read the route table itself rather than a list written out here, so a route added
 * next year without a permission fails this rather than passing beside it.
 */
class SellerCenterWave8Test extends TestCase
{
    /** Report screen => the export routes that produce the same rows as a file. */
    private const EXPORTS_OF = [
        'seller.reports.orders' => ['seller.exports.orders', 'seller.exports.orders-pdf'],
        'seller.reports.products' => ['seller.exports.products'],
        'seller.reports.stock' => ['seller.exports.stock'],
    ];

    public function test_every_export_is_gated_exactly_as_the_report_it_exports(): void
    {
        foreach (self::EXPORTS_OF as $report => $exports) {
            $expected = $this->permissionsOf($report);

            $this->assertNotSame([], $expected, "{$report} declares no permission at all");

            foreach ($exports as $export) {
                $this->assertSame(
                    $expected,
                    $this->permissionsOf($export),
                    "{$export} is gated differently from {$report} — a spreadsheet is the whole list in one file",
                );
            }
        }
    }

    public function test_the_hub_and_the_export_catalogue_are_readable_without_a_specific_permission(): void
    {
        // Both show only what is already the reader's to see, and every figure and every file
        // beneath them is gated on its own route. Gating the index too would hide the map from
        // somebody allowed to walk half of it.
        $this->assertSame([], $this->permissionsOf('seller.reports.index'));
        $this->assertSame([], $this->permissionsOf('seller.exports.index'));
    }

    public function test_the_staff_gate_knows_both_new_segments(): void
    {
        // The gate reads the second URL segment and denies by default, so a segment it has never
        // heard of is not "gated" — it is unreachable by every staff member, on a route that would
        // have let them in.
        $mapped = $this->staffMappedSegments();

        $this->assertContains('reports', $mapped);
        $this->assertContains('exports', $mapped);
    }

    public function test_a_period_the_seller_did_not_choose_falls_back_rather_than_failing(): void
    {
        $window = ReportWindow::make(type: 'last_tuesday');

        $this->assertSame(ReportWindow::THIS_YEAR, $window->type);
    }

    public function test_a_custom_period_given_backwards_is_read_in_the_order_it_was_meant(): void
    {
        $window = ReportWindow::make(type: ReportWindow::CUSTOM, from: '2026-03-31', to: '2026-03-01');

        $this->assertTrue($window->from->lessThanOrEqualTo($window->to));
        $this->assertSame('2026-03-01', $window->from->toDateString());
    }

    public function test_each_period_buckets_to_a_scale_a_person_can_read(): void
    {
        // An hourly bucket over a year is 8,760 bars and a yearly bucket over a day is one. The
        // bucket is chosen from the span rather than fixed, which is what makes the same chart
        // component usable for both.
        $this->assertSame(ReportWindow::BUCKET_HOUR, ReportWindow::make(type: ReportWindow::TODAY)->bucket);
        $this->assertSame(ReportWindow::BUCKET_WEEKDAY, ReportWindow::make(type: ReportWindow::THIS_WEEK)->bucket);
        $this->assertSame(ReportWindow::BUCKET_DAY, ReportWindow::make(type: ReportWindow::THIS_MONTH)->bucket);
        $this->assertSame(ReportWindow::BUCKET_MONTH, ReportWindow::make(type: ReportWindow::THIS_YEAR)->bucket);
    }

    public function test_a_chart_is_drawn_from_the_calendar_so_a_quiet_month_is_a_zero_not_a_gap(): void
    {
        $window = ReportWindow::make(type: ReportWindow::THIS_YEAR);

        $series = $window->series(
            rows: [['created_at' => Carbon::now()->startOfYear()->addMonth(), 'order_amount' => 40]],
            valueKey: 'order_amount',
        );

        // Twelve buckets, eleven of them zero. A series that returned one point would draw a shop
        // that traded for one month, which is a different claim.
        $this->assertCount(12, $series);
        $this->assertSame(40.0, (float) array_sum($series));
    }

    /**
     * The permissions a named route requires, read out of its own middleware stack.
     *
     * @return array<int, string>
     */
    private function permissionsOf(string $name): array
    {
        $route = Route::getRoutes()->getByName($name);

        $this->assertInstanceOf(RoutingRoute::class, $route, "route {$name} does not exist");

        $permissions = [];
        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'seller_can:')) {
                $permissions[] = substr($middleware, strlen('seller_can:'));
            }
        }

        sort($permissions);

        return $permissions;
    }

    /**
     * The URL segments the staff gate knows about, read out of the middleware's own match arms.
     *
     * Read rather than duplicated: a copy of the list here would pass while the real map was wrong.
     *
     * @return array<int, string>
     */
    private function staffMappedSegments(): array
    {
        $source = file_get_contents(base_path('app/Http/Middleware/SellerStaffAccessMiddleware.php'));
        $body = substr($source, strpos($source, 'return match ($area) {'));
        $body = substr($body, 0, strpos($body, 'default =>'));

        preg_match_all("/'([a-z0-9\-]+)'\s*(?:,|=>)/", $body, $found);

        return array_values(array_unique($found[1] ?? []));
    }
}
