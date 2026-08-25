<?php

namespace Tests\Feature;

use App\Jobs\RebuildProductSearchIndex;
use App\Models\BusinessSetting;
use App\Services\Monitoring\MonitoringNavigation;
use App\Services\Monitoring\Panels\PanelRegistry;
use App\Services\Monitoring\Panels\SearchIndexPanel;
use App\Services\Search\ProductSearchIndexer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesCatalogueSchema;
use Tests\TestCase;

/**
 * The search index, which nothing in the admin panel could see.
 *
 * Storefront search reads a normalised index rather than the products table. It is kept current by
 * a model observer that swallows its own failures — correctly, so an index write can never fail a
 * merchant's product save — and rebuilt by a weekly command. Neither of those can report anything,
 * and there was no page, no route and no setting for the index anywhere: an administrator could not
 * see how much of the catalogue was searchable, and a bulk import could leave half of it invisible
 * with no symptom but shoppers not finding things.
 *
 * These hold the three numbers that make it visible, and hold them to being counted rather than
 * assumed — a coverage figure that guessed would be worse than the blank page it replaced.
 */
class SearchIndexPanelTest extends TestCase
{
    use CreatesCatalogueSchema;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        foreach (['product_search_index', 'monitoring_scheduled_runs'] as $table) {
            Schema::dropIfExists($table);
        }

