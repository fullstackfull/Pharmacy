<?php

namespace Tests\Feature;

use App\Models\BusinessSetting;
use App\Models\CommissionRule;
use App\Services\Marketplace\CommissionEngine;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Which commission rule wins, and what it comes to (Phase 3, Stage B).
 *
 * What the platform did before this: `Helpers::seller_sales_commission()` read
 * `sellers.sales_commission_percentage`, fell back to a global setting, and multiplied. One
 * percentage, no category rate, no product override, no fixed fee, and no record of which rule
 * produced a number.
 *
 * Two properties matter more than any individual rate here, and both are asserted below:
 *
 *  1. **Determinism.** Two rules must never both "win". Priority decides, ties break by newest.
 *  2. **Compatibility.** With no rules configured the engine must return exactly what the platform
 *     returns today, so installing the migration and doing nothing changes no figure.
 */
class CommissionEngineTest extends TestCase
{
    private CommissionEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['commission_rules', 'sellers', 'business_settings'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('commission_rules', function (Blueprint $t) {
            $t->id();
            $t->string('scope_type', 20);
            $t->unsignedBigInteger('scope_id')->nullable();
            $t->string('rate_type', 30)->default('percentage');
            $t->decimal('percentage', 8, 4)->default(0);
            $t->decimal('fixed_amount', 24, 4)->default(0);
            $t->decimal('min_amount', 24, 4)->default(0);
            $t->decimal('max_amount', 24, 4)->default(0);
            $t->unsignedInteger('priority')->default(100);
            $t->boolean('is_active')->default(1);
            $t->timestamp('starts_at')->nullable();
            $t->timestamp('ends_at')->nullable();
            $t->string('label', 191)->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
        });
        Schema::create('sellers', function (Blueprint $t) {
            $t->id();
            $t->decimal('sales_commission_percentage', 8, 2)->nullable();
            $t->string('status')->default('approved');
            $t->timestamps();
        });
        Schema::create('business_settings', function (Blueprint $t) {
            $t->id(); $t->string('type')->nullable(); $t->text('value')->nullable(); $t->timestamps();
        });

        $this->engine = new CommissionEngine();
        cache()->flush();
    }

    private function rule(string $scope, ?int $scopeId, array $attributes = []): CommissionRule
    {
        return CommissionRule::create(array_merge([
            'scope_type' => $scope,
            'scope_id' => $scopeId,
            'rate_type' => CommissionEngine::RATE_PERCENTAGE,
            'percentage' => 10,
            'priority' => CommissionEngine::DEFAULT_PRIORITY[$scope],
            'is_active' => 1,
        ], $attributes));
    }

    private function line(array $overrides = []): array
    {
        return array_merge([
            'product_id' => 7, 'category_id' => 3, 'seller_id' => 5, 'seller_is' => 'seller',
        ], $overrides);
    }

    // ---- the rate types ----

    public function test_a_percentage_rule_takes_a_percentage(): void
    {
        $this->rule(CommissionEngine::SCOPE_GLOBAL, null, ['percentage' => 12.5]);

        $result = $this->engine->calculate($this->line(), 200.0);

        $this->assertSame(25.0, $result['commission_amount']);
        $this->assertSame(175.0, $result['seller_net_amount']);
    }

    public function test_a_fixed_rule_charges_per_line_regardless_of_value(): void
    {
        $this->rule(CommissionEngine::SCOPE_GLOBAL, null, [
            'rate_type' => CommissionEngine::RATE_FIXED, 'fixed_amount' => 1.5, 'percentage' => 0,
        ]);

        $this->assertSame(1.5, $this->engine->calculate($this->line(), 200.0)['commission_amount']);
        $this->assertSame(1.5, $this->engine->calculate($this->line(), 20.0)['commission_amount']);
    }

    public function test_percentage_plus_fixed_adds_both(): void
    {
        $this->rule(CommissionEngine::SCOPE_GLOBAL, null, [
            'rate_type' => CommissionEngine::RATE_PERCENTAGE_PLUS_FIXED, 'percentage' => 10, 'fixed_amount' => 0.5,
        ]);

        $this->assertSame(20.5, $this->engine->calculate($this->line(), 200.0)['commission_amount']);
    }

