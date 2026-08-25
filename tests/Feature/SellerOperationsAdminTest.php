<?php

namespace Tests\Feature;

use App\Models\Seller;
use App\Models\SellerApiKey;
use App\Models\SellerAutomationRule;
use App\Models\SellerWebhook;
use App\Services\Marketplace\SellerOperationsOverview;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\Support\BuildsIssueSchema;
use Tests\TestCase;

/**
 * The marketplace's view of what sellers are doing with the platform.
 *
 * Two things are being pinned. The first is that these pages render — a template that compiles is
 * not a template that renders, and every one of them takes a paginator that can be null, a shop
 * name that may not resolve, and JSON columns that may be empty.
 *
 * The second, and the reason the service exists at all, is that "not installed" and "zero" stay
 * distinguishable. An operator looking at a dashboard of zeroes has no way to tell a platform where
 * nobody has written a rule from one where the rules table was never created, and those call for
 * completely different actions.
 */
class SellerOperationsAdminTest extends TestCase
{
    use BuildsIssueSchema;

    private const SELLER = 1;

    protected function setUp(): void
    {
        parent::setUp();
        session(['local' => 'en']);

        foreach ([
            'seller_automation_actions', 'seller_automation_runs', 'seller_automation_rules',
            'seller_webhook_deliveries', 'seller_webhooks', 'seller_api_keys',
            'seller_bulk_jobs', 'seller_staff', 'seller_roles', 'seller_insights',
            'sellers', 'audit_logs', 'business_settings',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('business_settings', function (Blueprint $table) {
            $table->id();
            $table->string('type')->nullable();
            $table->longText('value')->nullable();
            $table->timestamps();
        });
        Schema::create('sellers', function (Blueprint $table) {
            $table->id();
            $table->string('f_name')->nullable();
            $table->string('l_name')->nullable();
            $table->string('status', 20)->default('approved');
            $table->timestamps();
        });

        Seller::insert([['id' => self::SELLER, 'f_name' => 'Owner', 'l_name' => 'One', 'status' => 'approved']]);
    }

    private function overview(): SellerOperationsOverview
    {
        return app(SellerOperationsOverview::class);
    }

    private function installAutomation(): void
    {
        (require base_path('database/migrations/2026_09_12_000001_create_seller_automation_tables.php'))->up();
        (require base_path('database/migrations/2026_09_16_000001_record_who_created_deferred_seller_work.php'))->up();
        (require base_path('database/migrations/2026_09_16_000002_record_who_suspended_an_automation_rule.php'))->up();
        (require base_path('database/migrations/2026_09_16_000003_note_when_something_else_changed_what_a_rule_touched.php'))->up();
        (require base_path('database/migrations/2026_09_17_000001_let_a_rule_be_pointed_at_part_of_the_catalogue.php'))->up();
    }

    private function installIntegrations(): void
    {
        (require base_path('database/migrations/2026_09_14_000001_create_seller_integration_tables.php'))->up();
    }

    public function test_a_table_that_does_not_exist_reads_as_not_installed_rather_than_zero(): void
    {
        $summary = $this->overview()->summary();

        // The whole point of the distinction: an operator seeing "0 suspended rules" on a platform
        // with no rules table would conclude automation is healthy.
        $this->assertFalse($summary['automation']['installed']);
        $this->assertArrayNotHasKey('total', $summary['automation']);
        $this->assertFalse($summary['keys']['installed']);
        $this->assertFalse($summary['webhooks']['installed']);
    }

    public function test_an_installed_but_empty_table_reads_as_zero(): void
    {
        $this->installAutomation();

        $summary = $this->overview()->summary();

        $this->assertTrue($summary['automation']['installed']);
        $this->assertSame(0, $summary['automation']['total']);
        $this->assertSame(0, $summary['automation']['attention']);
    }

    public function test_the_summary_counts_what_needs_attention_not_what_exists(): void
    {
        $this->installAutomation();

        SellerAutomationRule::create([
            'seller_id' => self::SELLER, 'name' => 'Fine', 'trigger' => 'out_of_stock',
            'action' => 'hide_listing', 'status' => SellerAutomationRule::STATUS_ACTIVE,
        ]);
        SellerAutomationRule::create([
            'seller_id' => self::SELLER, 'name' => 'Broken', 'trigger' => 'out_of_stock',
            'action' => 'hide_listing', 'status' => SellerAutomationRule::STATUS_SUSPENDED,
        ]);

        $summary = $this->overview()->summary();

        $this->assertSame(2, $summary['automation']['total']);
        $this->assertSame(1, $summary['automation']['attention']);
    }

    public function test_a_key_nobody_has_ever_used_is_what_the_summary_flags(): void
    {
        $this->installIntegrations();

        SellerApiKey::create([
            'seller_id' => self::SELLER, 'name' => 'Used', 'prefix' => 'aaaaaa',
            'token_hash' => 'x', 'scopes' => ['orders.view'],
        ])->forceFill(['last_used_at' => now()])->save();

        SellerApiKey::create([
            'seller_id' => self::SELLER, 'name' => 'Never used', 'prefix' => 'bbbbbb',
            'token_hash' => 'y', 'scopes' => [],
        ]);

        $summary = $this->overview()->summary();

        $this->assertSame(2, $summary['keys']['total']);
        // Usually the answer to "can this be revoked?".
        $this->assertSame(1, $summary['keys']['attention']);
    }

    public function test_a_revoked_key_is_not_counted_as_live(): void
    {
        $this->installIntegrations();

        SellerApiKey::create([
            'seller_id' => self::SELLER, 'name' => 'Gone', 'prefix' => 'cccccc',
            'token_hash' => 'z', 'scopes' => [],
        ])->forceFill(['revoked_at' => now()])->save();

        $this->assertSame(0, $this->overview()->summary()['keys']['total']);
    }

    public function test_rules_are_ordered_worst_state_first(): void
    {
        $this->installAutomation();

        foreach ([
            ['Active', SellerAutomationRule::STATUS_ACTIVE],
            ['Paused', SellerAutomationRule::STATUS_PAUSED],
            ['Suspended', SellerAutomationRule::STATUS_SUSPENDED],
        ] as [$name, $status]) {
            SellerAutomationRule::create([
                'seller_id' => self::SELLER, 'name' => $name, 'trigger' => 'out_of_stock',
                'action' => 'hide_listing', 'status' => $status,
            ]);
        }

        $names = collect($this->overview()->rules()->items())->pluck('name')->all();

        // An operator opening this page is looking for the shops where automation has stopped
        // working, not browsing a catalogue of rules.
        $this->assertSame(['Suspended', 'Active', 'Paused'], $names);
    }

    public function test_a_filter_narrows_to_one_shop(): void
    {
        $this->installAutomation();

        SellerAutomationRule::create([
            'seller_id' => self::SELLER, 'name' => 'Ours', 'trigger' => 'out_of_stock',
            'action' => 'hide_listing', 'status' => 'active',
        ]);
        SellerAutomationRule::create([
            'seller_id' => 2, 'name' => 'Theirs', 'trigger' => 'out_of_stock',
            'action' => 'hide_listing', 'status' => 'active',
        ]);

        $this->assertCount(1, $this->overview()->rules(sellerId: self::SELLER)->items());
        $this->assertCount(2, $this->overview()->rules()->items());
    }

    public function test_shop_names_are_read_in_one_query_for_the_whole_page(): void
    {
        $sellers = $this->overview()->sellersFor([self::SELLER, self::SELLER, null, 999]);

        // Every list on these pages is "rows plus which shop", and looking the shop up per row is
        // how a twenty-five row page becomes twenty-six queries.
        $this->assertTrue($sellers->has(self::SELLER));
        $this->assertFalse($sellers->has(999));
    }

    public function test_delivery_health_reports_not_installed_rather_than_a_clean_bill(): void
    {
        $health = $this->overview()->deliveryHealth();

        $this->assertFalse($health['installed']);
        $this->assertArrayNotHasKey('delivered', $health);
    }

    public function test_the_overview_page_renders(): void
    {
        $this->installAutomation();
        $this->installIntegrations();

        $html = $this->renderBody('admin-views.marketplace.seller-operations.index', [
            'summary' => $this->overview()->summary(),
            'issuesBySeller' => [],
            'deliveryHealth' => $this->overview()->deliveryHealth(),
            'sellers' => collect(),
        ]);

        $this->assertStringContainsString('Seller operations', $html);
        // The empty state, not a fabricated one.
        $this->assertStringContainsString('No open issues on any shop', $html);
    }

    public function test_the_automation_page_renders_with_nothing_installed(): void
    {
        $html = $this->renderBody('admin-views.marketplace.seller-operations.automation', [
            'rules' => null,
            'activity' => null,
            'sellers' => collect(),
        ]);

        $this->assertStringContainsString('Not installed', $html);
    }

    public function test_only_a_marketplace_suspension_offers_the_control_that_lifts_it(): void
    {
        $this->installAutomation();

        $byBreaker = SellerAutomationRule::create([
            'seller_id' => self::SELLER, 'name' => 'Failed too often', 'trigger' => 'out_of_stock',
            'action' => 'hide_listing', 'status' => SellerAutomationRule::STATUS_SUSPENDED,
        ]);
        $byBreaker->forceFill([
            'suspension_reason' => 'automation_suspended_repeated_failures',
            'suspended_by' => SellerAutomationRule::SUSPENDED_BY_PLATFORM,
        ])->save();

        SellerAutomationRule::create([
            'seller_id' => self::SELLER, 'name' => 'Stopped from here', 'trigger' => 'out_of_stock',
            'action' => 'hide_listing', 'status' => SellerAutomationRule::STATUS_SUSPENDED,
        ])->forceFill([
            'suspension_reason' => 'automation_suspended_by_marketplace',
            'suspended_by' => SellerAutomationRule::SUSPENDED_BY_MARKETPLACE,
        ])->save();

        $html = $this->renderBody('admin-views.marketplace.seller-operations.automation', [
            'rules' => $this->overview()->rules(),
            'activity' => $this->overview()->automationActivity(),
            'sellers' => collect(),
        ]);

        // One "Allow again", not two: a breaker suspension is the seller's own to clear, and a
        // control that lifted it here would take that away from them.
        $this->assertSame(1, substr_count($html, 'Allow again'));
        // Both are on the page, each saying why it stopped.
        $this->assertStringContainsString('Stopped by the marketplace', $html);
        $this->assertStringContainsString(translate('automation_suspended_repeated_failures'), $html);
    }

    public function test_the_integrations_page_renders_a_webhook_that_has_never_been_called(): void
    {
        $this->installIntegrations();

        $webhook = SellerWebhook::create([
            'seller_id' => self::SELLER, 'name' => 'ERP', 'url' => 'https://erp.example.com/hook',
            'events' => ['order.placed'], 'secret' => 'x', 'status' => SellerWebhook::STATUS_ACTIVE,
        ]);

        $html = $this->renderBody('admin-views.marketplace.seller-operations.integrations', [
            'keys' => $this->overview()->keys(),
            'webhooks' => $this->overview()->webhooks(),
            'health' => $this->overview()->deliveryHealth(),
            'sellers' => $this->overview()->sellersFor([$webhook->seller_id]),
        ]);

        // Not a green tick it has not earned.
        $this->assertStringContainsString('Never called', $html);
        $this->assertStringNotContainsString('Healthy', $html);
    }

    public function test_no_page_ever_prints_a_credential(): void
    {
        $this->installIntegrations();

        SellerApiKey::create([
            'seller_id' => self::SELLER, 'name' => 'ERP', 'prefix' => 'dddddd',
            'token_hash' => 'a-hash-nobody-should-ever-see', 'scopes' => ['orders.view'],
        ]);
        SellerWebhook::create([
            'seller_id' => self::SELLER, 'name' => 'ERP', 'url' => 'https://erp.example.com/hook',
            'events' => ['order.placed'], 'secret' => 'a-signing-secret-nobody-should-see',
            'status' => SellerWebhook::STATUS_ACTIVE,
        ]);

        $html = $this->renderBody('admin-views.marketplace.seller-operations.integrations', [
            'keys' => $this->overview()->keys(),
            'webhooks' => $this->overview()->webhooks(),
            'health' => $this->overview()->deliveryHealth(),
            'sellers' => $this->overview()->sellersFor([self::SELLER]),
        ]);

        $this->assertStringNotContainsString('a-hash-nobody-should-ever-see', $html);
        $this->assertStringNotContainsString('a-signing-secret-nobody-should-see', $html);
        // The prefix identifies a row and authenticates nothing, so it is fine to show.
        $this->assertStringContainsString('dddddd', $html);
    }

    public function test_the_team_and_bulk_pages_render_when_their_tables_are_missing(): void
    {
        foreach ([
            ['admin-views.marketplace.seller-operations.team', ['staff' => null, 'sellers' => collect()]],
            ['admin-views.marketplace.seller-operations.bulk-jobs', ['jobs' => null, 'sellers' => collect()]],
        ] as [$view, $data]) {
            $this->assertStringContainsString('Not installed', $this->renderBody($view, $data));
        }
    }

    public function test_only_shops_with_something_wrong_appear_beside_the_traffic(): void
    {
        $this->installAutomation();

        SellerAutomationRule::create([
            'seller_id' => self::SELLER, 'name' => 'Broken', 'trigger' => 'out_of_stock',
            'action' => 'hide_listing', 'status' => SellerAutomationRule::STATUS_SUSPENDED,
        ]);
        SellerAutomationRule::create([
            'seller_id' => 2, 'name' => 'Fine', 'trigger' => 'out_of_stock',
            'action' => 'hide_listing', 'status' => SellerAutomationRule::STATUS_ACTIVE,
        ]);

        $attention = $this->overview()->attentionBySeller();

        // A shop with nothing wrong is not a row of zeroes: a table of them would bury the one
        // that needs somebody.
        $this->assertArrayHasKey(self::SELLER, $attention);
        $this->assertArrayNotHasKey(2, $attention);
        $this->assertSame(1, $attention[self::SELLER]['suspended_rules']);
    }

    public function test_a_shop_can_be_flagged_by_more_than_one_thing_at_once(): void
    {
        $this->installAutomation();
        $this->installIntegrations();

        SellerAutomationRule::create([
            'seller_id' => self::SELLER, 'name' => 'Broken', 'trigger' => 'out_of_stock',
            'action' => 'hide_listing', 'status' => SellerAutomationRule::STATUS_SUSPENDED,
        ]);
        SellerWebhook::create([
            'seller_id' => self::SELLER, 'name' => 'Dead', 'url' => 'https://gone.example.com/h',
            'events' => ['order.placed'], 'secret' => 'x', 'status' => SellerWebhook::STATUS_DISABLED,
        ]);

        $state = $this->overview()->attentionBySeller()[self::SELLER];

        // The second signal must not overwrite the first — they are counted separately.
        $this->assertSame(1, $state['suspended_rules']);
        $this->assertSame(1, $state['failing_webhooks']);
    }

    public function test_the_issues_page_renders_and_offers_only_categories_that_have_something_in_them(): void
    {
        $this->createIssueTable();

        \App\Models\SellerInsight::create([
            'seller_id' => self::SELLER, 'type' => 'ORDER_SLA', 'category' => 'orders',
            'severity' => 'critical', 'status' => 'detected', 'title' => 'insight_order_late',
            'impact_score' => 87, 'affected_count' => 3, 'fingerprint' => 'a',
        ]);

        $categories = $this->overview()->issueCategories();

        // Read from the rows, not from the list of categories the code knows about: a filter
        // offering eight of which six can never match wastes the reader's time.
        $this->assertSame(['orders' => 1], $categories);

        $html = $this->renderBody('admin-views.marketplace.seller-operations.issues', [
            'summary' => $this->overview()->summary(),
            'issues' => $this->overview()->issues(),
            'categories' => $categories,
            'sellers' => $this->overview()->sellersFor([self::SELLER]),
        ]);

        $this->assertStringContainsString('87 / 100', $html);
        $this->assertStringContainsString('Owner One', $html);
    }

    public function test_the_issues_page_shows_only_live_issues(): void
    {
        $this->createIssueTable();

        \App\Models\SellerInsight::create([
            'seller_id' => self::SELLER, 'type' => 'ORDER_SLA', 'category' => 'orders',
            'severity' => 'high', 'status' => 'detected', 'title' => 'live_one', 'fingerprint' => 'a',
        ]);
        \App\Models\SellerInsight::create([
            'seller_id' => self::SELLER, 'type' => 'ORDER_SLA', 'category' => 'orders',
            'severity' => 'high', 'status' => 'resolved', 'title' => 'closed_one', 'fingerprint' => 'b',
        ]);

        // A resolved issue is history, and a queue that mixes the two stops being a queue.
        $this->assertSame(1, $this->overview()->issues()->total());
    }

    /**
     * Render a view's body without the admin layout.
     *
     * The layout drags in the whole admin chrome — sidebar, session, settings, an authenticated
     * user — none of which is under test here, and mocking it would test the mock. The body is what
     * changes and the body is what is checked.
     */
    private function renderBody(string $view, array $data): string
    {
        $source = File::get(resource_path('views/' . str_replace('.', '/', $view) . '.blade.php'));

        $source = preg_replace('/@extends\([^)]*\)/', '', $source, 1);
        $source = preg_replace("/@section\('title'.*?\)/", '', $source, 1);
        $source = str_replace("@section('content')", '', $source);
        $source = preg_replace('/@endsection\b/', '', $source);

        // A unique name per render. Blade caches its compiled output by path, so a fixed probe
        // name makes the second view in a test render the first view's compiled body — which is a
        // confusing failure that has nothing to do with the view under test.
        $name = '__render_probe_' . substr(md5($view . serialize(array_keys($data))), 0, 12);
        $probe = resource_path('views/admin-views/marketplace/seller-operations/' . $name . '.blade.php');
        File::put($probe, $source);

        try {
            return view('admin-views.marketplace.seller-operations.' . $name, $data)->render();
        } finally {
            File::delete($probe);
        }
    }
}
