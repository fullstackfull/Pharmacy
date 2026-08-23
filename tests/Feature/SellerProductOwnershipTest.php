<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Seller;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * A seller may only destroy their own product's files.
 *
 * Two write endpoints on the seller API looked a product up by id alone — no owner in the WHERE —
 * and then wrote to it. Any authenticated seller could delete the images off a competitor's listing,
 * or clear its preview file, by passing that product's id. It answered 200 and did the work. The
 * same lookups returned null for a product that did not exist and then fatalled on the next line, so
 * a mistyped id produced a 500 HTML page instead of a not-found.
 *
 * Both were reproduced against a running server before this test was written: seller 1 deleted an
 * image from seller 2's product and the row came back one image lighter.
 */
class SellerProductOwnershipTest extends TestCase
{
    private const OWNER_TOKEN = 'owner-token-long-enough-to-pass-the-length-gate';
    private const RIVAL_TOKEN = 'rival-token-long-enough-to-pass-the-length-gate';

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['products', 'sellers', 'reviews', 'translations', 'order_details', 'business_settings'] as $table) {
            Schema::dropIfExists($table);
        }

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
        Schema::create('sellers', function (Blueprint $table) {
            $table->id();
            $table->string('f_name')->nullable();
            $table->string('email')->nullable();
            $table->string('status', 20)->default('approved');
            $table->string('auth_token')->nullable();
            $table->integer('stock_limit')->default(0);
            $table->timestamps();
        });
        Schema::create('order_details', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->decimal('init_order_amount', 24, 3)->default(0);
            $table->timestamps();
        });
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('added_by', 20)->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->text('images')->nullable();
            $table->text('color_image')->nullable();
            $table->text('colors')->nullable();
            $table->string('preview_file')->nullable();
            $table->timestamps();
        });

        Seller::insert([
            ['id' => 1, 'f_name' => 'Owner', 'email' => 'owner@example.com', 'status' => 'approved',
                'auth_token' => self::OWNER_TOKEN, 'stock_limit' => 0],
            ['id' => 2, 'f_name' => 'Rival', 'email' => 'rival@example.com', 'status' => 'approved',
                'auth_token' => self::RIVAL_TOKEN, 'stock_limit' => 0],
        ]);
    }

    private function productFor(int $sellerId): Product
    {
        return Product::create([
            'name' => "Product of seller {$sellerId}",
            'added_by' => 'seller',
            'user_id' => $sellerId,
            'images' => json_encode(['first.webp', 'second.webp']),
            'color_image' => json_encode([]),
            'colors' => json_encode([]),
            'preview_file' => 'preview.zip',
        ]);
    }

    /** @return array<string, string> */
    private function asSeller(string $token): array
    {
        return ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];
    }

    public function test_a_seller_cannot_delete_an_image_from_another_sellers_product(): void
    {
        $rivalProduct = $this->productFor(2);

        $response = $this->withHeaders($this->asSeller(self::OWNER_TOKEN))
            ->getJson("/api/v3/seller/products/delete-image?id={$rivalProduct->id}&name=first.webp");

        $response->assertStatus(404);
        $this->assertSame(
            ['first.webp', 'second.webp'],
            json_decode($rivalProduct->fresh()->images, true),
            'The rival product kept both images.',
        );
    }

    public function test_a_seller_cannot_clear_the_preview_file_on_another_sellers_product(): void
    {
        $rivalProduct = $this->productFor(2);

        $response = $this->withHeaders($this->asSeller(self::OWNER_TOKEN))
            ->getJson("/api/v3/seller/products/delete-preview-file?product_id={$rivalProduct->id}");

        $response->assertStatus(404);
        $this->assertSame('preview.zip', $rivalProduct->fresh()->preview_file);
    }

    public function test_a_seller_can_still_delete_an_image_from_their_own_product(): void
    {
        $ownProduct = $this->productFor(1);

        $response = $this->withHeaders($this->asSeller(self::OWNER_TOKEN))
            ->getJson("/api/v3/seller/products/delete-image?id={$ownProduct->id}&name=first.webp");

        $response->assertStatus(200);
        $this->assertSame(['second.webp'], json_decode($ownProduct->fresh()->images, true));
    }

    public function test_a_product_that_does_not_exist_answers_rather_than_fatals(): void
    {
        // `Product::find()` returned null and the next line read an array offset on it, so a
        // mistyped id produced a 500 HTML page the app could not parse.
        $this->withHeaders($this->asSeller(self::OWNER_TOKEN))
            ->getJson('/api/v3/seller/products/delete-image?id=99999999&name=first.webp')
            ->assertStatus(404);

        $this->withHeaders($this->asSeller(self::OWNER_TOKEN))
            ->getJson('/api/v3/seller/products/delete-preview-file?product_id=99999999')
            ->assertStatus(404);
    }

    public function test_an_order_that_is_not_this_sellers_is_not_found_rather_than_a_crash(): void
    {
        // `null <= 0` is true in PHP, so a missing order passed the guard on the line above and then
        // fatalled reading a property on null.
        $this->withHeaders($this->asSeller(self::OWNER_TOKEN))
            ->getJson('/api/v3/seller/orders/99999999')
            ->assertStatus(404);
    }
}