    // ---- priority: two rules must never both win ----

    public function test_a_product_rule_beats_vendor_category_and_global(): void
    {
        $this->rule(CommissionEngine::SCOPE_GLOBAL, null, ['percentage' => 10]);
        $this->rule(CommissionEngine::SCOPE_CATEGORY, 3, ['percentage' => 15]);
        $this->rule(CommissionEngine::SCOPE_VENDOR, 5, ['percentage' => 20]);
        $this->rule(CommissionEngine::SCOPE_PRODUCT, 7, ['percentage' => 5, 'label' => 'product override']);

        $result = $this->engine->calculate($this->line(), 100.0);

        $this->assertSame(5.0, $result['commission_amount']);
        $this->assertSame('product', $result['rule_scope_type']);
        $this->assertSame('product override', $result['rule_label']);
    }

    public function test_a_vendor_rule_beats_category_and_global(): void
    {
        $this->rule(CommissionEngine::SCOPE_GLOBAL, null, ['percentage' => 10]);
        $this->rule(CommissionEngine::SCOPE_CATEGORY, 3, ['percentage' => 15]);
        $this->rule(CommissionEngine::SCOPE_VENDOR, 5, ['percentage' => 20]);

        $this->assertSame('vendor', $this->engine->calculate($this->line(), 100.0)['rule_scope_type']);
    }

    public function test_a_category_rule_beats_global(): void
    {
        $this->rule(CommissionEngine::SCOPE_GLOBAL, null, ['percentage' => 10]);
        $this->rule(CommissionEngine::SCOPE_CATEGORY, 3, ['percentage' => 15]);

        $this->assertSame(15.0, $this->engine->calculate($this->line(), 100.0)['commission_amount']);
    }

    /** A rule for a different product must not leak onto this one. */
    public function test_a_rule_scoped_to_another_id_does_not_apply(): void
    {
        $this->rule(CommissionEngine::SCOPE_GLOBAL, null, ['percentage' => 10]);
        $this->rule(CommissionEngine::SCOPE_PRODUCT, 999, ['percentage' => 50]);

        $this->assertSame(10.0, $this->engine->calculate($this->line(), 100.0)['commission_amount']);
    }

    /**
     * An explicit priority is what lets a campaign outrank a product override — which is why the
     * number is stored rather than derived from the scope at read time.
     */
    public function test_an_explicit_priority_can_float_a_rule_above_its_scope(): void
    {
        $this->rule(CommissionEngine::SCOPE_PRODUCT, 7, ['percentage' => 5]);
        $this->rule(CommissionEngine::SCOPE_GLOBAL, null, ['percentage' => 2, 'priority' => 900, 'label' => 'launch campaign']);

        $result = $this->engine->calculate($this->line(), 100.0);

        $this->assertSame(2.0, $result['commission_amount']);
        $this->assertSame('launch campaign', $result['rule_label']);
    }

    // ---- the window ----

    public function test_a_rule_that_has_not_started_is_ignored(): void
    {
        $this->rule(CommissionEngine::SCOPE_GLOBAL, null, ['percentage' => 10]);
        $this->rule(CommissionEngine::SCOPE_PRODUCT, 7, ['percentage' => 1, 'starts_at' => now()->addDay()]);

        $this->assertSame(10.0, $this->engine->calculate($this->line(), 100.0)['commission_amount']);
    }

    public function test_an_expired_rule_is_ignored(): void
    {
        $this->rule(CommissionEngine::SCOPE_GLOBAL, null, ['percentage' => 10]);
        $this->rule(CommissionEngine::SCOPE_PRODUCT, 7, ['percentage' => 1, 'ends_at' => now()->subDay()]);

        $this->assertSame(10.0, $this->engine->calculate($this->line(), 100.0)['commission_amount']);
    }

    public function test_a_switched_off_rule_is_ignored(): void
    {
        $this->rule(CommissionEngine::SCOPE_GLOBAL, null, ['percentage' => 10]);
        $this->rule(CommissionEngine::SCOPE_PRODUCT, 7, ['percentage' => 1, 'is_active' => 0]);

        $this->assertSame(10.0, $this->engine->calculate($this->line(), 100.0)['commission_amount']);
    }

