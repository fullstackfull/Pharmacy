<?php

namespace Tests\Feature\Analytics;

use App\Models\BusinessSetting;
use App\Services\Analytics\AnalyticsEvent;
use App\Services\Analytics\Support\AnalyticsPolicy;
use App\Services\Platform\Policy;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Three ways the analytics pipeline was quietly lying.
 *
 * A dimension's tail was dropped rather than folded, so its rows summed to less than the day and
 * every percentage computed from them was wrong. Two seller-domain events were written with
 * is_internal = 1 and excluded by every rollup, because they are only ever raised while a seller is
 * signed in. And every privacy decision about live customer traffic was an environment variable, on
 * a page that opened by admitting it was read-only.
 */
class AnalyticsIntegrityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('business_settings');
        Schema::create('business_settings', function (Blueprint $table) {
            $table->id();
            $table->string('type')->nullable();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        cache()->flush();
        app()->forgetInstance(Policy::class);
    }

    private function set(string $key, mixed $value): void
    {
        BusinessSetting::updateOrCreate(['type' => $key], ['value' => (string) $value]);
        cache()->flush();
        app()->forgetInstance(Policy::class);
        app()->forgetInstance(AnalyticsPolicy::class);
    }

    // ─────────────────────────────────────────────── seller-domain events

    /**
     * "Internal" means the merchant browsing their own storefront, which is a real filter and the
     * reason a small shop's conversion rate does not look like a rounding error. A payout request is
     * not that.
     */
    public function test_a_seller_domain_event_is_not_treated_as_internal_traffic(): void
    {
        $payout = new AnalyticsEvent(name: AnalyticsEvent::PAYOUT_REQUESTED);
        $kyc = new AnalyticsEvent(name: AnalyticsEvent::KYC_SUBMITTED);
        $pageView = new AnalyticsEvent(name: AnalyticsEvent::PAGE_VIEWED);

        $this->assertTrue($payout->isSellerDomain());
        $this->assertTrue($kyc->isSellerDomain());
        $this->assertFalse($pageView->isSellerDomain(), 'a page view must still be filterable as internal traffic');
    }

    // ──────────────────────────────────────────────────── privacy policy

    public function test_the_shipped_privacy_posture_is_unchanged(): void
    {
        $policy = app(AnalyticsPolicy::class);

        $this->assertTrue($policy->enabled());
        $this->assertFalse($policy->respectDoNotTrack(), 'turning this on must stay a deliberate act');
        $this->assertFalse($policy->requireConsent());
        $this->assertTrue($policy->maskIp());
    }

    public function test_a_privacy_control_can_be_switched_on_without_a_deploy(): void
    {
        $this->set('analytics_require_consent', 1);

        $this->assertTrue(app(AnalyticsPolicy::class)->requireConsent());
    }

    /**
     * The step that makes this safe to introduce on a live platform: a shop with
     * ANALYTICS_REQUIRE_CONSENT=true must not have consent quietly stop being required because a
     * registry default said otherwise.
     */
    public function test_an_environment_configured_install_is_not_overridden_by_a_default(): void
    {
        config()->set('analytics.privacy.require_consent', true);

        $this->assertTrue(app(AnalyticsPolicy::class)->requireConsent());

        // And a stored value still wins over the environment, because it is the more specific one.
        $this->set('analytics_require_consent', 0);

        $this->assertFalse(app(AnalyticsPolicy::class)->requireConsent());
    }

    public function test_a_retention_window_set_here_is_the_one_the_pruner_uses(): void
    {
        $this->assertSame(90, app(AnalyticsPolicy::class)->retentionDays('event_days'));

        $this->set('analytics_retention_event_days', 30);

        $this->assertSame(30, app(AnalyticsPolicy::class)->retentionDays('event_days'));
    }

    // ─────────────────────────────────────────────────────── the fold

    /**
     * config/analytics.php has always promised the tail is folded into `__other__` "and the fold is
     * reported rather than hidden". The rollup applied a LIMIT and wrote no such row.
     */
    public function test_the_rollup_declares_the_fold_it_promises(): void
    {
        $source = file_get_contents(base_path('app/Console/Commands/AnalyticsRollup.php'));

        $this->assertStringContainsString("'__other__'", $source);
        $this->assertStringNotContainsString('->limit($this->cap())', $source, 'the tail is being dropped again');
    }

    /** Distinct counts cannot be summed across keys, so the folded row must not pretend to. */
    public function test_the_folded_row_leaves_the_uncountable_measures_null(): void
    {
        $command = new \App\Console\Commands\AnalyticsRollup();
        $fold = new \ReflectionMethod($command, 'foldTail');
        $fold->setAccessible(true);

        $folded = $fold->invoke($command, collect([
            (object) ['sessions' => 3, 'visitors' => 3, 'orders' => 1, 'revenue' => 10.0],
            (object) ['sessions' => 2, 'visitors' => 2, 'orders' => 1, 'revenue' => 5.5],
        ]));

        $this->assertSame(5, $folded['sessions']);
        $this->assertSame(2, $folded['orders']);
        $this->assertSame(15.5, $folded['revenue']);
        $this->assertNull($folded['visitors'], 'adding distinct counts across keys counts one person once per key');
        $this->assertNull($folded['new_visitors']);
    }
}
