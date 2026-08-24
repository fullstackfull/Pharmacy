<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Services\Theme\ActionResolver;
use App\Services\Theme\LinkComposer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * A destination, chosen rather than typed — and read back as the same destination.
 *
 * Every link in the builder was a text box holding a URL a merchant had pasted. It is the most
 * fragile input in the product and it decides where a banner goes: a link copied from an admin page
 * instead of a shop page, a query parameter in the wrong shape, and the banner works on the web and
 * opens the entire catalogue on a phone. That exact failure is what started this work.
 *
 * The picker only helps if the pair holds: whatever the composer writes, the resolver must read
 * back as the same thing. These are that contract. A composer that could write a URL the resolver
 * does not recognise would put the builder back where it started, with a nicer control.
 */
class LinkComposerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        session(['local' => 'en']);

        foreach (['translations', 'categories', 'brands', 'products', 'reviews'] as $table) {
            Schema::dropIfExists($table);
        }
        // Category and Brand eager-load translations through a global scope.
        Schema::create('translations', function (Blueprint $table) {
            $table->id(); $table->string('translationable_type'); $table->unsignedBigInteger('translationable_id');
            $table->string('locale', 10); $table->string('key'); $table->text('value')->nullable();
        });
        Schema::create('categories', function (Blueprint $table) {
            $table->id(); $table->string('name'); $table->string('slug')->nullable();
            $table->unsignedBigInteger('parent_id')->default(0); $table->integer('position')->default(0);
            $table->integer('priority')->default(0); $table->timestamps();
        });
        Schema::create('brands', function (Blueprint $table) {
            $table->id(); $table->string('name'); $table->string('slug')->nullable();
            $table->boolean('status')->default(1); $table->timestamps();
        });
        Schema::create('products', function (Blueprint $table) {
            $table->id(); $table->string('name'); $table->string('slug')->nullable();
            $table->timestamps();
        });
        // A product's global scope eager-loads its reviews; without the table, reading one throws.
        Schema::create('reviews', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('delivery_man_id')->nullable();
            $table->boolean('status')->default(1); $table->timestamps();
        });
    }

    public function test_every_named_list_page_survives_the_round_trip(): void
    {
        // A collection the composer can write and the resolver cannot name would reach the phone as
        // a plain external URL — a browser opening on top of the app instead of a screen.
        $composer = app(LinkComposer::class);
        $resolver = app(ActionResolver::class);

        foreach (array_keys(LinkComposer::COLLECTIONS) as $collection) {
            $action = $resolver->resolve($composer->compose('collection', $collection));

            $this->assertSame(ActionResolver::COLLECTION, $action['type'], $collection);
            $this->assertSame($collection, $action['collection'], $collection);
        }
    }

    public function test_a_chosen_category_reaches_the_app_as_that_category(): void
    {
        // The bug this whole control exists for: the admin's own banner form stores a category as
        // /products?category_id=N, and a hand-typed /category/slug or a bare /products does not.
        $category = Category::create(['name' => 'Vitamins', 'slug' => 'vitamins']);

        $url = app(LinkComposer::class)->compose('category', $category->id);
        $action = app(ActionResolver::class)->resolve($url);

        $this->assertSame(ActionResolver::CATEGORY, $action['type']);
        $this->assertSame($category->id, $action['id'], 'the app opens a category list by id');
    }

    public function test_a_chosen_brand_reaches_the_app_as_that_brand(): void
    {
        $brand = Brand::create(['name' => 'Bayer', 'slug' => 'bayer', 'status' => 1]);

        $action = app(ActionResolver::class)->resolve(app(LinkComposer::class)->compose('brand', $brand->id));

        $this->assertSame(ActionResolver::BRAND, $action['type']);
        $this->assertSame($brand->id, $action['id']);
    }

    public function test_a_product_is_linked_by_the_slug_its_page_is_spelled_with(): void
    {
        // The picker hands back an id because that identifies the row; the storefront route wants a
        // slug. Composing has to bridge that or every product link 404s.
        $product = Product::create(['name' => 'Aspirin 100mg', 'slug' => 'aspirin-100mg']);

        $url = app(LinkComposer::class)->compose('product', $product->id);
        $action = app(ActionResolver::class)->resolve($url);

        $this->assertStringEndsWith('/product/aspirin-100mg', $url);
        $this->assertSame(ActionResolver::PRODUCT, $action['type']);
        $this->assertSame('aspirin-100mg', $action['slug']);
    }

    public function test_a_record_with_no_page_to_link_to_composes_nothing(): void
    {
        // A URL that 404s is worse than an empty field: the merchant would have to open the shop to
        // discover the link they just made does not work.
        $product = Product::create(['name' => 'Unlisted', 'slug' => null]);

        $this->assertNull(app(LinkComposer::class)->compose('product', $product->id));
        $this->assertNull(app(LinkComposer::class)->compose('product', 9999));
        $this->assertNull(app(LinkComposer::class)->compose('category', 0));
        $this->assertNull(app(LinkComposer::class)->compose('none'));
    }

    public function test_a_search_link_carries_the_term(): void
    {
        $url = app(LinkComposer::class)->compose('search', ' vitamin c ');
        $action = app(ActionResolver::class)->resolve($url);

        $this->assertSame(ActionResolver::SEARCH, $action['type']);
        $this->assertSame('vitamin c', $action['query'], 'trimmed, and still the whole phrase');
    }

    public function test_an_external_address_is_kept_exactly_as_typed(): void
    {
        // The one case nothing else covers. Rewriting it would break the merchant's own link.
        $url = app(LinkComposer::class)->compose('url', 'https://ministry.example/notice');

        $this->assertSame('https://ministry.example/notice', $url);
        $this->assertSame(ActionResolver::URL, app(ActionResolver::class)->resolve($url)['type']);
    }

    public function test_a_stored_link_comes_back_as_the_choice_that_made_it(): void
    {
        // Without this the control would open showing "nothing" for a link that works, and the next
        // save would wipe it — the worst possible outcome of adding a nicer input.
        $category = Category::create(['name' => 'Skincare', 'slug' => 'skincare']);
        $composer = app(LinkComposer::class);

        $described = $composer->describe($composer->compose('category', $category->id));

        $this->assertSame('category', $described['kind']);
        $this->assertSame($category->id, $described['reference']);
        $this->assertSame('Skincare', $described['label'], 'and it says which one, not just an id');
    }

    public function test_every_kind_the_control_offers_describes_back_to_itself(): void
    {
        $composer = app(LinkComposer::class);
        $category = Category::create(['name' => 'Vitamins', 'slug' => 'vitamins']);
        $brand = Brand::create(['name' => 'Bayer', 'slug' => 'bayer', 'status' => 1]);
        $product = Product::create(['name' => 'Aspirin', 'slug' => 'aspirin']);

        $cases = [
            ['category', $category->id],
            ['brand', $brand->id],
            ['product', $product->id],
            ['collection', 'best_selling'],
            ['search', 'vitamin'],
            ['cart', null],
            ['wishlist', null],
            ['url', 'https://elsewhere.example/page'],
        ];

        foreach ($cases as [$kind, $reference]) {
            $described = $composer->describe($composer->compose($kind, $reference));

            $this->assertSame($kind, $described['kind'], $kind);
        }
    }

    public function test_an_empty_or_unreadable_link_is_simply_no_link(): void
    {
        $composer = app(LinkComposer::class);

        foreach ([null, '', '   ', '#'] as $nothing) {
            $this->assertSame('none', $composer->describe($nothing)['kind'], var_export($nothing, true));
        }
    }
}