    // ---- guard rails ----

    public function test_a_max_caps_a_runaway_rate(): void
    {
        $this->rule(CommissionEngine::SCOPE_GLOBAL, null, ['percentage' => 50, 'max_amount' => 20]);

        $this->assertSame(20.0, $this->engine->calculate($this->line(), 100.0)['commission_amount']);
    }

    public function test_a_min_lifts_a_trivial_rate(): void
    {
        $this->rule(CommissionEngine::SCOPE_GLOBAL, null, ['percentage' => 1, 'min_amount' => 5]);

        $this->assertSame(5.0, $this->engine->calculate($this->line(), 100.0)['commission_amount']);
    }

    /** A misconfigured rule must never make the seller owe money for having made a sale. */
    public function test_commission_can_never_exceed_the_line(): void
    {
        $this->rule(CommissionEngine::SCOPE_GLOBAL, null, ['percentage' => 500]);

        $result = $this->engine->calculate($this->line(), 100.0);

        $this->assertSame(100.0, $result['commission_amount']);
        $this->assertSame(0.0, $result['seller_net_amount']);
    }

    // ---- the marketplace's own stock ----

    public function test_an_in_house_line_carries_no_commission(): void
    {
        $this->rule(CommissionEngine::SCOPE_GLOBAL, null, ['percentage' => 25]);

        $result = $this->engine->calculate($this->line(['seller_is' => 'admin']), 100.0);

        $this->assertSame(0.0, $result['commission_amount'], 'the marketplace does not pay itself');
        $this->assertSame(100.0, $result['seller_net_amount']);
    }

    // ---- backward compatibility: no rules configured ----

    public function test_with_no_rules_it_uses_the_sellers_own_percentage(): void
    {
        // Seller::$fillable does not include id, so a mass-assigned one would land on a different
        // row and the lookup would miss — insert directly to pin it.
        \Illuminate\Support\Facades\DB::table('sellers')->insert(['id' => 5, 'sales_commission_percentage' => 8, 'status' => 'approved']);

        $result = $this->engine->calculate($this->line(), 100.0);

        $this->assertSame(8.0, $result['commission_amount'], 'exactly what the platform does today');
        $this->assertNull($result['rule_id']);
        $this->assertSame('legacy_seller_percentage', $result['rule_label']);
    }

    public function test_with_no_rules_and_no_seller_percentage_it_uses_the_global_setting(): void
    {
        \Illuminate\Support\Facades\DB::table('sellers')->insert(['id' => 5, 'sales_commission_percentage' => null, 'status' => 'approved']);
        BusinessSetting::updateOrCreate(['type' => 'sales_commission'], ['value' => 6]);
        cache()->flush();

        $this->assertSame(6.0, $this->engine->calculate($this->line(), 100.0)['commission_amount']);
    }

    public function test_the_snapshot_carries_everything_needed_to_re_check_the_arithmetic(): void
    {
        $rule = $this->rule(CommissionEngine::SCOPE_VENDOR, 5, [
            'rate_type' => CommissionEngine::RATE_PERCENTAGE_PLUS_FIXED,
            'percentage' => 10, 'fixed_amount' => 2, 'label' => 'vendor contract A',
        ]);

        $result = $this->engine->calculate($this->line(), 150.0);

        // Everything a settlement or an auditor needs, without reconstructing prices or rules.
        $this->assertSame($rule->id, $result['rule_id']);
        $this->assertSame('vendor', $result['rule_scope_type']);
        $this->assertSame('vendor contract A', $result['rule_label']);
        $this->assertSame('percentage_plus_fixed', $result['rate_type']);
        $this->assertSame(10.0, $result['percentage']);
        $this->assertSame(2.0, $result['fixed_amount']);
        $this->assertSame(150.0, $result['commissionable_amount']);
        $this->assertSame(17.0, $result['commission_amount']);
        $this->assertSame(133.0, $result['seller_net_amount']);
    }
}
