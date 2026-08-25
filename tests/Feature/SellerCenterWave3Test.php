<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Seller;
use App\Models\SellerStaff;
use App\Models\SellerAutomationAction;
use App\Models\SellerAutomationRule;
use App\Models\SellerAutomationRun;
use App\Services\Marketplace\SellerPrincipal;
use App\Services\SellerAutomation\Actions\HideListingAction;
use App\Services\SellerAutomation\Actions\SetDiscountAction;
use App\Services\SellerAutomation\AutomationRegistry;
use App\Services\SellerAutomation\RuleScope;
use App\Services\SellerAutomation\SettingField;
use App\Services\SellerAutomation\Triggers\LowStockTrigger;
use App\Services\SellerCenter\Automation\HistoryList;
use App\Services\SellerCenter\Automation\Opportunities;
use App\Services\SellerCenter\Automation\RulePresenter;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Wave 3's definition of done (handoff 13).
 *
 * A rule can be built from the server catalogue only, previewed without applying, activated, and
 * every run is explainable from history — including the capped and marketplace-stopped states.
 *
 * The three these tests guard hardest are the three a seller would be misled by: a capped run must
 * never read as an applied one, a rule pointed at one brand must not touch another, and a rate
 * that has never been measured must render as nothing rather than as zero.
 */
