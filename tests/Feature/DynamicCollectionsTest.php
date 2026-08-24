<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductCollection;
use App\Models\ProductMetric;
use App\Services\Commerce\CollectionResolver;
use App\Services\Commerce\CollectionRuleRegistry;
use App\Services\Theme\ContentSource;
use App\Services\Theme\SectionDataResolver;
use App\Services\Theme\ThemeSourceMap;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesCatalogueSchema;
use Tests\TestCase;

/**
 * Dynamic Collections (Phase 3.1).
 *
 * The promises under test, in the order they matter to production:
 * safety — the rule registry refuses what it does not allowlist, so admin input can never become
 * SQL it did not mean; opt-in — a page whose sections never name a collection is untouched;
 * fail-soft — a broken, disabled or deleted collection resolves to an empty list, which the
 * storefront already renders as "no section" rather than an error; and honesty — the metrics a
 * collection ranks by are recomputed from tables the platform genuinely writes.
 */
class DynamicCollectionsTest extends TestCase
{
    use CreatesCatalogueSchema;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        session(['local' => 'en']);
        config(['commerce.enabled' => true]);
        $this->createCatalogueSchema();

        // The shared catalogue schema stops at what the placement services read; the featured
        // flag is a rule field here, so this suite adds it.
        if (!Schema::hasColumn('products', 'featured')) {
            Schema::table('products', function (Blueprint $table) {
                $table->integer('featured')->default(0);
            });
        }

