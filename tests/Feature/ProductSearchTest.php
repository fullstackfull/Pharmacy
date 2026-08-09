<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductSearchIndex;
use App\Models\Translation;
use App\Services\Search\ArabicTextNormalizer;
use App\Services\Search\ProductSearchIndexer;
use App\Services\Search\ProductSearchService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The indexer and the ranked search built on it (Phase 2.1).
 *
 * The catalogue used here mirrors the real one: Latin product names with Arabic translations, and
 * the spelling that exposed the original defect — a product stored as "حمايه" that no customer
 * typing "حماية" could find.
 */
class ProductSearchTest extends TestCase
{
    private ProductSearchIndexer $indexer;
    private ProductSearchService $search;

    protected function setUp(): void
    {
        parent::setUp();

        $normalizer = new ArabicTextNormalizer();
        $this->indexer = new ProductSearchIndexer($normalizer);
        $this->search = new ProductSearchService($normalizer);

        Schema::dropIfExists('product_search_index');
        Schema::dropIfExists('translations');
        Schema::dropIfExists('products');

        Schema::create('products', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->string('slug')->nullable();
            $t->text('details')->nullable();
            $t->timestamps();
        });
        Schema::create('translations', function (Blueprint $t) {
            $t->id();
            $t->string('translationable_type');
            $t->unsignedBigInteger('translationable_id');
            $t->string('locale', 20);
            $t->string('key');
            $t->text('value')->nullable();
        });
        Schema::create('product_search_index', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('product_id');
            $t->string('locale', 20)->default('default');
            $t->string('name_normalized', 512)->default('');
            $t->text('text_normalized')->nullable();
            $t->timestamps();
            $t->unique(['product_id', 'locale']);
        });
    }

    private function product(string $name, ?string $arabicName = null, ?string $details = null): Product
    {
        $product = Product::create(['name' => $name, 'slug' => str($name)->slug(), 'details' => $details]);

        if ($arabicName !== null) {
            Translation::create([
                'translationable_type' => Product::class,
                'translationable_id'   => $product->id,
                'locale'               => 'sy',
                'key'                  => 'name',
                'value'                => $arabicName,
            ]);
        }

        $this->indexer->index($product);

        return $product;
    }

    // ---- the defect this feature exists for ----

    /**
     * Measured against the live catalogue before any of this was written: "حمايه" returned 2 rows
     * and "حماية" returned 0, for the same product. The customer's spelling is the standard one.
     */
    public function test_the_standard_spelling_finds_a_product_stored_with_the_other_spelling(): void
    {
        $product = $this->product('JUVELAB Sunscreen SPF 50', 'جوفيلاب حمايه من الشمس');

        $this->assertSame([$product->id], $this->search->productIds('حماية'));
        $this->assertSame([$product->id], $this->search->productIds('حمايه'));
    }

    public function test_alef_variants_find_each_other(): void
    {
        $product = $this->product('Cream', 'كريم احمر');

        $this->assertSame([$product->id], $this->search->productIds('أحمر'));
    }

    public function test_arabic_indic_digits_match_latin_digits(): void
    {
        $product = $this->product('MEDEE RETINOL 30 ml', 'ميدي ريتينول 30 مل');

        $this->assertSame([$product->id], $this->search->productIds('٣٠ مل'));
    }

    // ---- ranking ----

    public function test_an_exact_name_match_outranks_a_partial_one(): void
    {
        $partial = $this->product('Serum Extra C with serum base');
        $exact   = $this->product('Serum');

        $ids = $this->search->productIds('serum');

        $this->assertSame($exact->id, $ids[0], 'the product actually called "Serum" must come first');
        $this->assertContains($partial->id, $ids);
    }

    public function test_a_prefix_match_outranks_a_mid_string_match(): void
    {
        $middle = $this->product('Buy MEDEE today');
        $prefix = $this->product('MEDEE Hydra 50 ml');

        $ids = $this->search->productIds('medee');

        $this->assertSame($prefix->id, $ids[0]);
        $this->assertContains($middle->id, $ids);
    }

    public function test_a_name_match_outranks_a_description_only_match(): void
    {
        $body = $this->product('Something else', null, 'contains retinol in the description');
        $name = $this->product('Retinol Serum');

        $ids = $this->search->productIds('retinol');

        $this->assertSame($name->id, $ids[0]);
        $this->assertContains($body->id, $ids);
    }

    /** Every term must appear, or "vitamin c" returns everything containing "c". */
    public function test_all_terms_must_match(): void
    {
        $both = $this->product('Vitamin C Serum');
        $one  = $this->product('Vitamin E Cream');

        $ids = $this->search->productIds('vitamin c');

        $this->assertContains($both->id, $ids);
        $this->assertNotContains($one->id, $ids);
    }

    public function test_results_are_deduplicated_across_locales(): void
    {
        $product = $this->product('Serum', 'سيروم');

        // matches the default row AND the Arabic row; the product must still appear once
        $this->assertSame([$product->id], $this->search->productIds('serum'));
    }

    // ---- input safety ----

    /**
     * An unescaped % means "match anything", so a customer typing "50%" would silently receive the
     * entire catalogue instead of the products named that.
     */
    public function test_like_wildcards_in_the_query_are_escaped(): void
    {
        $this->product('SPF 50 Sunscreen');
        $this->product('Totally unrelated cream');

        $this->assertSame([], $this->search->productIds('%'));
        $this->assertSame([], $this->search->productIds('_'));
    }

    public function test_empty_queries_return_nothing_rather_than_everything(): void
    {
        $this->product('Anything');

        foreach ([null, '', '   ', '!!!'] as $query) {
            $this->assertSame([], $this->search->productIds($query), var_export($query, true));
        }
    }

    public function test_the_result_count_is_bounded(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->product("Serum number {$i}");
        }

        $this->assertCount(2, $this->search->productIds('serum', null, 2));
    }

    // ---- the indexer ----

    public function test_indexing_is_idempotent(): void
    {
        $product = $this->product('Serum', 'سيروم');

        $this->indexer->index($product);
        $this->indexer->index($product);

        $this->assertSame(2, ProductSearchIndex::where('product_id', $product->id)->count());
    }

    public function test_reindexing_reflects_a_renamed_product(): void
    {
        $product = $this->product('Old Name');

        $product->update(['name' => 'New Name']);
        $this->indexer->index($product);

        $this->assertSame([], $this->search->productIds('old name'));
        $this->assertSame([$product->id], $this->search->productIds('new name'));
    }

    /**
     * Regression: the indexer read getRawOriginal(), which during a `saved` event still holds the
     * PRE-save snapshot — Laravel syncs originals after firing the event. The index was therefore
     * permanently one edit behind: a rename was unfindable until the product was saved again.
     * Caught at runtime by renaming a real product, not by reading the code.
     */
    public function test_indexing_uses_the_value_being_saved_not_the_previous_one(): void
    {
        $product = $this->product('Old Name');

        // mutate in memory, exactly as an observer would see it mid-save
        $product->name = 'Brand New Name';
        $this->indexer->index($product);

        $this->assertSame([$product->id], $this->search->productIds('brand new name'));
        $this->assertSame([], $this->search->productIds('old name'));
    }

    /**
     * Regression: Product::getNameAttribute() returns a translation depending on
     * request()->segment(1), so indexing through the accessor stored Arabic in the default row and
     * a product called "Serum" could not be found by "serum".
     */
    public function test_indexing_uses_the_stored_column_not_the_translation_accessor(): void
    {
        $product = $this->product('Serum', 'سيروم');

        $default = ProductSearchIndex::where('product_id', $product->id)
            ->where('locale', ProductSearchIndexer::DEFAULT_LOCALE)->first();

        $this->assertSame('serum', $default->name_normalized);
        $this->assertSame([$product->id], $this->search->productIds('serum'));
    }

    public function test_removing_a_product_drops_its_rows(): void
    {
        $product = $this->product('Serum', 'سيروم');

        $this->indexer->remove($product->id);

        $this->assertSame(0, ProductSearchIndex::where('product_id', $product->id)->count());
        $this->assertSame([], $this->search->productIds('serum'));
    }

    /** Indexing must never be able to break the save that triggered it. */
    public function test_a_missing_index_table_degrades_instead_of_fataling(): void
    {
        $product = Product::create(['name' => 'Serum']);
        Schema::dropIfExists('product_search_index');

        $this->assertSame(0, $this->indexer->index($product));
        $this->assertSame([], $this->search->productIds('serum'));
        $this->assertFalse($this->search->available());
    }

    // ---- helping the customer when nothing matches ----

    public function test_unmatched_terms_identify_which_word_failed(): void
    {
        $this->product('Vitamin C Serum');

        $this->assertSame(['zzzz'], $this->search->unmatchedTerms('vitamin zzzz'));
        $this->assertSame([], $this->search->unmatchedTerms('vitamin serum'));
    }

    // ---- integration into the existing search paths ----

    /**
     * The storefront and the API both merge these IDs into what their legacy searches found, so
     * this helper must degrade to an empty array rather than throw — a search that raises takes
     * the product listing page down with it.
     */
    public function test_the_product_manager_helper_never_throws(): void
    {
        $product = $this->product('Serum', 'سيروم');

        $this->assertSame([$product->id], \App\Utils\ProductManager::normalizedSearchProductIds('سيروم'));
        $this->assertSame([], \App\Utils\ProductManager::normalizedSearchProductIds(null));
        $this->assertSame([], \App\Utils\ProductManager::normalizedSearchProductIds('   '));

        Schema::dropIfExists('product_search_index');
        $this->assertSame([], \App\Utils\ProductManager::normalizedSearchProductIds('سيروم'));
    }

    /**
     * The API response keys are a published contract that shipped Flutter builds parse. The
     * Arabic-aware fallback reproduces them exactly; a rename or omission here would break clients
     * that cannot be updated retroactively.
     */
    public function test_the_api_result_shape_matches_the_published_contract(): void
    {
        $result = \App\Utils\ProductManager::normalizedSearchProducts(request(), 'nothing matches this', 10, 1);

        $this->assertSame(['total_size', 'limit', 'offset', 'products'], array_keys($result));
        $this->assertSame(0, $result['total_size']);
        $this->assertSame(10, $result['limit']);
        $this->assertSame(1, $result['offset']);
        $this->assertSame([], $result['products']);
    }

    public function test_the_api_helper_reports_the_requested_paging_values(): void
    {
        $result = \App\Utils\ProductManager::normalizedSearchProducts(request(), 'nothing', 25, 3);

        $this->assertSame(25, $result['limit']);
        $this->assertSame(3, $result['offset']);
    }
}