class SellerCenterWave3Test extends TestCase
{
    private const SELLER = 1;
    private const RIVAL = 2;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['sellers', 'products', 'order_details', 'orders', 'business_settings', 'seller_automation_rules', 'seller_automation_runs', 'seller_automation_actions', 'analytics_events'] as $table) {
            Schema::dropIfExists($table);
        }

        // `translate()` reads the active language out of the settings table, and every sentence
        // these services produce goes through it.
        Schema::create('business_settings', function (Blueprint $table) {
            $table->id();
            $table->string('type')->nullable();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        Schema::create('sellers', function (Blueprint $table) {
            $table->id();
            $table->string('f_name')->nullable();
            $table->string('l_name')->nullable();
            $table->string('status', 20)->default('approved');
            $table->timestamps();
        });
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('added_by', 20)->default('seller');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('product_type', 20)->default('physical');
            $table->string('name')->nullable();
            $table->text('variation')->nullable();
            $table->unsignedBigInteger('brand_id')->nullable();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->unsignedBigInteger('sub_category_id')->nullable();
            $table->unsignedBigInteger('sub_sub_category_id')->nullable();
            $table->text('category_ids')->nullable();
            $table->integer('status')->default(1);
            $table->decimal('unit_price', 24, 3)->default(0);
            $table->decimal('discount', 24, 3)->default(0);
            $table->string('discount_type', 20)->default('flat');
            $table->integer('current_stock')->default(0);
            $table->timestamps();
        });
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->timestamps();
        });
        Schema::create('order_details', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->string('delivery_status', 30)->nullable();
            $table->integer('qty')->default(1);
            $table->decimal('price', 24, 3)->default(0);
            $table->decimal('discount', 24, 3)->default(0);
            $table->timestamps();
        });
        Schema::create('seller_automation_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id');
            $table->string('name');
            $table->string('trigger', 60);
            $table->string('action', 60);
            $table->text('trigger_settings')->nullable();
            $table->text('action_settings')->nullable();
            $table->text('scope')->nullable();
            $table->string('status', 20)->default('active');
            $table->integer('max_actions_per_run')->default(50);
            $table->integer('cooldown_minutes')->default(15);
            $table->integer('run_count')->default(0);
            $table->integer('applied_count')->default(0);
            $table->integer('consecutive_failures')->default(0);
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('last_fired_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->string('suspension_reason')->nullable();
            $table->string('suspended_by', 20)->nullable();
            $table->timestamps();
        });
        Schema::create('seller_automation_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('rule_id');
            $table->unsignedBigInteger('seller_id');
            $table->string('outcome', 20);
            $table->integer('matched_count')->default(0);
            $table->integer('applied_count')->default(0);
            $table->integer('skipped_count')->default(0);
            $table->integer('failed_count')->default(0);
            $table->boolean('dry_run')->default(false);
            $table->string('message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
        Schema::create('seller_automation_actions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('run_id')->nullable();
            $table->unsignedBigInteger('rule_id')->nullable();
            $table->unsignedBigInteger('seller_id');
            $table->string('subject_type', 20)->default('product');
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('subject_label')->nullable();
            $table->string('action', 60)->nullable();
            $table->string('status', 20)->default('applied');
            $table->string('reason')->nullable();
            $table->text('before')->nullable();
            $table->text('after')->nullable();
            $table->timestamp('reverted_at')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->timestamps();
        });

        Seller::insert([
            ['id' => self::SELLER, 'f_name' => 'Owner', 'l_name' => 'One', 'status' => 'approved'],
            ['id' => self::RIVAL, 'f_name' => 'Rival', 'l_name' => 'Two', 'status' => 'approved'],
        ]);
    }

    // ───────────────────────────────── the form is built from the server

    public function test_a_settings_form_is_derived_from_the_rule_that_will_validate_it(): void
    {
        $fields = collect(SettingField::describe((new LowStockTrigger())->rules()))->keyBy('key');

        $threshold = $fields['threshold'];

        $this->assertSame('integer', $threshold['type']);
        $this->assertTrue($threshold['required']);
        $this->assertSame(1.0, $threshold['min']);
        $this->assertSame(1000.0, $threshold['max']);
    }

    public function test_an_enum_setting_offers_exactly_the_values_the_validator_accepts(): void
    {
        $fields = collect(SettingField::describe((new SetDiscountAction())->rules()))->keyBy('key');

        $this->assertSame('choice', $fields['discount_type']['type']);
        $this->assertSame(['percent', 'flat'], $fields['discount_type']['options']);
    }

    public function test_greater_than_zero_becomes_a_bound_a_number_input_can_express(): void
    {
        $fields = collect(SettingField::describe((new SetDiscountAction())->rules()))->keyBy('key');

        // `gt:0` cannot be typed into a number input; the smallest value the field's own precision
        // holds above it can.
        $this->assertSame(0.01, $fields['discount_value']['min']);
    }

    public function test_an_action_the_principal_may_not_perform_cannot_be_automated(): void
    {
        $registry = app(AutomationRegistry::class);
        // Resolved rather than constructed: the action takes its collaborators from the container,
        // and a test that hand-builds one is testing a different object than the engine runs.
        $action = $registry->action(HideListingAction::KEY);

        $mayNot = $this->principal(['products.view']);
        $may = $this->principal(['products.view', 'products.manage']);

        $this->assertSame('restricted', $registry->classify($action, $mayNot)['class']);
        $this->assertSame('safe', $registry->classify($action, $may)['class']);
    }

    // ─────────────────────────────────────────── a rule pointed at part of the shop

    public function test_a_rule_scoped_to_one_brand_leaves_the_rest_of_the_catalogue_alone(): void
    {
        $inScope = $this->product(['brand_id' => 7, 'current_stock' => 2]);
        $outOfScope = $this->product(['brand_id' => 9, 'current_stock' => 2]);

        $matched = (new LowStockTrigger())->match(self::SELLER, ['threshold' => 5], 10, ['brand_ids' => [7]]);

        $this->assertSame([$inScope->id], $matched->pluck('id')->all());
        $this->assertNotContains($outOfScope->id, $matched->pluck('id')->all());
    }

    public function test_a_rule_with_no_scope_still_means_the_whole_shop(): void
    {
        $this->product(['brand_id' => 7, 'current_stock' => 2]);
        $this->product(['brand_id' => 9, 'current_stock' => 2]);

        $this->assertCount(2, (new LowStockTrigger())->match(self::SELLER, ['threshold' => 5], 10));
        $this->assertCount(2, (new LowStockTrigger())->match(self::SELLER, ['threshold' => 5], 10, []));
    }

    public function test_a_scope_naming_nothing_is_stored_as_nothing(): void
    {
        $this->assertSame([], RuleScope::clean(['brand_ids' => [], 'category_ids' => null]));
        $this->assertFalse(RuleScope::isNarrowed(['brand_ids' => [0]]));
        $this->assertTrue(RuleScope::isNarrowed(['brand_ids' => [3]]));
    }

    public function test_a_category_scope_does_not_match_an_id_that_merely_contains_it(): void
    {
        $five = $this->product(['current_stock' => 1, 'category_ids' => '[{"id":"5","position":1}]']);
        $fiftyOne = $this->product(['current_stock' => 1, 'category_ids' => '[{"id":"51","position":1}]']);

        $matched = (new LowStockTrigger())->match(self::SELLER, ['threshold' => 5], 10, ['category_ids' => [5]]);

        $this->assertSame([$five->id], $matched->pluck('id')->all());
        $this->assertNotContains($fiftyOne->id, $matched->pluck('id')->all());
    }

    // ───────────────────────────────────────────────── what the rule says

    public function test_a_rule_reads_as_one_sentence_carrying_its_own_numbers(): void
    {
        $rule = $this->rule(['trigger_settings' => ['threshold' => 5]]);

        $sentence = app(RulePresenter::class)->sentence($rule);

        $this->assertStringContainsString('5', $sentence);
        $this->assertStringNotContainsString(':threshold', $sentence);
        $this->assertStringNotContainsString(':when', $sentence);
    }

    public function test_a_rule_that_has_never_run_has_no_success_rate(): void
    {
        $presenter = app(RulePresenter::class);

        $this->assertNull($presenter->successRate($this->rule(['run_count' => 0, 'applied_count' => 0])));
        $this->assertSame(0.0, $presenter->successRate($this->rule(['run_count' => 4, 'applied_count' => 0])));
    }

    public function test_a_rule_that_ran_and_changed_nothing_is_reported_as_information_not_a_fault(): void
    {
        $presented = app(RulePresenter::class)->one($this->rule(['run_count' => 3, 'applied_count' => 0]));

        $this->assertTrue($presented['ran_without_acting']);
        $this->assertSame('active', $presented['status']);
    }

    public function test_a_rule_the_marketplace_stopped_offers_the_seller_no_way_to_restart_it(): void
    {
        $presenter = app(RulePresenter::class);

        $byPlatform = $presenter->one($this->rule([
            'status' => SellerAutomationRule::STATUS_SUSPENDED,
            'suspended_by' => SellerAutomationRule::SUSPENDED_BY_PLATFORM,
        ]));
        $byMarketplace = $presenter->one($this->rule([
            'status' => SellerAutomationRule::STATUS_SUSPENDED,
            'suspended_by' => SellerAutomationRule::SUSPENDED_BY_MARKETPLACE,
        ]));

        $this->assertTrue($byPlatform['may_resume']);
        $this->assertFalse($byMarketplace['may_resume']);
        $this->assertTrue($byMarketplace['stopped_by_marketplace']);
    }

    public function test_a_scope_is_named_rather_than_shown_as_ids(): void
    {
        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamps();
        });
        DB::table('brands')->insert(['id' => 7, 'name' => 'MEDEE', 'created_at' => now(), 'updated_at' => now()]);

        $presented = app(RulePresenter::class)->collection([$this->rule(['scope' => ['brand_ids' => [7]]])]);

        $this->assertStringContainsString('MEDEE', $presented[0]['scope_label']);
    }

    // ──────────────────────────────────────────── a run explains itself

    public function test_a_capped_run_never_reads_as_an_applied_one(): void
    {
        $run = $this->runRow([
            'outcome' => SellerAutomationRun::OUTCOME_CAPPED,
            'matched_count' => 900,
            'applied_count' => 0,
        ]);

        $sentence = app(HistoryList::class)->outcomeSentence($run);

        $this->assertStringContainsString('900', $sentence);
        $this->assertSame('high', app(HistoryList::class)->outcomeTone($run));
    }

    public function test_a_run_that_matched_nothing_is_not_a_failure(): void
    {
        $run = $this->runRow(['outcome' => SellerAutomationRun::OUTCOME_NO_MATCH]);

        $this->assertSame('neutral', app(HistoryList::class)->outcomeTone($run));
    }

    public function test_a_run_that_has_not_finished_reports_no_duration(): void
    {
        $list = app(HistoryList::class);

        $this->assertNull($list->duration($this->runRow(['started_at' => now(), 'finished_at' => null])));
        $this->assertNotNull($list->duration($this->runRow(['started_at' => now()->subMinutes(2), 'finished_at' => now()])));
    }

    public function test_a_change_somebody_else_has_since_overwritten_is_not_offered_as_undoable(): void
    {
        $applied = $this->record(['before' => ['status' => 1], 'after' => ['status' => 0]]);
        $superseded = $this->record(['before' => ['status' => 1], 'superseded_at' => now()]);
        $skipped = $this->record(['status' => SellerAutomationAction::STATUS_SKIPPED, 'before' => null]);

        $this->assertTrue($applied->isRevertible());
        $this->assertFalse($superseded->isRevertible());
        $this->assertFalse($skipped->isRevertible());
    }

    public function test_the_history_of_one_shop_never_shows_anothers(): void
    {
        $this->runRow(['seller_id' => self::SELLER]);
        $this->runRow(['seller_id' => self::RIVAL]);

        $runs = app(HistoryList::class)->paginate(self::SELLER, new \Illuminate\Http\Request());

        $this->assertSame(1, $runs->total());
    }

    // ─────────────────────────────────────────────────── opportunities

    public function test_an_opportunity_with_no_data_behind_it_is_not_shown_at_all(): void
    {
        $this->product(['current_stock' => 100]);

        // Nothing has sold and nothing has been viewed, so there is nothing to say. A zero would be
        // a measurement; this is the absence of one.
        $found = collect(app(Opportunities::class)->for(self::SELLER))->pluck('key')->all();

        $this->assertNotContains('fast_sellers_at_stock_risk', $found);
        $this->assertNotContains('high_traffic_low_conversion', $found);
    }

    public function test_a_fast_seller_running_out_is_reported_with_the_evidence_behind_it(): void
    {
        $product = $this->product(['current_stock' => 3]);
        $this->soldLines($product->id, qty: 40);

        $found = collect(app(Opportunities::class)->for(self::SELLER))->keyBy('key');

        $this->assertTrue($found->has('fast_sellers_at_stock_risk'));
        $this->assertSame(1, $found['fast_sellers_at_stock_risk']['count']);
        $this->assertStringContainsString((string) Opportunities::WINDOW_DAYS, $found['fast_sellers_at_stock_risk']['evidence']);
    }

    public function test_a_product_with_plenty_of_cover_is_not_called_a_risk(): void
    {
        $product = $this->product(['current_stock' => 5000]);
        $this->soldLines($product->id, qty: 40);

        $found = collect(app(Opportunities::class)->for(self::SELLER))->pluck('key')->all();

        $this->assertNotContains('fast_sellers_at_stock_risk', $found);
    }

    public function test_opportunities_never_reach_across_shops(): void
    {
        $mine = $this->product(['current_stock' => 3]);
        $theirs = $this->product(['user_id' => self::RIVAL, 'current_stock' => 3]);
        $this->soldLines($mine->id, qty: 40);
        $this->soldLines($theirs->id, qty: 40, sellerId: self::RIVAL);

        $found = collect(app(Opportunities::class)->for(self::SELLER))->keyBy('key');

        $this->assertSame(1, $found['fast_sellers_at_stock_risk']['count']);
    }

    // ─────────────────────────────────────────────────────── fixtures

    private function principal(array $permissions): SellerPrincipal
    {
        $staff = new SellerStaff();
        $staff->forceFill(['id' => 5, 'seller_id' => self::SELLER, 'name' => 'Staff']);

        return SellerPrincipal::staff(Seller::find(self::SELLER), $staff, $permissions);
    }

    private function product(array $attributes = []): Product
    {
        $product = new Product();
        $product->forceFill(array_merge([
            'added_by' => 'seller',
            'user_id' => self::SELLER,
            'product_type' => 'physical',
            'name' => 'A product',
            'status' => 1,
            'unit_price' => 100,
            'current_stock' => 10,
        ], $attributes))->save();

        return $product;
    }

    private function rule(array $attributes = []): SellerAutomationRule
    {
        $rule = new SellerAutomationRule();
        $rule->forceFill(array_merge([
            'seller_id' => self::SELLER,
            'name' => 'A rule',
            'trigger' => LowStockTrigger::KEY,
            'action' => HideListingAction::KEY,
            'trigger_settings' => ['threshold' => 5],
            'action_settings' => [],
            'status' => SellerAutomationRule::STATUS_ACTIVE,
            'max_actions_per_run' => 5,
            'cooldown_minutes' => 15,
        ], $attributes))->save();

        return $rule;
    }

    private function runRow(array $attributes = []): SellerAutomationRun
    {
        $run = new SellerAutomationRun();
        $run->forceFill(array_merge([
            'rule_id' => 1,
            'seller_id' => self::SELLER,
            'outcome' => SellerAutomationRun::OUTCOME_APPLIED,
            'matched_count' => 1,
            'applied_count' => 1,
            'started_at' => now(),
            'finished_at' => now(),
        ], $attributes))->save();

        return $run;
    }

    private function record(array $attributes = []): SellerAutomationAction
    {
        $record = new SellerAutomationAction();
        $record->forceFill(array_merge([
            'run_id' => 1,
            'rule_id' => 1,
            'seller_id' => self::SELLER,
            'subject_type' => 'product',
            'subject_id' => 1,
            'action' => HideListingAction::KEY,
            'status' => SellerAutomationAction::STATUS_APPLIED,
        ], $attributes))->save();

        return $record;
    }

    private function soldLines(int $productId, int $qty, int $sellerId = self::SELLER): void
    {
        DB::table('orders')->insert(['id' => $productId + 1000, 'seller_id' => $sellerId, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('order_details')->insert([
            'order_id' => $productId + 1000,
            'product_id' => $productId,
            'seller_id' => $sellerId,
            'delivery_status' => 'delivered',
            'qty' => $qty,
            'price' => 100,
            'created_at' => now()->subDays(3),
            'updated_at' => now(),
        ]);
    }
}
