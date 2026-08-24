<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\SellerAutomationAction;
use App\Models\SellerAutomationRule;
use App\Models\SellerAutomationRun;
use App\Services\Marketplace\SellerPrincipal;
use App\Services\SellerAutomation\AutomationEngine;
use App\Services\SellerAutomation\SellerAutomationRuleService;
use App\Models\Seller;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Rules a seller writes, and the limits that make letting them run unattended defensible.
 *
 * Most of what is asserted here is restraint rather than capability. Hiding a listing is easy; the
 * hard parts are refusing to hide four hundred of them because a stock feed broke, never
 * republishing something a moderator turned down, never touching another shop's catalogue, and
 * leaving enough of a record that a seller who disagrees can put it back.
 *
 * The preview is tested against the run deliberately: they are the same code, and a test that only
 * checked the preview in isolation would not notice if they stopped being.
 */
class SellerAutomationTest extends TestCase
{
    private const SELLER = 1;
    private const RIVAL = 2;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'seller_automation_actions', 'seller_automation_runs', 'seller_automation_rules',
            'products', 'order_details', 'product_price_changes', 'sellers', 'audit_logs',
            'business_settings', 'translations', 'reviews', 'seller_staff', 'seller_roles',
        ] as $table) {
            Schema::dropIfExists($table);
        }

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
            $table->string('auth_token')->nullable();
            $table->timestamps();
        });
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('added_by', 20)->default('seller');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('product_type', 20)->default('physical');
            $table->string('name')->nullable();
            $table->integer('status')->default(1);
            $table->integer('request_status')->default(1);
            $table->decimal('unit_price', 24, 3)->default(0);
            $table->decimal('discount', 24, 3)->default(0);
            $table->string('discount_type', 20)->default('flat');
            $table->integer('current_stock')->default(0);
            $table->text('variation')->nullable();
            $table->timestamps();
        });
        Schema::create('product_price_changes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->decimal('previous_price', 24, 3)->nullable();
            $table->decimal('new_price', 24, 3);
            $table->decimal('previous_discount', 24, 3)->nullable();
            $table->decimal('new_discount', 24, 3)->nullable();
            $table->string('previous_discount_type', 20)->nullable();
            $table->string('new_discount_type', 20)->nullable();
            $table->string('source', 30)->default('seller_ui');
            $table->string('reason', 191)->nullable();
            $table->string('actor_type', 30)->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_name', 191)->nullable();
            $table->timestamps();
        });
        Schema::create('order_details', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('delivery_status', 30)->nullable();
            $table->timestamps();
        });
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('actor_type', 30)->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_name', 191)->nullable();
            $table->string('action', 60);
            $table->string('subject_type', 60)->nullable();
            $table->string('subject_id', 60)->nullable();
            $table->text('before')->nullable();
            $table->text('after')->nullable();
            $table->text('context')->nullable();
            $table->string('ip_address', 60)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();
        });

        $this->runAutomationMigration();

        Seller::insert([
            ['id' => self::SELLER, 'f_name' => 'Owner', 'l_name' => 'One', 'status' => 'approved'],
            ['id' => self::RIVAL, 'f_name' => 'Rival', 'l_name' => 'Two', 'status' => 'approved'],
        ]);
    }

    private function runAutomationMigration(): void
    {
        (require base_path('database/migrations/2026_09_12_000001_create_seller_automation_tables.php'))->up();
        // The column that records which credential wrote the rule, so revoking a key stops it.
        (require base_path('database/migrations/2026_09_16_000001_record_who_created_deferred_seller_work.php'))->up();
        // And the one that records who stopped it, so a marketplace suspension is not the seller's
        // to clear.
        (require base_path('database/migrations/2026_09_16_000002_record_who_suspended_an_automation_rule.php'))->up();
        // And the one that releases automation's claim on a listing somebody else has changed.
        (require base_path('database/migrations/2026_09_16_000003_note_when_something_else_changed_what_a_rule_touched.php'))->up();
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
            'request_status' => 1,
            'unit_price' => 100,
            'discount' => 0,
            'discount_type' => 'flat',
            'current_stock' => 0,
            'variation' => '[]',
            'created_at' => now()->subYear(),
        ], $attributes))->save();

        return $product;
    }

    private function principal(): SellerPrincipal
    {
        return SellerPrincipal::owner(Seller::find(self::SELLER));
    }

    private function rule(array $attributes = []): SellerAutomationRule
    {
        return app(SellerAutomationRuleService::class)->create(array_merge([
            'name' => 'Hide sold-out lines',
            'trigger' => 'out_of_stock',
            'action' => 'hide_listing',
            'trigger_settings' => ['threshold' => 0],
            'max_actions_per_run' => 50,
        ], $attributes), $this->principal());
    }

    private function engine(): AutomationEngine
    {
        return app(AutomationEngine::class);
    }

    public function test_a_rule_hides_a_live_listing_with_nothing_left_to_sell(): void
    {
        $product = $this->product(['current_stock' => 0]);

        $run = $this->engine()->run($this->rule());

        $this->assertSame(SellerAutomationRun::OUTCOME_APPLIED, $run->outcome);
        $this->assertSame(0, (int) $product->fresh()->status);
    }

    public function test_the_preview_says_exactly_what_the_run_would_do(): void
    {
        $this->product(['current_stock' => 0, 'name' => 'Sold out']);
        $rule = $this->rule();

        $preview = $this->engine()->preview($rule);

        $this->assertSame(1, $preview['matched']);
        $this->assertTrue($preview['subjects'][0]['will_apply']);
        $this->assertSame('Sold out', $preview['subjects'][0]['label']);
        $this->assertSame(['status' => 1], $preview['subjects'][0]['before']);
        $this->assertSame(['status' => 0], $preview['subjects'][0]['after']);

        // And nothing moved: a preview that wrote would be a run with a friendlier name.
        $this->assertSame(1, (int) Product::withoutGlobalScope('translate')->first()->status);
        $this->assertSame(0, SellerAutomationRun::count());
    }

    public function test_a_rule_never_reaches_another_shops_catalogue(): void
    {
        $this->product(['user_id' => self::RIVAL, 'current_stock' => 0]);

        $run = $this->engine()->run($this->rule());

        $this->assertSame(SellerAutomationRun::OUTCOME_NO_MATCH, $run->outcome);
        $this->assertSame(1, (int) Product::withoutGlobalScope('translate')->first()->status);
    }

    public function test_a_run_that_would_touch_more_than_allowed_touches_nothing_and_stops(): void
    {
        foreach (range(1, 4) as $ignored) {
            $this->product(['current_stock' => 0]);
        }

        $rule = $this->rule(['max_actions_per_run' => 3]);
        $run = $this->engine()->run($rule);

        // Not "the first three": a rule that suddenly matches far more than expected is usually
        // reading a number that changed meaning, and half-applying that is the hardest outcome to
        // undo.
        $this->assertSame(SellerAutomationRun::OUTCOME_CAPPED, $run->outcome);
        $this->assertSame(0, $run->applied_count);
        $this->assertSame(0, SellerAutomationAction::count());
        $this->assertSame(4, Product::withoutGlobalScope('translate')->where('status', 1)->count());

        $rule->refresh();
        $this->assertSame(SellerAutomationRule::STATUS_SUSPENDED, $rule->status);
        $this->assertSame('automation_suspended_too_many_matches', $rule->suspension_reason);
    }

    public function test_a_suspended_rule_does_not_run_again_on_its_own(): void
    {
        $this->product(['current_stock' => 0]);
        $rule = $this->rule(['max_actions_per_run' => 1]);
        $this->product(['current_stock' => 0]);

        $this->engine()->run($rule);
        $this->assertSame(SellerAutomationRule::STATUS_SUSPENDED, $rule->fresh()->status);

        // The sweep is where automatic recovery would happen if it existed. It does not.
        $runs = $this->engine()->runDue();

        $this->assertSame([], $runs);
        $this->assertSame(2, Product::withoutGlobalScope('translate')->where('status', 1)->count());
    }

    public function test_a_rule_will_not_publish_a_listing_the_marketplace_turned_down(): void
    {
        $product = $this->product(['status' => 0, 'request_status' => 2, 'current_stock' => 10]);

        // Hidden by automation, so the restock trigger would otherwise claim it back.
        SellerAutomationAction::create([
            'run_id' => 999, 'rule_id' => 999, 'seller_id' => self::SELLER,
            'subject_type' => SellerAutomationAction::SUBJECT_PRODUCT, 'subject_id' => $product->id,
            'action' => 'hide_listing', 'status' => SellerAutomationAction::STATUS_APPLIED,
            'before' => ['status' => 1], 'after' => ['status' => 0],
        ]);

        $rule = $this->rule([
            'name' => 'Put back what stock allows',
            'trigger' => 'restocked_after_automation_hid_it',
            'action' => 'publish_listing',
            'trigger_settings' => ['threshold' => 1],
        ]);

        $run = $this->engine()->run($rule);

        $this->assertSame(0, $run->applied_count);
        $this->assertSame(0, (int) $product->fresh()->status);
        $this->assertSame(
            'automation_reason_not_approved',
            SellerAutomationAction::where('run_id', $run->id)->first()->reason,
        );
    }

    public function test_a_rule_only_puts_back_what_automation_itself_took_down(): void
    {
        // Hidden by the seller, by hand. No automation row for it.
        $bySeller = $this->product(['status' => 0, 'current_stock' => 10, 'name' => 'Discontinued']);
        $byRule = $this->product(['status' => 0, 'current_stock' => 10, 'name' => 'Was out of stock']);

        SellerAutomationAction::create([
            'run_id' => 999, 'rule_id' => 999, 'seller_id' => self::SELLER,
            'subject_type' => SellerAutomationAction::SUBJECT_PRODUCT, 'subject_id' => $byRule->id,
            'action' => 'hide_listing', 'status' => SellerAutomationAction::STATUS_APPLIED,
            'before' => ['status' => 1], 'after' => ['status' => 0],
        ]);

        $this->engine()->run($this->rule([
            'name' => 'Put back what stock allows',
            'trigger' => 'restocked_after_automation_hid_it',
            'action' => 'publish_listing',
            'trigger_settings' => ['threshold' => 1],
        ]));

        $this->assertSame(1, (int) $byRule->fresh()->status);
        // The shop is the seller's. A rule about stock levels is not consent to republish a line
        // they took down on purpose.
        $this->assertSame(0, (int) $bySeller->fresh()->status);
    }

    public function test_a_listing_the_seller_has_since_decided_about_is_no_longer_the_rules_to_put_back(): void
    {
        $product = $this->product(['status' => 0, 'current_stock' => 10, 'name' => 'Was out of stock']);

        SellerAutomationAction::create([
            'run_id' => 999, 'rule_id' => 999, 'seller_id' => self::SELLER,
            'subject_type' => SellerAutomationAction::SUBJECT_PRODUCT, 'subject_id' => $product->id,
            'action' => 'hide_listing', 'status' => SellerAutomationAction::STATUS_APPLIED,
            'before' => ['status' => 1], 'after' => ['status' => 0],
        ]);

        // The seller puts it back themselves, then takes it down again on purpose. The trail still
        // says the last thing automation did was hide it, and that is no longer the point.
        $product->forceFill(['status' => 1])->save();
        $product->forceFill(['status' => 0])->save();

        $this->engine()->run($this->rule([
            'name' => 'Put back what stock allows',
            'trigger' => 'restocked_after_automation_hid_it',
            'action' => 'publish_listing',
            'trigger_settings' => ['threshold' => 1],
        ]));

        $this->assertSame(0, (int) $product->fresh()->status);

        // Nor may the same stale row be undone: putting back what the rule replaced would overwrite
        // a decision taken after it.
        $record = SellerAutomationAction::where('subject_id', $product->id)->first();
        $this->assertNotNull($record->superseded_at);
        $this->assertFalse($record->isRevertible());
    }

    public function test_a_markdown_refuses_rather_than_quietly_applying_a_smaller_one(): void
    {
        $product = $this->product(['current_stock' => 5, 'unit_price' => 100]);

        $rule = $this->rule([
            'name' => 'Clear slow stock',
            'trigger' => 'stale_stock',
            'action' => 'set_discount',
            'trigger_settings' => ['days' => 30],
            'action_settings' => [
                'discount_type' => 'percent',
                'discount_value' => 40,
                // 60 is below this, so the rule may not do what it was told.
                'min_price_after_discount' => 80,
            ],
        ]);

        $run = $this->engine()->run($rule);

        $this->assertSame(0, $run->applied_count);
        $this->assertSame(0.0, (float) $product->fresh()->discount);
        $this->assertSame(
            'automation_reason_below_floor',
            SellerAutomationAction::where('run_id', $run->id)->first()->reason,
        );
    }

    public function test_a_markdown_within_the_floor_applies_and_is_attributed_to_the_rule(): void
    {
        $product = $this->product(['current_stock' => 5, 'unit_price' => 100]);

        $this->engine()->run($this->rule([
            'name' => 'Clear slow stock',
            'trigger' => 'stale_stock',
            'action' => 'set_discount',
            'trigger_settings' => ['days' => 30],
            'action_settings' => [
                'discount_type' => 'percent',
                'discount_value' => 10,
                'min_price_after_discount' => 50,
            ],
        ]));

        $this->assertEquals(10, $product->fresh()->discount);

        // The newest row: creating the product recorded its first price, which is a different fact.
        $change = DB::table('product_price_changes')->where('product_id', $product->id)->orderByDesc('id')->first();
        $this->assertNotNull($change, 'An automated price change left no trace.');
        $this->assertSame('automation', $change->source);
        // By name, so a seller looking at a price they did not type can find the rule that moved it.
        $this->assertSame('Clear slow stock', $change->reason);
    }

    public function test_a_product_that_sold_inside_the_window_is_not_stale(): void
    {
        $product = $this->product(['current_stock' => 5]);
        DB::table('order_details')->insert([
            'product_id' => $product->id,
            'delivery_status' => 'delivered',
            'created_at' => now()->subDays(3),
            'updated_at' => now()->subDays(3),
        ]);

        $run = $this->engine()->run($this->rule([
            'trigger' => 'stale_stock',
            'action' => 'hide_listing',
            'trigger_settings' => ['days' => 30],
        ]));

        $this->assertSame(SellerAutomationRun::OUTCOME_NO_MATCH, $run->outcome);
    }

    public function test_a_variant_product_is_left_alone_because_its_header_stock_means_nothing(): void
    {
        $this->product(['current_stock' => 0, 'variation' => '[{"type":"red","qty":4}]']);

        $run = $this->engine()->run($this->rule());

        $this->assertSame(SellerAutomationRun::OUTCOME_NO_MATCH, $run->outcome);
    }

    public function test_a_run_that_changed_nothing_is_still_written_down(): void
    {
        $rule = $this->rule();

        $run = $this->engine()->run($rule);

        // "Found nothing" and "did not run" are different problems, and the rule row alone cannot
        // tell them apart.
        $this->assertSame(SellerAutomationRun::OUTCOME_NO_MATCH, $run->outcome);
        $this->assertSame(1, SellerAutomationRun::count());
        $this->assertSame(1, $rule->fresh()->run_count);
        $this->assertNull($rule->fresh()->last_fired_at);
    }

    public function test_the_cooldown_stops_a_rule_running_twice_in_a_row(): void
    {
        $this->product(['current_stock' => 0]);
        $rule = $this->rule(['cooldown_minutes' => 60]);

        $this->assertCount(1, $this->engine()->runDue());
        $this->assertCount(0, $this->engine()->runDue());

        $rule->forceFill(['last_run_at' => now()->subMinutes(61)])->save();
        $this->assertCount(1, $this->engine()->runDue());
    }

    public function test_a_seller_can_put_back_one_thing_a_rule_did(): void
    {
        $product = $this->product(['current_stock' => 0]);
        $run = $this->engine()->run($this->rule());

        $record = SellerAutomationAction::where('run_id', $run->id)->first();
        $this->assertTrue($record->isRevertible());

        $result = $this->engine()->revert($record, $this->principal());

        $this->assertTrue($result['ok']);
        $this->assertSame(1, (int) $product->fresh()->status);
        $this->assertNotNull($record->fresh()->reverted_at);
        // Once put back, it stays put back — a second undo is not a second toggle.
        $this->assertFalse($record->fresh()->isRevertible());
    }

    public function test_undo_restores_only_what_the_action_says_it_owns(): void
    {
        $product = $this->product(['current_stock' => 0, 'unit_price' => 100]);
        $run = $this->engine()->run($this->rule());

        $record = SellerAutomationAction::where('run_id', $run->id)->first();
        // A trail row edited to carry a column the action never touches.
        $record->forceFill(['before' => ['status' => 1, 'unit_price' => 1]])->save();

        $this->engine()->revert($record->fresh(), $this->principal());

        $this->assertSame(1, (int) $product->fresh()->status);
        $this->assertEquals(100, $product->fresh()->unit_price, 'Undo wrote a column its action does not own.');
    }

    public function test_undo_cannot_reach_another_shops_product(): void
    {
        $product = $this->product(['user_id' => self::RIVAL, 'status' => 0]);

        $record = SellerAutomationAction::create([
            'run_id' => 999, 'rule_id' => 999, 'seller_id' => self::SELLER,
            'subject_type' => SellerAutomationAction::SUBJECT_PRODUCT, 'subject_id' => $product->id,
            'action' => 'hide_listing', 'status' => SellerAutomationAction::STATUS_APPLIED,
            'before' => ['status' => 1], 'after' => ['status' => 0],
        ]);

        $result = $this->engine()->revert($record, $this->principal());

        $this->assertFalse($result['ok']);
        $this->assertSame(0, (int) $product->fresh()->status);
    }

    public function test_a_rule_written_by_a_key_stops_when_the_key_is_revoked(): void
    {
        (require base_path('database/migrations/2026_09_14_000001_create_seller_integration_tables.php'))->up();

        $issued = app(\App\Services\Marketplace\SellerApiKeyService::class)
            ->issue($this->principal(), 'ERP', ['products.manage']);

        $this->product(['current_stock' => 0]);

        $rule = app(SellerAutomationRuleService::class)->create([
            'name' => 'Hide sold-out lines',
            'trigger' => 'out_of_stock',
            'action' => 'hide_listing',
            'trigger_settings' => ['threshold' => 0],
        ], \App\Services\Marketplace\SellerPrincipal::integration(
            Seller::find(self::SELLER),
            $issued['key'],
            ['products.manage'],
        ));

        // The key is recorded, not flattened into "no staff id", which used to mean "the owner".
        $this->assertSame($issued['key']->id, (int) $rule->created_by_api_key_id);

        app(\App\Services\Marketplace\SellerApiKeyService::class)->revoke($issued['key'], $this->principal());

        $run = $this->engine()->run($rule->fresh());

        // Revoking a credential has to stop the work it created, exactly as deactivating a staff
        // member already did — otherwise a revoked key keeps changing the catalogue for ever.
        $this->assertSame(SellerAutomationRun::OUTCOME_FAILED, $run->outcome);
        $this->assertSame(SellerAutomationRule::STATUS_SUSPENDED, $rule->fresh()->status);
        $this->assertSame(1, (int) Product::withoutGlobalScope('translate')->first()->status);
    }

    public function test_a_rule_stops_running_when_the_shop_stops_being_allowed_to_act(): void
    {
        $this->product(['current_stock' => 0]);
        $rule = $this->rule();

        Seller::where('id', self::SELLER)->update(['status' => 'suspended']);

        $run = $this->engine()->run($rule);

        $this->assertSame(SellerAutomationRun::OUTCOME_FAILED, $run->outcome);
        $this->assertSame(SellerAutomationRule::STATUS_SUSPENDED, $rule->fresh()->status);
        $this->assertSame(1, (int) Product::withoutGlobalScope('translate')->first()->status);
    }

    public function test_a_rule_whose_action_the_writer_may_not_perform_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        $clerk = SellerPrincipal::staff(
            Seller::find(self::SELLER),
            new \App\Models\SellerStaff(['seller_id' => self::SELLER, 'name' => 'Clerk']),
            ['products.view'],
        );

        app(SellerAutomationRuleService::class)->create([
            'name' => 'Hide things',
            'trigger' => 'out_of_stock',
            'action' => 'hide_listing',
        ], $clerk);
    }

    public function test_a_trigger_and_an_action_that_cannot_go_together_are_refused(): void
    {
        $this->expectException(ValidationException::class);

        $this->rule(['trigger' => 'out_of_stock', 'action' => 'no_such_action']);
    }

    public function test_settings_a_trigger_never_asked_for_are_not_stored(): void
    {
        $rule = $this->rule(['trigger_settings' => ['threshold' => 2, 'secret_lever' => 'on']]);

        $this->assertSame(['threshold' => 2], $rule->trigger_settings);
    }

    public function test_rewriting_a_rule_clears_the_suspension_it_earned_without_restarting_it(): void
    {
        $this->product(['current_stock' => 0]);
        $this->product(['current_stock' => 0]);
        $rule = $this->rule(['max_actions_per_run' => 1]);

        $this->engine()->run($rule);
        $this->assertSame(SellerAutomationRule::STATUS_SUSPENDED, $rule->fresh()->status);

        app(SellerAutomationRuleService::class)->update($rule->fresh(), [
            'name' => 'Hide sold-out lines',
            'trigger' => 'out_of_stock',
            'action' => 'hide_listing',
            'trigger_settings' => ['threshold' => 0],
            'max_actions_per_run' => 50,
        ], $this->principal());

        // The rule that failed is not the rule that now exists: the suspension and the failures it
        // earned are gone. It does not start running again on its own, though — the seller says so.
        $rule->refresh();
        $this->assertSame(SellerAutomationRule::STATUS_PAUSED, $rule->status);
        $this->assertNull($rule->suspension_reason);
        $this->assertNull($rule->suspended_by);
        $this->assertSame(0, $rule->consecutive_failures);

        $this->assertTrue(
            app(SellerAutomationRuleService::class)
                ->setStatus($rule, SellerAutomationRule::STATUS_ACTIVE, $this->principal())['ok'],
        );
        $this->assertSame(SellerAutomationRule::STATUS_ACTIVE, $rule->fresh()->status);
    }

    public function test_an_edit_that_says_nothing_about_a_setting_leaves_it_alone(): void
    {
        $rule = $this->rule(['max_actions_per_run' => 5, 'cooldown_minutes' => 240]);
        app(SellerAutomationRuleService::class)->setStatus($rule, SellerAutomationRule::STATUS_PAUSED, $this->principal());

        app(SellerAutomationRuleService::class)->update($rule->fresh(), [
            'name' => 'A clearer name',
            'trigger' => 'out_of_stock',
            'action' => 'hide_listing',
        ], $this->principal());

        // Renaming a rule is not asking for it to start running, nor for the two limits that stop
        // it running away to go back to their defaults.
        $rule->refresh();
        $this->assertSame('A clearer name', $rule->name);
        $this->assertSame(SellerAutomationRule::STATUS_PAUSED, $rule->status);
        $this->assertSame(5, $rule->max_actions_per_run);
        $this->assertSame(240, $rule->cooldown_minutes);
    }

    public function test_a_rule_the_marketplace_stopped_is_not_the_sellers_to_restart(): void
    {
        $rule = $this->rule();
        $rule->forceFill([
            'status' => SellerAutomationRule::STATUS_SUSPENDED,
            'suspended_at' => now(),
            'suspension_reason' => 'automation_suspended_by_marketplace',
            'suspended_by' => SellerAutomationRule::SUSPENDED_BY_MARKETPLACE,
        ])->save();

        $service = app(SellerAutomationRuleService::class);

        $result = $service->setStatus($rule, SellerAutomationRule::STATUS_ACTIVE, $this->principal());
        $this->assertFalse($result['ok']);
        $this->assertSame('automation_reason_suspended_by_marketplace', $result['reason']);

        // Nor by the back door: editing the rule does not clear a stop the marketplace applied.
        $service->update($rule->fresh(), [
            'name' => 'Hide sold-out lines',
            'trigger' => 'out_of_stock',
            'action' => 'hide_listing',
            'status' => SellerAutomationRule::STATUS_ACTIVE,
        ], $this->principal());

        $rule->refresh();
        $this->assertSame(SellerAutomationRule::STATUS_SUSPENDED, $rule->status);
        $this->assertSame('automation_suspended_by_marketplace', $rule->suspension_reason);
        $this->assertFalse($rule->isDue());
    }

    public function test_deleting_a_rule_keeps_the_record_of_what_it_did(): void
    {
        $this->product(['current_stock' => 0]);
        $rule = $this->rule();
        $this->engine()->run($rule);

        app(SellerAutomationRuleService::class)->delete($rule, $this->principal());

        $this->assertSame(0, SellerAutomationRule::count());
        // Deleting the rule does not un-happen what it did to the shop.
        $this->assertSame(1, SellerAutomationAction::count());
        $this->assertSame(1, SellerAutomationRun::count());
    }

    public function test_every_automated_change_is_written_down_with_what_it_replaced(): void
    {
        $product = $this->product(['current_stock' => 0, 'name' => 'Sold out']);

        $run = $this->engine()->run($this->rule());
        $record = SellerAutomationAction::where('run_id', $run->id)->first();

        $this->assertSame(SellerAutomationAction::STATUS_APPLIED, $record->status);
        $this->assertSame($product->id, (int) $record->subject_id);
        $this->assertSame('Sold out', $record->subject_label);
        $this->assertSame(['status' => 1], $record->before);
        $this->assertSame(['status' => 0], $record->after);
    }
}
