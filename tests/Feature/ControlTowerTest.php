<?php

namespace Tests\Feature;

use App\Models\SellerInsight;
use App\Services\SellerIntelligence\ControlTowerService;
use App\Services\SellerIntelligence\DailyBriefingService;
use App\Services\SellerIntelligence\IssueEscalationService;
use App\Services\SellerIntelligence\Producers\InventoryRiskProducer;
use App\Services\SellerIntelligence\Producers\StaleInventoryProducer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsIssueSchema;
use Tests\TestCase;

/**
 * The command centre, the escalation that feeds it, and the briefing beside it.
 *
 * The arrangement is the feature. A single list sorted by severity still makes a seller read all of
 * it to find the two things that must happen this morning, so the tests here are mostly about which
 * section a thing lands in and what happens when nothing lands anywhere.
 *
 * Escalation is tested for restraint above all. It has to climb, once per step, and it must not
 * touch an issue somebody has said they are working on — punishing a seller for telling the truth
 * about what they are doing teaches them not to.
 */
class ControlTowerTest extends TestCase
{
    use BuildsIssueSchema;

    private const OWNER_TOKEN = 'owner-token-long-enough-to-clear-the-length-gate';

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['sellers', 'orders', 'order_details', 'refund_requests', 'vendor_ledger_entries', 'business_settings', 'audit_logs', 'seller_staff'] as $table) {
            Schema::dropIfExists($table);
        }

        $this->createIssueTable();

        Schema::create('seller_staff', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id');
            $table->string('name', 120)->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });

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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('seller_is', 20)->nullable();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->string('order_status', 30)->nullable();
            $table->decimal('order_amount', 24, 3)->default(0);
            $table->timestamps();
        });
        Schema::create('order_details', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('seller_id')->nullable();
            $table->string('delivery_status', 30)->nullable();
            $table->integer('qty')->default(1);
            $table->decimal('price', 24, 3)->default(0);
            $table->decimal('discount', 24, 3)->default(0);
            $table->timestamps();
        });
        Schema::create('refund_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->string('status', 30)->default('pending');
            $table->decimal('amount', 24, 3)->default(0);
            $table->timestamps();
        });
        Schema::create('vendor_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('seller_id');
            $table->string('seller_is', 20)->default('seller');
            $table->string('entry_type', 40);
            $table->decimal('debit', 24, 4)->default(0);
            $table->decimal('credit', 24, 4)->default(0);
            $table->decimal('balance_after', 24, 4)->default(0);
            $table->string('status', 20)->default('pending');
            $table->string('reference_type', 60)->nullable();
            $table->string('reference_id', 60)->nullable();
            $table->timestamps();
        });

        DB::table('sellers')->insert([
            ['id' => 1, 'f_name' => 'Owner', 'l_name' => 'One', 'status' => 'approved', 'auth_token' => self::OWNER_TOKEN],
            ['id' => 2, 'f_name' => 'Rival', 'l_name' => 'Two', 'status' => 'approved', 'auth_token' => 'rival-token-long-enough-to-clear-the-gate!!'],
        ]);
    }

    private function issue(array $attributes = []): SellerInsight
    {
        static $sequence = 0;
        $sequence++;

        return SellerInsight::create(array_merge([
            'seller_id' => 1,
            'type' => 'TEST',
            'category' => SellerInsight::CATEGORY_ORDERS,
            'severity' => SellerInsight::SEVERITY_MEDIUM,
            'status' => SellerInsight::STATUS_OPEN,
            'title' => 'something',
            'fingerprint' => 'fp-' . $sequence,
            'affected_count' => 1,
            'first_detected_at' => now(),
            'last_detected_at' => now(),
        ], $attributes));
    }

    private function tower(): ControlTowerService
    {
        return app(ControlTowerService::class);
    }

    // ------------------------------------------------------- control tower

    public function test_the_sections_answer_what_now_and_what_today(): void
    {
        $this->issue(['severity' => SellerInsight::SEVERITY_CRITICAL]);
        $this->issue(['severity' => SellerInsight::SEVERITY_HIGH, 'due_at' => now()->addHours(3)]);
        $this->issue(['severity' => SellerInsight::SEVERITY_LOW, 'due_at' => now()->addDays(9)]);

        $sections = $this->tower()->forSeller(1)['sections'];

        $this->assertSame(1, $sections['critical_now']['count']);
        $this->assertSame(1, $sections['needs_action_today']['count']);
        // The one due in nine days is in its domain section and in neither of the urgent two.
        $this->assertSame(3, $sections['sla_risk']['count']);
    }

    public function test_a_critical_issue_is_not_also_listed_as_due_today(): void
    {
        $this->issue(['severity' => SellerInsight::SEVERITY_CRITICAL, 'due_at' => now()->addHour()]);

        $sections = $this->tower()->forSeller(1)['sections'];

        // Reading the same row twice under two headings makes a seller think there are two problems.
        $this->assertSame(1, $sections['critical_now']['count']);
        $this->assertSame(0, $sections['needs_action_today']['count']);
    }

    public function test_a_section_carries_what_it_is_about_not_only_how_many_rows(): void
    {
        $this->issue(['category' => SellerInsight::CATEGORY_INVENTORY, 'affected_count' => 37]);

        $section = $this->tower()->forSeller(1)['sections']['inventory_risk'];

        // "37 products require action" is the sentence. "1 issue" is not.
        $this->assertSame(1, $section['count']);
        $this->assertSame(37, $section['affected']);
    }

    public function test_a_quiet_shop_gets_empty_sections_rather_than_something_reassuring(): void
    {
        $tower = $this->tower()->forSeller(1);

        foreach ($tower['sections'] as $name => $section) {
            $this->assertSame(0, $section['count'], $name . ' invented something.');
        }
        $this->assertSame(0, $tower['counts']['total']);
    }

    public function test_health_reports_nothing_detected_rather_than_fine(): void
    {
        $this->issue(['category' => SellerInsight::CATEGORY_FINANCE, 'severity' => SellerInsight::SEVERITY_CRITICAL]);
        $this->issue(['category' => SellerInsight::CATEGORY_SHIPPING, 'severity' => SellerInsight::SEVERITY_MEDIUM]);

        $health = $this->tower()->forSeller(1)['health'];

        $this->assertSame('critical', $health['finance']['state']);
        $this->assertSame('watch', $health['shipping']['state']);
        // A domain nothing has been detected in. A narrower claim than "fine", and worded that way.
        $this->assertSame('healthy', $health['returns']['state']);
    }

    public function test_another_sellers_problems_are_not_in_this_sellers_tower(): void
    {
        $this->issue(['seller_id' => 2, 'severity' => SellerInsight::SEVERITY_CRITICAL]);

        $this->assertSame(0, $this->tower()->forSeller(1)['sections']['critical_now']['count']);
    }

    public function test_a_dismissed_issue_leaves_the_tower(): void
    {
        $issue = $this->issue(['severity' => SellerInsight::SEVERITY_HIGH]);
        $issue->forceFill(['status' => SellerInsight::STATUS_DISMISSED, 'dismissed_at' => now()])->save();

        $this->assertSame(0, $this->tower()->forSeller(1)['sections']['sla_risk']['count']);
    }

    public function test_what_the_platform_closed_by_itself_is_shown_so_it_can_be_checked(): void
    {
        $this->issue([
            'status' => SellerInsight::STATUS_AUTO_RESOLVED,
            'resolved_at' => now()->subHours(2),
            'resolution_type' => SellerInsight::RESOLUTION_AUTO,
        ]);

        // "Auto-resolved" is a claim, and a claim nobody can inspect is worth nothing.
        $this->assertSame(1, $this->tower()->forSeller(1)['sections']['recently_auto_resolved']['count']);
    }

    // ---------------------------------------------------------- escalation

    public function test_an_unanswered_issue_climbs_one_step(): void
    {
        $issue = $this->issue([
            'severity' => SellerInsight::SEVERITY_MEDIUM,
            'first_detected_at' => now()->subDays(10),
        ]);

        app(IssueEscalationService::class)->sweep();

        $issue->refresh();
        $this->assertSame(SellerInsight::SEVERITY_HIGH, $issue->severity);
        $this->assertSame(1, $issue->escalation_level);
        // And it shows why, because a severity that changed on its own needs a reason attached.
        $this->assertSame('unattended', $issue->metadata['escalations'][0]['reason']);
    }

    public function test_running_the_sweep_again_does_not_promote_the_same_issue_twice(): void
    {
        $this->issue(['severity' => SellerInsight::SEVERITY_MEDIUM, 'first_detected_at' => now()->subDays(10)]);

        $escalation = app(IssueEscalationService::class);
        $escalation->sweep();
        $second = $escalation->sweep();

        // It runs every four hours. Without this it would climb to critical inside a day.
        $this->assertSame(0, $second['escalated']);
        $this->assertSame(SellerInsight::SEVERITY_HIGH, SellerInsight::first()->severity);
    }

    public function test_an_issue_somebody_is_working_on_is_left_alone(): void
    {
        $issue = $this->issue([
            'severity' => SellerInsight::SEVERITY_MEDIUM,
            'status' => SellerInsight::STATUS_IN_PROGRESS,
            'first_detected_at' => now()->subDays(30),
        ]);

        app(IssueEscalationService::class)->sweep();

        // Escalating this would punish a seller for saying what they are doing, and teach them not
        // to say it.
        $this->assertSame(SellerInsight::SEVERITY_MEDIUM, $issue->refresh()->severity);
    }

    public function test_a_fresh_issue_is_not_escalated(): void
    {
        $issue = $this->issue(['severity' => SellerInsight::SEVERITY_MEDIUM, 'first_detected_at' => now()->subHours(2)]);

        app(IssueEscalationService::class)->sweep();

        $this->assertSame(SellerInsight::SEVERITY_MEDIUM, $issue->refresh()->severity);
    }

    public function test_a_missed_deadline_promotes_immediately(): void
    {
        $issue = $this->issue([
            'severity' => SellerInsight::SEVERITY_MEDIUM,
            'first_detected_at' => now()->subHours(2),
            'due_at' => now()->subHour(),
        ]);

        // Standing time has not been earned, but a deadline has passed, which is its own answer.
        app(IssueEscalationService::class)->sweep();

        $issue->refresh();
        $this->assertSame(SellerInsight::SEVERITY_HIGH, $issue->severity);
        $this->assertSame('overdue', $issue->metadata['escalations'][0]['reason']);
    }

    public function test_escalation_stops_at_critical(): void
    {
        $issue = $this->issue([
            'severity' => SellerInsight::SEVERITY_CRITICAL,
            'first_detected_at' => now()->subDays(90),
        ]);

        app(IssueEscalationService::class)->sweep();

        // Nothing is above it, and a level that kept counting would be a number nobody could act on
        // differently.
        $this->assertSame(SellerInsight::SEVERITY_CRITICAL, $issue->refresh()->severity);
        $this->assertSame(0, $issue->escalation_level);
    }

    // ------------------------------------------------------------ briefing

    public function test_the_briefing_compares_today_with_yesterday(): void
    {
        DB::table('order_details')->insert([
            ['order_id' => 1, 'seller_id' => 1, 'delivery_status' => 'delivered', 'qty' => 2, 'price' => 100,
                'created_at' => now()->subHours(2), 'updated_at' => now()],
            ['order_id' => 2, 'seller_id' => 1, 'delivery_status' => 'delivered', 'qty' => 1, 'price' => 100,
                'created_at' => now()->subDay(), 'updated_at' => now()->subDay()],
        ]);

        $briefing = app(DailyBriefingService::class)->forSeller(1);

        $this->assertEquals(200, $briefing['today']['revenue']);
        $this->assertEquals(100, $briefing['previous_day']['revenue']);
        $this->assertEquals(100.0, $briefing['change']['revenue']);
    }

    public function test_a_comparison_against_a_day_with_nothing_in_it_is_null_not_infinity(): void
    {
        DB::table('order_details')->insert([
            'order_id' => 1, 'seller_id' => 1, 'delivery_status' => 'delivered', 'qty' => 2, 'price' => 100,
            'created_at' => now()->subHours(2), 'updated_at' => now(),
        ]);

        $briefing = app(DailyBriefingService::class)->forSeller(1);

        // Reporting "+∞%" or silently substituting 100 would both be lies.
        $this->assertNull($briefing['change']['revenue']);
    }

    public function test_the_briefing_counts_the_queue_not_the_issues_about_the_queue(): void
    {
        DB::table('orders')->insert([
            'seller_is' => 'seller', 'seller_id' => 1, 'order_status' => 'pending', 'order_amount' => 100,
            'created_at' => now()->subHours(30), 'updated_at' => now(),
        ]);

        $briefing = app(DailyBriefingService::class)->forSeller(1);

        // An issue may have been dismissed. The order still needs shipping.
        $this->assertSame(1, $briefing['waiting']['awaiting_shipment']);
        $this->assertSame(1, $briefing['waiting']['sla_at_risk']);
    }

    public function test_briefing_revenue_is_net_of_the_line_discount(): void
    {
        DB::table('order_details')->insert([
            'order_id' => 1, 'seller_id' => 1, 'delivery_status' => 'delivered', 'qty' => 2, 'price' => 100,
            'discount' => 10, 'created_at' => now()->subHours(2), 'updated_at' => now(),
        ]);

        // The same arithmetic as reconciliation, the statement and the payout. A briefing that read
        // it gross would be the one number of the four that disagreed.
        $this->assertEquals(190, app(DailyBriefingService::class)->forSeller(1)['today']['revenue']);
    }

    public function test_slow_moving_stock_is_not_counted_as_stock_running_out(): void
    {
        $this->issue([
            'type' => InventoryRiskProducer::TYPE,
            'category' => SellerInsight::CATEGORY_INVENTORY,
            'affected_count' => 2,
        ]);
        $this->issue([
            'type' => StaleInventoryProducer::TYPE,
            'category' => SellerInsight::CATEGORY_INVENTORY,
            'affected_count' => 40,
        ]);

        // Both are inventory problems and they are not the same problem: forty slow movers are not
        // forty things about to sell out.
        $this->assertSame(2, app(DailyBriefingService::class)->forSeller(1)['waiting']['low_stock_products']);
    }

    // ----------------------------------------------------------- endpoints

    public function test_a_seller_can_say_they_are_working_on_something(): void
    {
        $issue = $this->issue();

        $this->withHeaders(['Authorization' => 'Bearer ' . self::OWNER_TOKEN, 'Accept' => 'application/json'])
            ->putJson("/api/v3/seller/seller-center/control-tower/issues/{$issue->id}/status", [
                'status' => SellerInsight::STATUS_IN_PROGRESS,
            ])
            ->assertStatus(200);

        $this->assertSame(SellerInsight::STATUS_IN_PROGRESS, $issue->refresh()->status);
    }

    public function test_a_seller_cannot_declare_a_problem_resolved(): void
    {
        $issue = $this->issue();

        // An issue is resolved when the condition producing it stops being true, which the detector
        // decides by ceasing to report it. Otherwise the whole list is a matter of opinion.
        $this->withHeaders(['Authorization' => 'Bearer ' . self::OWNER_TOKEN, 'Accept' => 'application/json'])
            ->putJson("/api/v3/seller/seller-center/control-tower/issues/{$issue->id}/status", [
                'status' => SellerInsight::STATUS_RESOLVED,
            ])
            ->assertStatus(403);

        $this->assertSame(SellerInsight::STATUS_OPEN, $issue->refresh()->status);
    }

    public function test_an_issue_cannot_be_handed_to_somebody_from_another_shop(): void
    {
        $issue = $this->issue();
        $theirs = DB::table('seller_staff')->insertGetId([
            'seller_id' => 2, 'name' => 'Rival clerk', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->withHeaders(['Authorization' => 'Bearer ' . self::OWNER_TOKEN, 'Accept' => 'application/json'])
            ->putJson("/api/v3/seller/seller-center/control-tower/issues/{$issue->id}/status", [
                'status' => SellerInsight::STATUS_IN_PROGRESS,
                'assigned_staff_id' => $theirs,
            ])
            ->assertStatus(403);

        // Refused rather than partly applied: neither the assignment nor the status change lands.
        $issue->refresh();
        $this->assertNull($issue->assigned_staff_id);
        $this->assertSame(SellerInsight::STATUS_OPEN, $issue->status);
    }

    public function test_an_issue_can_be_handed_to_somebody_who_works_here(): void
    {
        $issue = $this->issue();
        $ours = DB::table('seller_staff')->insertGetId([
            'seller_id' => 1, 'name' => 'Our clerk', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->withHeaders(['Authorization' => 'Bearer ' . self::OWNER_TOKEN, 'Accept' => 'application/json'])
            ->putJson("/api/v3/seller/seller-center/control-tower/issues/{$issue->id}/status", [
                'status' => SellerInsight::STATUS_IN_PROGRESS,
                'assigned_staff_id' => $ours,
            ])
            ->assertStatus(200);

        $this->assertSame($ours, $issue->refresh()->assigned_staff_id);
    }

    public function test_another_sellers_issue_cannot_be_moved(): void
    {
        $issue = $this->issue(['seller_id' => 2]);

        $this->withHeaders(['Authorization' => 'Bearer ' . self::OWNER_TOKEN, 'Accept' => 'application/json'])
            ->putJson("/api/v3/seller/seller-center/control-tower/issues/{$issue->id}/status", [
                'status' => SellerInsight::STATUS_ACKNOWLEDGED,
            ])
            ->assertStatus(404);
    }

    public function test_the_tower_needs_a_credential(): void
    {
        $this->getJson('/api/v3/seller/seller-center/control-tower')->assertStatus(401);
        $this->getJson('/api/v3/seller/seller-center/control-tower/briefing')->assertStatus(401);
    }
}
