<?php

namespace Tests\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The slice of the catalogue schema the storefront placement services touch.
 *
 * The suite runs on an in-memory SQLite database with no migrations, so a test
 * that exercises a service has to stand up the tables that service reads. Kept
 * in one place because the category-page and brand-page headers read the same
 * catalogue: categories, brands, products (with the columns Product::active()
 * filters on), banners, and the storage/translation tables the models write to
 * on save.
 */
trait CreatesCatalogueSchema
{
    protected function createCatalogueSchema(): void
    {
        $this->createTable('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('slug')->nullable();
            $table->string('icon')->nullable();
            $table->string('icon_storage_type')->nullable();
            $table->integer('parent_id')->default(0);
            $table->integer('position')->default(0);
            $table->integer('priority')->default(0);
            $table->boolean('home_status')->default(0);
            $table->timestamps();
        });

        $this->createTable('brands', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('slug')->nullable();
            $table->string('image')->nullable();
            $table->string('image_storage_type')->nullable();
            $table->string('image_alt_text')->nullable();
            $table->integer('status')->default(1);
            $table->timestamps();
        });

        $this->createTable('banners', function (Blueprint $table) {
            $table->id();
            $table->string('photo')->nullable();
            $table->string('mobile_photo')->nullable();
            $table->string('banner_type');
            $table->string('layout', 20)->default('full');
            $table->integer('priority')->default(0);
            $table->string('theme')->default('default');
            $table->integer('published')->default(0);
            $table->string('url')->nullable();
            $table->string('resource_type')->nullable();
            $table->unsignedBigInteger('resource_id')->nullable();
            $table->string('title')->nullable();
            $table->string('sub_title')->nullable();
            $table->string('button_text')->nullable();
            $table->string('background_color')->nullable();
            $table->timestamps();
        });

        // Product::active() joins the seller and filters on the publication columns.
        $this->createTable('products', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->unsignedBigInteger('sub_category_id')->nullable();
            $table->unsignedBigInteger('sub_sub_category_id')->nullable();
            $table->unsignedBigInteger('brand_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('added_by')->default('admin');
            $table->string('product_type')->default('physical');
            $table->integer('status')->default(1);
            $table->integer('request_status')->default(1);
            // Columns the product card and the product page read: stock decides the sold-out
            // state, the price pair drives the discount percentage, and the two description
            // fields feed the short description.
            $table->integer('current_stock')->default(0);
            $table->decimal('unit_price', 24, 3)->default(0);
            $table->decimal('discount', 24, 3)->default(0);
            $table->string('discount_type')->default('flat');
            $table->integer('minimum_order_qty')->default(1);
            $table->text('meta_description')->nullable();
            // The companions a merchant picks for the "frequently bought together" panel.
            $table->text('bought_together_ids')->nullable();
            $table->text('details')->nullable();
            $table->timestamps();
        });

        // A price read checks for a running clearance sale on the product first.
        $this->createTable('stock_clearance_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('discount_type')->nullable();
            $table->decimal('discount_amount', 24, 3)->default(0);
            $table->timestamps();
        });

        // Every Product read eager-loads its reviews through a global scope, so any test that
        // fetches products (not just counts them) needs the table to exist.
        $this->createTable('reviews', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('delivery_man_id')->nullable();
            $table->integer('rating')->default(5);
            $table->text('comment')->nullable();
            $table->integer('status')->default(1);
            $table->timestamps();
        });

        // Flash deals: a themed section can feature one, and only one deal is ever ACTIVE
        // (activating one deactivates the rest), which is what makes picking a deal matter.
        $this->createTable('flash_deals', function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable();
            $table->string('slug')->nullable();
            $table->string('deal_type')->default('flash_deal');
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->boolean('status')->default(0);
            $table->timestamps();
        });

        $this->createTable('flash_deal_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('flash_deal_id');
            $table->unsignedBigInteger('product_id');
            $table->timestamps();
        });

        $this->createTable('sellers', function (Blueprint $table) {
            $table->id();
            $table->string('status')->default('approved');
            $table->timestamps();
        });

        // Saving a banner registers its file here; names resolve through translations
        // and the settings table.
        $this->createTable('storages', function (Blueprint $table) {
            $table->id();
            $table->string('data_type')->nullable();
            $table->unsignedBigInteger('data_id')->nullable();
            $table->string('key')->nullable();
            $table->string('value')->nullable();
            $table->timestamps();
        });

        $this->createTable('business_settings', function (Blueprint $table) {
            $table->id();
            $table->string('type')->nullable();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        $this->createTable('translations', function (Blueprint $table) {
            $table->id();
            $table->string('translationable_type')->nullable();
            $table->unsignedBigInteger('translationable_id')->nullable();
            $table->string('locale')->nullable();
            $table->string('key')->nullable();
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }

    private function createTable(string $name, callable $definition): void
    {
        if (!Schema::hasTable($name)) {
            Schema::create($name, $definition);
        }
    }
}