        $this->createCatalogueSchema();
        Schema::create('product_search_index', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('product_id'); $table->string('locale', 20)->default('default');
            $table->string('name_normalized', 512)->default(''); $table->text('text_normalized')->nullable();
            $table->timestamps();
        });
        Schema::create('monitoring_scheduled_runs', function (Blueprint $table) {
            $table->id(); $table->string('task', 191); $table->string('expression', 40)->nullable();
            $table->string('status', 12)->default('running'); $table->unsignedInteger('duration_ms')->nullable();
            $table->text('output')->nullable(); $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable(); $table->timestamp('finished_at')->nullable();
            $table->timestamp('expected_next_at')->nullable();
        });
    }

    public function test_the_section_is_reachable_and_has_a_panel_behind_it(): void
    {
        // A section in the rail with no panel opens on the "not installed" page, which is exactly
        // the dead end the operations centre marks rather than hides.
        $this->assertTrue(MonitoringNavigation::exists('search'));
        $this->assertTrue(PanelRegistry::has('search'));
    }

    public function test_coverage_is_counted_from_both_sides_rather_than_assumed(): void
    {
        $this->product(1, indexed: true);
        $this->product(2, indexed: true);
        $this->product(3, indexed: false);

        $data = $this->panel();

        $this->assertSame(3, $data['metrics']['catalogue_products']->value);
        $this->assertSame(2, $data['metrics']['indexed_products']->value);
        $this->assertSame(1, $data['metrics']['missing']->value, 'the product a bulk import would leave behind');
        $this->assertEqualsWithDelta(66.7, $data['metrics']['coverage']->value, 0.1);
    }

    public function test_a_product_edited_after_it_was_indexed_counts_as_stale(): void
    {
        // The observer falling behind rather than never having run: the row exists, and search
        // answers under text the merchant has already changed.
        $this->product(1, indexed: true);
        DB::table('products')->where('id', 1)->update(['updated_at' => now()->addHour()]);

        $this->assertSame(1, $this->panel()['metrics']['stale']->value);
    }

    public function test_a_row_with_no_searchable_name_is_counted_separately(): void
    {
        // Indexed and unfindable. Every count that does not look inside a row calls this healthy.
        $this->product(1, indexed: true);
        DB::table('product_search_index')->where('product_id', 1)->update(['name_normalized' => '']);

        $data = $this->panel();
        $this->assertSame(1, $data['metrics']['indexed_products']->value);
        $this->assertSame(1, $data['metrics']['empty_names']->value);
    }

    public function test_rows_are_broken_down_by_language(): void
    {
        $this->product(1, indexed: true);
        DB::table('product_search_index')->insert([
            'product_id' => 1, 'locale' => 'ar', 'name_normalized' => 'كريم',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame(
            [['locale' => 'ar', 'rows' => 1], ['locale' => 'default', 'rows' => 1]],
            $this->panel()['locales'],
        );
    }

    public function test_the_weekly_rebuild_is_read_from_the_scheduler_own_record(): void
    {
        // The same table the scheduler page reads, so the two can never tell different stories
        // about the same task.
        DB::table('monitoring_scheduled_runs')->insert([
            'task' => "php artisan 'search:reindex-products'", 'status' => 'success',
            'started_at' => now()->subHours(6), 'finished_at' => now()->subHours(6)->addMinutes(2),
            'duration_ms' => 120000,
        ]);

        $task = $this->panel()['task'];

        $this->assertSame('success', $task['status']);
        $this->assertEqualsWithDelta(6.0, $task['age_hours'], 0.2);
    }

    public function test_a_missing_index_table_says_so_instead_of_reporting_zero_coverage(): void
    {
        // Zero indexed products and no index table are opposite facts. Reporting the first for the
        // second is the fabricated-zero this whole system exists to avoid.
        Schema::drop('product_search_index');

        $data = $this->panel();

        $this->assertFalse($data['available']);
        $this->assertSame([], $data['metrics']);
    }

    // ---- the one write on the page ----------------------------------------------------------

    public function test_the_rebuild_writes_down_what_it_did(): void
    {
        $this->product(1, indexed: false);

        (new RebuildProductSearchIndex('Layla'))->handle(app(ProductSearchIndexer::class));

        $rebuild = $this->panel()['rebuild'];
        $this->assertNotNull($rebuild, 'a rebuild that leaves no trace is one the page cannot report');
        $this->assertSame('Layla', $rebuild['requested_by']);
        $this->assertSame(1, $rebuild['indexed']);
    }

    public function test_the_rebuild_marker_survives_a_shop_with_no_settings_table(): void
    {
        Schema::drop('business_settings');

        (new RebuildProductSearchIndex())->handle(app(ProductSearchIndexer::class));

        $this->assertTrue(true, 'recording where it finished must never fail the work it finished');
    }

    public function test_only_one_rebuild_may_be_in_flight(): void
    {
        // Pressing the button twice, or pressing it while the weekly task runs, must not start a
        // second walk over the whole catalogue.
        $this->assertSame(
            (new RebuildProductSearchIndex('a'))->uniqueId(),
            (new RebuildProductSearchIndex('b'))->uniqueId(),
        );
    }

    // ---- the page itself ---------------------------------------------------------------------

    public function test_the_section_renders_the_numbers_and_the_way_to_fix_them(): void
    {
        // A panel that compiles is not a page that renders. This one draws Metric objects, a
        // permission-gated form and a component library, and none of that is exercised by asking
        // the panel for an array.
        $this->product(1, indexed: true);
        $this->product(2, indexed: false);
        session(['local' => 'en']);

        $html = view('admin-views.monitoring.sections.search', [
            'panel' => $this->panel(),
            'range' => '24h',
            'section' => 'search',
            'permissions' => app(\App\Services\Monitoring\MonitoringPermissionService::class),
        ])->render();

        $this->assertStringContainsString('php artisan search:reindex-products', $html,
            'the command, for a shop with no queue worker to take the button');
        $this->assertStringContainsString('mon-grid', $html);
    }

    public function test_the_section_says_the_index_is_missing_rather_than_drawing_zeroes(): void
    {
        Schema::drop('product_search_index');
        session(['local' => 'en']);

        $html = view('admin-views.monitoring.sections.search', [
            'panel' => $this->panel(),
            'range' => '24h',
            'section' => 'search',
            'permissions' => app(\App\Services\Monitoring\MonitoringPermissionService::class),
        ])->render();

        $this->assertStringNotContainsString('mon-grid', $html);
    }

    // ---- helpers -----------------------------------------------------------------------------

    private function panel(): array
    {
        return app(SearchIndexPanel::class)->data('24h', Request::create('/'));
    }

    private function product(int $id, bool $indexed): void
    {
        DB::table('products')->insert([
            'id' => $id, 'name' => 'Product ' . $id, 'details' => 'details',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        if ($indexed) {
            DB::table('product_search_index')->insert([
                'product_id' => $id, 'locale' => 'default', 'name_normalized' => 'product ' . $id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }
}
