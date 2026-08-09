<?php

namespace Tests\Feature;

use App\Models\BusinessSetting;
use App\Models\CategoryGovernance;
use App\Services\Marketplace\CategoryGovernanceService;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Per-category operational governance (Phase 3, Stage A — spec item 10).
 *
 * The property that matters most, asserted throughout: an ungoverned category inherits the global
 * default, so adopting this changes nothing until a category is deliberately configured. On top of
 * that, a category can set a stricter or more generous return window, force moderation, and require
 * attributes — cosmetics and electronics no longer share one store-wide policy.
 */
class CategoryGovernanceTest extends TestCase
{
    private CategoryGovernanceService $governance;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['category_governance', 'business_settings'] as $t) {
            Schema::dropIfExists($t);
        }

        Schema::create('category_governance', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('category_id');
            $t->unsignedInteger('return_window_days')->nullable();
            $t->json('required_attributes')->nullable();
            $t->string('tax_class', 60)->nullable();
            $t->boolean('requires_moderation')->default(0);
            $t->boolean('shipping_restricted')->default(0);
            $t->string('restriction_note', 512)->nullable();
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->timestamps();
        });
        Schema::create('business_settings', function (Blueprint $t) {
            $t->id(); $t->string('type')->nullable(); $t->text('value')->nullable(); $t->timestamps();
        });

        BusinessSetting::updateOrCreate(['type' => 'refund_day_limit'], ['value' => 7]);
        cache()->flush();

        $this->governance = new CategoryGovernanceService();
    }

    // ---- the return window, inherited or overridden ----

    public function test_an_ungoverned_category_inherits_the_global_return_window(): void
    {
        $this->assertSame(7, $this->governance->returnWindowDays(3), 'no row -> the global refund_day_limit');
    }

    public function test_a_category_can_set_a_stricter_window(): void
    {
        CategoryGovernance::create(['category_id' => 3, 'return_window_days' => 2]);

        $this->assertSame(2, $this->governance->returnWindowDays(3));
    }

    public function test_a_category_can_forbid_returns_with_zero_distinct_from_inherit(): void
    {
        CategoryGovernance::create(['category_id' => 3, 'return_window_days' => 0]);

        $this->assertSame(0, $this->governance->returnWindowDays(3), '0 is "no returns", not "inherit"');
    }

    public function test_a_null_window_still_inherits_even_with_a_row_present(): void
    {
        CategoryGovernance::create(['category_id' => 3, 'return_window_days' => null, 'tax_class' => 'standard']);

        $this->assertSame(7, $this->governance->returnWindowDays(3), 'a row that sets other fields still inherits the window');
    }

    // ---- the window actually gates a refund ----

    public function test_a_refund_inside_the_window_is_allowed(): void
    {
        CategoryGovernance::create(['category_id' => 3, 'return_window_days' => 5]);

        $this->assertTrue($this->governance->isWithinReturnWindow(3, Carbon::now()->subDays(3)));
    }

    public function test_a_refund_past_the_category_window_is_refused_even_when_the_global_is_generous(): void
    {
        // Global is 7; this category tightens it to 2.
        CategoryGovernance::create(['category_id' => 3, 'return_window_days' => 2]);

        $this->assertFalse(
            $this->governance->isWithinReturnWindow(3, Carbon::now()->subDays(4)),
            'four days is fine store-wide but past this category`s two-day window'
        );
    }

    public function test_the_same_four_days_is_still_allowed_for_an_ungoverned_category(): void
    {
        $this->assertTrue(
            $this->governance->isWithinReturnWindow(999, Carbon::now()->subDays(4)),
            'ungoverned -> global 7-day window -> four days is inside it'
        );
    }

    public function test_a_refund_with_no_start_time_is_not_yet_expired(): void
    {
        $this->assertTrue($this->governance->isWithinReturnWindow(3, null), 'the clock has not started');
    }

    // ---- moderation requirement ----

    public function test_a_category_can_force_moderation(): void
    {
        CategoryGovernance::create(['category_id' => 3, 'requires_moderation' => true]);

        $this->assertTrue($this->governance->requiresModeration(3));
        $this->assertFalse($this->governance->requiresModeration(999), 'ungoverned -> not forced');
    }

    // ---- required attributes ----

    public function test_it_reports_which_required_attributes_a_product_is_missing(): void
    {
        CategoryGovernance::create(['category_id' => 3, 'required_attributes' => ['strength', 'volume', 'brand']]);

        $missing = $this->governance->missingRequiredAttributes(3, ['brand' => 'Feme', 'volume' => '']);

        $this->assertEqualsCanonicalizing(['strength', 'volume'], $missing, 'strength absent, volume blank');
    }

    public function test_a_product_that_satisfies_the_category_is_missing_nothing(): void
    {
        CategoryGovernance::create(['category_id' => 3, 'required_attributes' => ['strength']]);

        $this->assertSame([], $this->governance->missingRequiredAttributes(3, ['strength' => '5mg']));
    }

    public function test_an_ungoverned_category_requires_no_attributes(): void
    {
        $this->assertSame([], $this->governance->missingRequiredAttributes(3, []));
    }

    // ---- tax class ----

    public function test_a_category_can_carry_a_tax_class(): void
    {
        CategoryGovernance::create(['category_id' => 3, 'tax_class' => 'zero_rated']);

        $this->assertSame('zero_rated', $this->governance->taxClass(3));
        $this->assertNull($this->governance->taxClass(999));
    }
}
