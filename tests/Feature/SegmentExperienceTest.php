<?php

namespace Tests\Feature;

use App\Models\CustomerSegment;
use App\Models\Theme;
use App\Models\ThemeSection;
use App\Models\ThemeVersion;
use App\Services\Commerce\SegmentResolver;
use App\Services\Commerce\SegmentRules;
use App\Services\Theme\SectionVisibility;
use App\Services\Theme\ThemeDelivery;
use App\Services\Theme\ViewerContext;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Customer segments (Phase 3.4): rule-based, deterministic, fail-open.
 *
 * The promises: "repeat buyer" is a computation over real orders, not a list; a guest never
 * belongs to a segment; a section targeted at a segment shows to its members and to nobody
 * extra; two members share one cached delivery while no customer data enters a shared cache
 * value; and when resolution fails, the shopper gets the base page — not an error, not an empty
 * one (§40–44, §64–65).
 */
class SegmentExperienceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        session(['local' => 'en']);
        config(['commerce.enabled' => true]);

        foreach (['customer_segments', 'orders', 'users', 'theme_blocks', 'theme_sections',
                  'theme_versions', 'themes', 'business_settings'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::create('business_settings', function (Blueprint $table) {
            $table->id(); $table->string('type')->nullable(); $table->longText('value')->nullable();
            $table->timestamps();
        });
        Schema::create('users', function (Blueprint $table) {
            $table->id(); $table->string('name')->nullable(); $table->timestamps();
        });
        Schema::create('orders', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('customer_id')->nullable();
            $table->boolean('is_guest')->default(false);
            $table->decimal('order_amount', 24, 2)->default(0); $table->timestamps();
        });
        Schema::create('customer_segments', function (Blueprint $table) {
            $table->id(); $table->string('name', 120); $table->string('key', 60)->unique();
            $table->boolean('status')->default(true); $table->json('rules')->nullable();
            $table->timestamps();
        });
        Schema::create('themes', function (Blueprint $table) {
            $table->id(); $table->string('name'); $table->string('slug', 120)->unique();
            $table->boolean('is_active')->default(false); $table->boolean('is_system')->default(false);
            $table->timestamps();
        });
        Schema::create('theme_versions', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('theme_id'); $table->string('label')->nullable();
            $table->string('status', 20)->default('draft'); $table->json('settings')->nullable();
            $table->unsignedBigInteger('based_on_version_id')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->unsignedInteger('revision')->default(0); $table->string('checksum', 64)->nullable();
            $table->timestamps();
        });
        Schema::create('theme_sections', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('theme_version_id'); $table->uuid('uuid')->nullable();
            $table->string('page', 60)->default('home'); $table->string('type', 80);
            $table->unsignedInteger('sort_order')->default(0); $table->boolean('is_visible')->default(true);
            $table->json('settings')->nullable();
            $table->timestamp('starts_at')->nullable(); $table->timestamp('ends_at')->nullable();
            $table->json('platforms')->nullable(); $table->json('audience')->nullable();
            $table->timestamps();
        });
        Schema::create('theme_blocks', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('theme_section_id'); $table->string('type', 80);
            $table->unsignedInteger('sort_order')->default(0); $table->boolean('is_visible')->default(true);
            $table->json('settings')->nullable(); $table->timestamps();
        });
    }

    private function repeatBuyerSegment(): CustomerSegment
    {
        return CustomerSegment::create([
            'name' => 'Repeat buyer', 'key' => 'repeat-buyer', 'status' => true,
            'rules' => [['field' => 'orders_count', 'operator' => 'greater_than_or_equal', 'value' => 2]],
        ]);
    }

    private function customerWithOrders(int $count, float $amount = 50): int
    {
        $customerId = DB::table('users')->insertGetId([
            'name' => 'Shopper', 'created_at' => now()->subDays(100), 'updated_at' => now(),
        ]);
        for ($order = 0; $order < $count; $order++) {
            DB::table('orders')->insert([
                'customer_id' => $customerId, 'is_guest' => false, 'order_amount' => $amount,
                'created_at' => now()->subDays($order + 1), 'updated_at' => now(),
            ]);
        }

        return $customerId;
    }

    // ---- membership is a computation --------------------------------------------------------

    public function test_repeat_buyer_is_orders_count_not_a_list(): void
    {
        $this->repeatBuyerSegment();
        $twice = $this->customerWithOrders(2);
        $once = $this->customerWithOrders(1);

        $resolver = app(SegmentResolver::class);

        $this->assertSame(['repeat-buyer'], $resolver->segmentsFor($twice));
        $this->assertSame([], $resolver->segmentsFor($once));
    }

    public function test_a_guest_belongs_to_no_segment(): void
    {
        $this->repeatBuyerSegment();

        $this->assertSame([], app(SegmentResolver::class)->segmentsFor(null));
    }

    public function test_a_customer_who_never_ordered_is_not_zero_days_since_last_order(): void
    {
        CustomerSegment::create([
            'name' => 'Inactive', 'key' => 'inactive', 'status' => true,
            'rules' => [['field' => 'days_since_last_order', 'operator' => 'greater_than', 'value' => 90]],
        ]);
        $neverOrdered = $this->customerWithOrders(0);

        $this->assertSame([], app(SegmentResolver::class)->segmentsFor($neverOrdered),
            'no last order means the rule cannot hold, not that it held at day zero');
    }

    public function test_the_rule_validator_refuses_the_unknown_by_name(): void
    {
        $checked = app(SegmentRules::class)->validate([
            ['field' => 'orders_count', 'operator' => 'greater_than_or_equal', 'value' => 2],
            ['field' => 'shoe_size', 'operator' => 'equals', 'value' => 44],
            ['field' => 'total_spent', 'operator' => 'between', 'value' => 'not-a-pair'],
        ]);

        $this->assertCount(1, $checked['rules']);
        $this->assertCount(2, $checked['errors']);
    }

    // ---- targeting --------------------------------------------------------------------------

    public function test_a_section_targeted_at_a_segment_admits_members_and_nobody_extra(): void
    {
        $section = ['audience' => ['repeat-buyer'], 'is_visible' => true, 'settings' => []];
        $visibility = app(SectionVisibility::class);

        $member = new ViewerContext(authenticated: true, segments: ['repeat-buyer']);
        $outsider = new ViewerContext(authenticated: true, segments: []);
        $guest = new ViewerContext(authenticated: false);

        $this->assertTrue($visibility->passes($section, $member));
        $this->assertFalse($visibility->passes($section, $outsider));
        $this->assertFalse($visibility->passes($section, $guest));
    }

    public function test_guest_and_customer_targeting_still_works_exactly_as_before(): void
    {
        $visibility = app(SectionVisibility::class);
        $guestOnly = ['audience' => ['guest'], 'is_visible' => true, 'settings' => []];

        $this->assertTrue($visibility->passes($guestOnly, new ViewerContext(authenticated: false)));
        $this->assertFalse($visibility->passes($guestOnly, new ViewerContext(authenticated: true, segments: ['repeat-buyer'])));
    }

    public function test_the_delivered_page_differs_by_segment_and_not_by_customer(): void
    {
        $theme = Theme::create(['name' => 'S', 'slug' => 's', 'is_active' => true]);
        $version = ThemeVersion::create([
            'theme_id' => $theme->id, 'status' => ThemeVersion::STATUS_PUBLISHED, 'revision' => 1,
        ]);
        ThemeSection::create([
            'theme_version_id' => $version->id, 'page' => 'home', 'type' => 'spacer',
            'sort_order' => 1, 'is_visible' => true, 'settings' => ['height' => 10],
        ]);
        ThemeSection::create([
            'theme_version_id' => $version->id, 'page' => 'home', 'type' => 'faq',
            'sort_order' => 2, 'is_visible' => true, 'settings' => ['title' => 'Buy again'],
            'audience' => ['repeat-buyer'],
        ]);

        $delivery = app(ThemeDelivery::class);
        $viewerFor = fn (array $segments) => new ViewerContext(
            platform: ViewerContext::PLATFORM_WEB, authenticated: true, segments: $segments,
        );

        $member = $delivery->payload('home', $viewerFor(['repeat-buyer']));
        $outsider = $delivery->payload('home', $viewerFor([]));

        $this->assertContains('faq', array_column($member['sections'], 'type'));
        $this->assertNotContains('faq', array_column($outsider['sections'], 'type'),
            'a personalised section shows to its segment and to nobody extra');
        $this->assertNotSame($member['checksum'], $outsider['checksum'],
            'two segment sets are two pages; sharing a cache entry would leak one into the other');
    }

    // ---- fail-open (§44) --------------------------------------------------------------------

    public function test_resolution_failing_means_the_base_page_not_an_error(): void
    {
        $this->repeatBuyerSegment();
        $customer = $this->customerWithOrders(3);

        // Sabotage: the orders table disappears mid-flight.
        Schema::drop('orders');
        Cache::flush();

        $this->assertSame([], app(SegmentResolver::class)->segmentsFor($customer),
            'no segments, base experience — never an exception a page has to catch');
    }

    public function test_every_rule_arm_reads_the_metrics_it_names(): void
    {
        $rules = app(SegmentRules::class);
        $metrics = ['orders_count' => 3, 'days_since_last_order' => 12,
                    'days_since_registration' => 200, 'total_spent' => 450.0];

        $this->assertTrue($rules->holds(['field' => 'total_spent', 'operator' => 'between', 'value' => [100.0, 500.0]], $metrics));
        $this->assertFalse($rules->holds(['field' => 'total_spent', 'operator' => 'between', 'value' => [500.0, 900.0]], $metrics));
        $this->assertTrue($rules->holds(['field' => 'days_since_registration', 'operator' => 'greater_than', 'value' => 100.0], $metrics));
        $this->assertTrue($rules->holds(['field' => 'orders_count', 'operator' => 'not_equals', 'value' => 4.0], $metrics));
        $this->assertFalse($rules->holds(['field' => 'orders_count', 'operator' => 'not_equals', 'value' => 3.0], $metrics));
        $this->assertTrue($rules->holds(['field' => 'days_since_last_order', 'operator' => 'less_than_or_equal', 'value' => 12.0], $metrics));
    }

    public function test_a_channel_restricted_section_is_withheld_from_the_other_channel(): void
    {
        // The rule was inert on the delivery path: the section array handed to visibility never
        // carried the channels key, so a web-only section reached every app.
        Schema::table('theme_sections', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->json('channels')->nullable();
        });

        $theme = Theme::create(['name' => 'C', 'slug' => 'c', 'is_active' => true]);
        $version = ThemeVersion::create([
            'theme_id' => $theme->id, 'status' => ThemeVersion::STATUS_PUBLISHED, 'revision' => 1,
        ]);
        ThemeSection::create([
            'theme_version_id' => $version->id, 'page' => 'home', 'type' => 'spacer',
            'sort_order' => 1, 'is_visible' => true, 'settings' => ['height' => 10],
            'channels' => ['web'],
        ]);

        $payload = app(ThemeDelivery::class)->payload('home', new ViewerContext(
            platform: ViewerContext::PLATFORM_APP, device: ViewerContext::DEVICE_MOBILE,
        ));

        $this->assertSame([], $payload['sections'], 'a web-only section must never reach the app');
    }

    public function test_saving_delivery_rules_with_the_engine_off_never_widens_a_section(): void
    {
        // The save-time vocabulary must include every key ON RECORD and whatever the section
        // already carried — an admin saving a schedule while commerce is disabled must not
        // silently strip 'repeat-buyer' and show the section to everyone.
        $this->repeatBuyerSegment();
        config(['commerce.enabled' => false]);

        $theme = Theme::create(['name' => 'W', 'slug' => 'w', 'is_active' => true]);
        $version = ThemeVersion::create(['theme_id' => $theme->id, 'status' => ThemeVersion::STATUS_DRAFT]);
        $section = ThemeSection::create([
            'theme_version_id' => $version->id, 'page' => 'home', 'type' => 'spacer',
            'sort_order' => 1, 'is_visible' => true, 'settings' => [],
            'audience' => ['repeat-buyer'],
        ]);

        app(\App\Services\Theme\ThemeBuilderService::class)->setDeliveryRules($section, [
            'audience' => ['repeat-buyer'],
        ]);

        $this->assertSame(['repeat-buyer'], $section->fresh()->audience);
    }

    public function test_the_kill_switch_empties_every_segment(): void
    {
        $this->repeatBuyerSegment();
        $customer = $this->customerWithOrders(5);

        config(['commerce.enabled' => false]);

        $this->assertSame([], app(SegmentResolver::class)->segmentsFor($customer));
    }
}