        foreach (['product_collections', 'product_metrics', 'order_details', 'wishlists', 'analytics_daily'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::create('product_collections', function (Blueprint $table) {
            $table->id(); $table->string('name', 120); $table->string('slug', 60)->unique();
            $table->boolean('status')->default(true); $table->json('rules')->nullable();
            $table->string('sort_by', 40)->default('sales_30d'); $table->timestamps();
        });
        Schema::create('product_metrics', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('product_id')->unique();
            $table->unsignedBigInteger('sales_30d')->default(0);
            $table->unsignedBigInteger('views_30d')->default(0);
            $table->unsignedBigInteger('carted_30d')->default(0);
            $table->decimal('rating', 4, 2)->default(0);
            $table->unsignedBigInteger('wishlist_count')->default(0);
            $table->timestamp('computed_at')->nullable();
        });
        Schema::create('order_details', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('order_id')->nullable(); $table->timestamps();
        });
        Schema::create('wishlists', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('product_id')->nullable(); $table->timestamps();
        });
        Schema::create('analytics_daily', function (Blueprint $table) {
            $table->id(); $table->date('date'); $table->string('dimension', 32);
            $table->string('dimension_key', 191);
            $table->unsignedBigInteger('pageviews')->default(0);
            $table->unsignedBigInteger('cart_adds')->default(0);
            $table->timestamp('computed_at')->nullable();
        });
    }

    private function product(array $attributes = []): Product
    {
        return Product::query()->create($attributes + [
            'name' => 'Product', 'added_by' => 'admin', 'product_type' => 'physical',
            'status' => 1, 'request_status' => 1, 'current_stock' => 10, 'unit_price' => 10,
        ]);
    }

    private function collection(array $rules = [], string $sort = 'sales_30d', bool $status = true): ProductCollection
    {
        static $sequence = 0;

        return ProductCollection::create([
            'name' => 'Collection ' . (++$sequence), 'slug' => 'collection-' . $sequence,
            'status' => $status, 'rules' => $rules, 'sort_by' => $sort,
        ]);
    }

    // ---- the rule registry: allowlist or refusal --------------------------------------------

    public function test_the_registry_refuses_what_it_does_not_allowlist(): void
    {
        $checked = app(CollectionRuleRegistry::class)->validate([
            ['field' => 'price', 'operator' => 'less_than', 'value' => '50'],
            ['field' => 'products.name; DROP TABLE products', 'operator' => 'equals', 'value' => 'x'],
            ['field' => 'price', 'operator' => 'in', 'value' => [1, 2]],
            ['field' => 'category', 'operator' => 'in', 'value' => 'not,numbers,at,all'],
        ]);

        $this->assertCount(1, $checked['rules'], 'only the well-formed rule survives');
        $this->assertSame('price', $checked['rules'][0]['field']);
        $this->assertCount(3, $checked['errors'], 'every refusal is named, never silently dropped');
    }

    public function test_a_between_value_is_ordered_and_a_set_is_cleaned(): void
    {
        $checked = app(CollectionRuleRegistry::class)->validate([
            ['field' => 'price', 'operator' => 'between', 'value' => ['90', '10']],
            ['field' => 'brand', 'operator' => 'in', 'value' => '3, 7, 3, 0, x'],
        ]);

        $this->assertSame([10.0, 90.0], $checked['rules'][0]['value']);
        $this->assertSame([3, 7], $checked['rules'][1]['value']);
    }

    // ---- resolution -------------------------------------------------------------------------

    public function test_rules_and_ranking_decide_the_products(): void
    {
        $cheap = $this->product(['name' => 'Cheap', 'unit_price' => 5]);
        $mid = $this->product(['name' => 'Mid', 'unit_price' => 20]);
        $this->product(['name' => 'Expensive', 'unit_price' => 200]);

        ProductMetric::create(['product_id' => $cheap->id, 'sales_30d' => 2]);
        ProductMetric::create(['product_id' => $mid->id, 'sales_30d' => 9]);

        $collection = $this->collection([
            ['field' => 'price', 'operator' => 'less_than', 'value' => 100],
        ]);

        $names = app(CollectionResolver::class)->resolve($collection, 10)->pluck('name')->all();

        $this->assertSame(['Mid', 'Cheap'], $names, 'filtered by the rule, ranked by sales');
    }

    public function test_a_product_without_a_metrics_row_still_belongs(): void
    {
        $this->product(['name' => 'Brand new']);

        $collection = $this->collection([
            ['field' => 'sales_30d', 'operator' => 'less_than', 'value' => 5],
        ]);

        $this->assertSame(
            ['Brand new'],
            app(CollectionResolver::class)->resolve($collection, 10)->pluck('name')->all(),
            'no metrics row means zero recorded sales — which is less than five',
        );
    }

    public function test_an_inactive_product_never_appears_whatever_the_rules_say(): void
    {
        $this->product(['name' => 'Hidden', 'status' => 0]);

        $this->assertCount(0, app(CollectionResolver::class)->resolve($this->collection(), 10));
    }

    // ---- fail-soft --------------------------------------------------------------------------

    public function test_disabled_deleted_or_switched_off_resolves_to_nothing_not_an_error(): void
    {
        $this->product();
        $resolver = app(CollectionResolver::class);

        $off = $this->collection(status: false);
        $this->assertCount(0, $resolver->resolve($off->id, 10), 'disabled collection');
        $this->assertCount(0, $resolver->resolve(999999, 10), 'deleted collection');

        config(['commerce.enabled' => false]);
        $live = $this->collection();
        $this->assertCount(0, $resolver->resolve($live->id, 10), 'engine switched off (§79 rollback)');
    }

    public function test_a_stored_rule_the_registry_no_longer_knows_serves_nothing_rather_than_more(): void
    {
        $this->product();
        $collection = $this->collection();
        // Simulate a rule written by a future build or a corrupted row: it bypassed validate().
        $collection->forceFill(['rules' => [['field' => 'zodiac_sign', 'operator' => 'equals', 'value' => 'leo']]])->save();

        $this->assertCount(0, app(CollectionResolver::class)->resolve($collection->id, 10),
            'a collection that cannot mean what it says must not quietly mean something broader');
    }

    // ---- the seams both clients read --------------------------------------------------------

    public function test_a_section_sourced_from_a_collection_resolves_through_the_same_engine(): void
    {
        $this->product(['name' => 'Chosen', 'unit_price' => 5]);
        $this->product(['name' => 'Refused', 'unit_price' => 500]);
        $collection = $this->collection([
            ['field' => 'price', 'operator' => 'less_than', 'value' => 100],
        ]);

        $products = app(SectionDataResolver::class)->productsFrom(
            ContentSource::fromSettings(['source' => 'collection', 'collection_id' => $collection->id, 'limit' => 8]),
        );

        $this->assertSame(['Chosen'], $products->pluck('name')->all());
    }

    public function test_the_app_is_hinted_at_the_collection_endpoint(): void
    {
        $source = app(ThemeSourceMap::class)->products([
            'source' => 'collection', 'collection_id' => 7, 'limit' => 8,
        ]);

        $this->assertSame('api', $source['kind']);
        $this->assertSame('/api/v1/products/theme-collection', $source['endpoint']);
        $this->assertSame(7, $source['params']['id']);
    }

    public function test_existing_sources_are_untouched_by_the_new_kind(): void
    {
        // §7: nothing opts in by itself. A featured section resolves exactly as before.
        $this->product(['name' => 'Featured one', 'featured' => 1]);

        $products = app(SectionDataResolver::class)->productsFrom(
            ContentSource::fromSettings(['source' => 'featured', 'limit' => 8]),
        );

        $this->assertSame(['Featured one'], $products->pluck('name')->all());
    }

    // ---- the metrics command ----------------------------------------------------------------

    public function test_metrics_are_recomputed_from_what_the_platform_records(): void
    {
        $product = $this->product(['name' => 'Measured']);

        DB::table('order_details')->insert([
            ['product_id' => $product->id, 'created_at' => now()->subDays(3), 'updated_at' => now()],
            ['product_id' => $product->id, 'created_at' => now()->subDays(5), 'updated_at' => now()],
            // Outside the 30-day window: must not count.
            ['product_id' => $product->id, 'created_at' => now()->subDays(60), 'updated_at' => now()],
        ]);
        DB::table('wishlists')->insert(['product_id' => $product->id, 'customer_id' => 1]);
        DB::table('reviews')->insert([
            'product_id' => $product->id, 'rating' => 4, 'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('analytics_daily')->insert([
            'date' => now()->subDays(2)->toDateString(), 'dimension' => 'product',
            'dimension_key' => (string) $product->id, 'pageviews' => 31, 'cart_adds' => 4,
        ]);

        Artisan::call('commerce:metrics-refresh');

        $metric = ProductMetric::query()->where('product_id', $product->id)->first();
        $this->assertSame(2, $metric->sales_30d, 'the 60-day-old sale is outside the window');
        $this->assertSame(31, $metric->views_30d);
        $this->assertSame(4, $metric->carted_30d);
        $this->assertSame(1, $metric->wishlist_count);
        $this->assertEqualsWithDelta(4.0, $metric->rating, 0.01);
    }

    public function test_the_sweep_removes_metrics_for_products_that_are_gone(): void
    {
        ProductMetric::create(['product_id' => 424242, 'sales_30d' => 9]);

        Artisan::call('commerce:metrics-refresh');

        $this->assertNull(ProductMetric::query()->where('product_id', 424242)->first());
    }
}
