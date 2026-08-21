<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Repositories\ProductRepository;
use Tests\Concerns\CreatesCatalogueSchema;
use Tests\TestCase;

/**
 * The barcode is bound wherever the SKU already is.
 *
 * A number that is stored but never read back is exactly what the merchant did not want: it has to
 * print on the label, answer a search, and reach the API alongside the SKU.
 */
class ProductBarcodeTest extends TestCase
{
    use CreatesCatalogueSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createCatalogueSchema();
    }

    public function test_the_label_prints_the_barcode_when_there_is_one_and_the_sku_otherwise(): void
    {
        $scanned = Product::create(['name' => 'Boxed', 'code' => 'ABC123', 'barcode' => '6291234567890']);
        $ownSkuOnly = Product::create(['name' => 'Unboxed', 'code' => 'DEF456']);

        $this->assertSame('6291234567890', $scanned->scan_code);
        $this->assertSame('DEF456', $ownSkuOnly->scan_code);
    }

    public function test_the_scan_code_travels_with_the_product_to_the_api(): void
    {
        $product = Product::create(['name' => 'Boxed', 'code' => 'ABC123', 'barcode' => '6291234567890']);

        $payload = $product->fresh()->toArray();

        $this->assertSame('6291234567890', $payload['barcode']);
        $this->assertSame('6291234567890', $payload['scan_code']);
    }

    public function test_a_scanned_number_finds_the_product_the_same_way_its_sku_does(): void
    {
        Product::create(['name' => 'Boxed', 'status' => 1, 'request_status' => 1, 'code' => 'ABC123', 'barcode' => '6291234567890']);
        Product::create(['name' => 'Other', 'status' => 1, 'request_status' => 1, 'code' => 'ZZZ999', 'barcode' => '1111111111111']);

        $repository = app(ProductRepository::class);

        $bySku = $repository->getListWhere(filters: ['code' => 'ABC123'], dataLimit: 'all');
        $byBarcode = $repository->getListWhere(filters: ['code' => '6291234567890'], dataLimit: 'all');

        $this->assertSame(['Boxed'], $bySku->pluck('name')->all());
        $this->assertSame(['Boxed'], $byBarcode->pluck('name')->all());
    }

    public function test_the_barcode_is_stored_as_typed_minus_the_noise_a_scanner_adds(): void
    {
        $service = app(\App\Services\ProductService::class);
        $method = new \ReflectionMethod($service, 'getBarcode');

        $this->assertSame('6291234567890', $method->invoke($service, new \Illuminate\Http\Request(['barcode' => ' 629 123 456 7890 '])));
        $this->assertSame('978-3-16-148', $method->invoke($service, new \Illuminate\Http\Request(['barcode' => '978-3-16-148'])));
        $this->assertNull($method->invoke($service, new \Illuminate\Http\Request(['barcode' => '   '])));
        $this->assertNull($method->invoke($service, new \Illuminate\Http\Request([])));
    }
}
