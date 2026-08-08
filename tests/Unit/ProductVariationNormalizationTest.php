<?php

namespace Tests\Unit;

use App\Services\ProductService;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Regression tests for the "save fails after removing a variant/attribute/option" bug.
 *
 * The product form keeps parallel structures (choice_no[] + choice_options_<id>[]). When an
 * attribute/option is removed before saving, choice_options_<id> arrives null. Previously the
 * service called implode('|', null) -> TypeError -> HTTP 500 (a silent failure to the merchant).
 *
 * These target the pure normalization methods (no constructor dependencies are touched), so the
 * service is instantiated without its constructor.
 */
class ProductVariationNormalizationTest extends TestCase
{
    private function service(): ProductService
    {
        return (new ReflectionClass(ProductService::class))->newInstanceWithoutConstructor();
    }

    private function request(array $params): Request
    {
        return Request::create('/', 'POST', $params);
    }

    /** The regression: a choice_no whose options were removed must not throw. */
    public function test_get_options_skips_attribute_with_removed_options(): void
    {
        $options = $this->service()->getOptions($this->request([
            'choice_no' => ['1', '2'],
            'choice_options_1' => ['Red,Blue'],
            // choice_options_2 removed entirely (desynced form state)
        ]));

        // Only the intact attribute survives; the empty one is dropped instead of crashing
        // or collapsing the whole variation set.
        $this->assertSame([['Red', 'Blue']], $options);
    }

    /** Valid input must behave exactly as before (backward compatibility). */
    public function test_get_options_valid_input_is_unchanged(): void
    {
        $options = $this->service()->getOptions($this->request([
            'colors_active' => 'on',
            'colors' => ['#ff0000', '#00ff00'],
            'choice_no' => ['1'],
            'choice_options_1' => ['S,M,L'],
            'product_type' => 'physical',
        ]));

        $this->assertSame([['#ff0000', '#00ff00'], ['S', 'M', 'L']], $options);
    }

    /** getChoiceOptions must skip the removed attribute and keep the intact one, no TypeError. */
    public function test_get_choice_options_skips_removed_and_keeps_valid(): void
    {
        $result = $this->service()->getChoiceOptions($this->request([
            'choice' => ['Size', 'Material'],
            'choice_no' => ['1', '2'],
            'choice_options_1' => ['S,M'],
            // choice_options_2 removed
        ]));

        $this->assertCount(1, $result);
        $this->assertSame('choice_1', $result[0]['name']);
        $this->assertSame(['S', 'M'], $result[0]['options']);
    }

    /** All attributes removed => empty option set, no crash. */
    public function test_get_options_all_removed_returns_empty(): void
    {
        $options = $this->service()->getOptions($this->request([
            'choice_no' => ['1', '2'],
            // both option arrays removed
        ]));

        $this->assertSame([], $options);
    }

    /** Cartesian product still correct for valid factors (behavior guard). */
    public function test_get_combinations_cartesian_product(): void
    {
        $combinations = $this->service()->getCombinations([['#ff0000'], ['S', 'M']]);
        $this->assertCount(2, $combinations);
    }
}
