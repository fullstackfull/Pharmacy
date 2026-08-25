<?php

namespace Tests\Feature;

use App\Models\BusinessSetting;
use App\Models\SellerInsight;
use App\Services\Commerce\CommerceExperience;
use App\Services\Platform\Policy;
use App\Services\SellerIntelligence\IssueEscalationService;
use App\Services\SellerIntelligence\Severity\SeverityEngine;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The marketplace's posture toward its own sellers, and its own rollback switch.
 *
 * Thirteen severity constants decided what every seller sees first. An escalation ladder decided how
 * long a problem may stand before the platform raises the pressure. A notification window decided
 * how often somebody is interrupted. None of them could be changed without a deploy — and neither
 * could the master switch that is the documented rollback path for the whole personalisation engine.
 */
class IssuePolicyTest extends TestCase
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
        app()->forgetInstance(CommerceExperience::class);
    }

    public function test_the_shipped_bands_are_the_ones_the_constants_had(): void
    {
        $this->assertSame(['critical' => 75, 'high' => 40, 'medium' => 20], SeverityEngine::bands());
    }

    public function test_a_marketplace_can_decide_what_critical_means_on_its_catalogue(): void
    {
        $this->set('issue_band_critical', 90);

        $this->assertSame(90, SeverityEngine::bands()['critical']);
    }

    /** A "high" band above "critical" makes critical unreachable rather than strict. */
    public function test_the_bands_cannot_cross(): void
    {
        $this->set('issue_band_critical', 50);
        $this->set('issue_band_high', 80);
        $this->set('issue_band_medium', 90);

        $this->assertSame(['critical' => 50, 'high' => 50, 'medium' => 50], SeverityEngine::bands());
    }

    public function test_the_escalation_ladder_is_the_marketplaces_to_set(): void
    {
        $this->assertSame(336, IssueEscalationService::promoteAfterHours()[SellerInsight::SEVERITY_LOW]);

        $this->set('issue_promote_low_hours', 72);

        $this->assertSame(72, IssueEscalationService::promoteAfterHours()[SellerInsight::SEVERITY_LOW]);
        $this->assertSame(3, IssueEscalationService::maxEscalationLevel());
    }

    /** The rollback an operator most needs at 2am was the one that required a release. */
    public function test_the_personalisation_engine_can_be_switched_off_without_a_deploy(): void
    {
        $this->assertTrue(app(CommerceExperience::class)->enabled());

        $this->set('commerce_experience_enabled', 0);

        $this->assertFalse(app(CommerceExperience::class)->enabled());
    }

    public function test_an_environment_switched_off_install_stays_off(): void
    {
        config()->set('commerce.enabled', false);

        $this->assertFalse(app(CommerceExperience::class)->enabled());
    }

    /** Three complete features an operator could reach only by opening a fourth. */
    public function test_every_commerce_feature_is_reachable_from_the_sidebar(): void
    {
        $sidebar = file_get_contents(base_path('resources/views/layouts/admin/partials/v2/_side-bar.blade.php'));

        foreach (['collections', 'campaigns', 'segments', 'experiments'] as $feature) {
            $this->assertStringContainsString("admin.commerce.{$feature}.index", $sidebar, $feature . ' is not linked from the sidebar');
            $this->assertTrue(RouteFacade::has("admin.commerce.{$feature}.index"));
        }
    }

    /** Same controller, same five actions, one copy outside the module gate and linked from nothing. */
    public function test_the_addon_manager_is_mounted_once(): void
    {
        $mounts = collect(RouteFacade::getRoutes())
            ->filter(fn ($route) => $route->getName() !== null && str_ends_with($route->getName(), 'addon.upload'))
            ->count();

        $this->assertSame(1, $mounts, 'the addon uploader is mounted more than once');
    }
}
