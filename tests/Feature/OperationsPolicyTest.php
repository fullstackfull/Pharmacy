<?php

namespace Tests\Feature;

use App\Models\BusinessSetting;
use App\Services\Marketplace\OperationsPolicy;
use App\Services\SellerCenter\Status;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The windows the marketplace judges its sellers by are settings, not constants.
 *
 * These tests hold the three properties that make that safe: an install that changes nothing keeps
 * today's behaviour, a value the operator sets is the value the detectors and the countdown use,
 * and a value that would break the panel is brought inside its bounds rather than obeyed.
 */
class OperationsPolicyTest extends TestCase
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
    }

    private function policy(): OperationsPolicy
    {
        return new OperationsPolicy();
    }

    private function set(string $key, mixed $value): void
    {
        BusinessSetting::updateOrCreate(['type' => $key], ['value' => (string) $value]);
        cache()->flush();
    }

    public function test_an_untouched_install_keeps_the_values_the_constants_had(): void
    {
        $policy = $this->policy();

        $this->assertSame(72, $policy->stuckOrderHours());
        $this->assertSame(45, $policy->stuckStopAfterDays());
        $this->assertSame(0.25, $policy->slaUrgentFraction());
        $this->assertSame(['closing' => 120, 'soon' => 480], $policy->slaBands());
        $this->assertSame(48, $policy->returnsResponseHours());
        $this->assertSame(72, $policy->returnsProcessingHours());
        $this->assertSame(6, $policy->financeGraceHours());
        $this->assertSame(30, $policy->batchExpiryDays());
        $this->assertSame(OperationsPolicy::DEFAULTS, $policy->all());
    }

    public function test_a_stored_value_is_the_value_the_platform_uses(): void
    {
        $this->set('ops_stuck_order_hours', 12);
        $this->set('ops_returns_response_hours', 24);
        $this->set('ops_sla_urgent_fraction', 0.5);

        $this->assertSame(12, $this->policy()->stuckOrderHours());
        $this->assertSame(24, $this->policy()->returnsResponseHours());
        $this->assertSame(0.5, $this->policy()->slaUrgentFraction());
    }

    public function test_a_value_outside_its_bounds_is_clamped_rather_than_obeyed(): void
    {
        $this->set('ops_stuck_order_hours', 0);
        $this->set('ops_batch_expiry_days', 9999);

        $this->assertSame(OperationsPolicy::LIMITS['ops_stuck_order_hours']['min'], $this->policy()->stuckOrderHours());
        $this->assertSame(OperationsPolicy::LIMITS['ops_batch_expiry_days']['max'], $this->policy()->batchExpiryDays());
    }

    public function test_an_unusable_value_falls_back_to_the_shipped_default(): void
    {
        $this->set('ops_finance_grace_hours', '');
        $this->assertSame(6, $this->policy()->financeGraceHours());

        $this->set('ops_finance_grace_hours', 'soon');
        $this->assertSame(6, $this->policy()->financeGraceHours());
    }

    /** A "soon" band inside the "closing" one would make the amber state unreachable. */
    public function test_the_warning_bands_cannot_cross(): void
    {
        $this->set('ops_sla_closing_minutes', 300);
        $this->set('ops_sla_soon_minutes', 60);

        $this->assertSame(['closing' => 300, 'soon' => 300], $this->policy()->slaBands());
    }

    /** The countdown colour reads the policy, so the row and the detector cannot disagree. */
    public function test_the_seller_countdown_colours_by_the_configured_bands(): void
    {
        $now = new \DateTimeImmutable('2026-01-01 12:00:00');
        $dueIn = static fn (int $minutes) => $now->modify('+' . $minutes . ' minutes');

        $this->assertSame('soon', Status::sla(dueAt: $dueIn(200), met: false, now: $now)['state']);
        $this->assertSame('on_time', Status::sla(dueAt: $dueIn(600), met: false, now: $now)['state']);

        $this->set('ops_sla_closing_minutes', 240);
        $this->set('ops_sla_soon_minutes', 720);

        $this->assertSame('closing', Status::sla(dueAt: $dueIn(200), met: false, now: $now)['state']);
        $this->assertSame('soon', Status::sla(dueAt: $dueIn(600), met: false, now: $now)['state']);
    }

    /** Every policy is bounded, labelled, and on the form — a new one cannot arrive unmanageable. */
    public function test_every_policy_is_bounded_and_editable_on_the_admin_page(): void
    {
        $form = file_get_contents(base_path('resources/views/admin-views/marketplace/sla.blade.php'));

        $this->assertSame(array_keys(OperationsPolicy::DEFAULTS), array_keys(OperationsPolicy::LIMITS));

        foreach (array_keys(OperationsPolicy::DEFAULTS) as $key) {
            $this->assertStringContainsString("'" . $key . "' =>", $form, $key . ' has no label on the SLA page');
        }
    }
}
