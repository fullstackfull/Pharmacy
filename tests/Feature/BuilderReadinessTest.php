<?php

namespace Tests\Feature;

use App\Models\BusinessSetting;
use App\Services\Theme\BuilderReadiness;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The server checklist behind the App Builder's readiness panel.
 *
 * Each check exists because its failure used to be invisible: a deployment that skipped a
 * migration, a cron nobody installed, a rollup that never fired. What these pin down is that the
 * panel tells the truth — a passing check really passes, a failing one names its fix — because a
 * reassuring green row over a dead scheduler is worse than no panel at all.
 */
class BuilderReadinessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        session(['local' => 'en']);

        foreach (['experience_pages', 'theme_versions', 'business_settings'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::create('business_settings', function (Blueprint $table) {
            $table->id(); $table->string('type')->nullable(); $table->longText('value')->nullable();
            $table->timestamps();
        });
        Schema::create('experience_pages', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('theme_id'); $table->string('slug', 60);
            $table->timestamps();
        });
        Schema::create('theme_versions', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('theme_id');
            $table->string('status', 20)->default('draft');
            $table->string('change_note', 300)->nullable();
            $table->timestamp('publish_at')->nullable();
            $table->timestamps();
        });
    }

    public function test_a_fully_migrated_store_passes(): void
    {
        $this->assertTrue(app(BuilderReadiness::class)->storeIsMigrated());
    }

    public function test_a_missing_migration_fails_the_store_check_with_the_fix(): void
    {
        Schema::dropIfExists('experience_pages');

        $readiness = app(BuilderReadiness::class);
        $this->assertFalse($readiness->storeIsMigrated());

        $store = collect($readiness->checks())->firstWhere('key', 'store');
        $this->assertFalse($store['ok']);
        $this->assertSame('php artisan migrate', $store['fix']);
    }

    public function test_a_fresh_heartbeat_means_the_scheduler_is_alive(): void
    {
        BusinessSetting::create(['type' => 'scheduler_last_run_at', 'value' => now()->toIso8601String()]);

        $this->assertTrue(app(BuilderReadiness::class)->schedulerIsRunning());
    }

    public function test_a_stale_or_absent_heartbeat_fails_with_the_crontab_line(): void
    {
        // Stale is dead: a scheduler that last ran an hour ago is not going to fire tonight's
        // publish, and the panel saying otherwise would cost a merchant a launch.
        BusinessSetting::create([
            'type' => 'scheduler_last_run_at', 'value' => now()->subHour()->toIso8601String(),
        ]);

        $readiness = app(BuilderReadiness::class);
        $this->assertFalse($readiness->schedulerIsRunning());

        $scheduler = collect($readiness->checks())->firstWhere('key', 'scheduler');
        $this->assertFalse($scheduler['ok']);
        $this->assertStringContainsString('php artisan schedule:run', $scheduler['fix']);
    }

    public function test_the_analytics_row_repeats_what_the_analytics_pages_say(): void
    {
        // No analytics tables in this schema, so the health is "not installed" — and the panel
        // must carry that exact explanation rather than invent its own reading of the pipeline.
        $analytics = collect(app(BuilderReadiness::class)->checks())->firstWhere('key', 'analytics');

        $this->assertFalse($analytics['ok']);
        $this->assertNotNull($analytics['why']);
    }

    public function test_every_check_carries_what_the_panel_renders(): void
    {
        foreach (app(BuilderReadiness::class)->checks() as $check) {
            $this->assertNotSame('', $check['key']);
            $this->assertIsBool($check['ok']);
            $this->assertNotSame('', $check['label']);
            if (!$check['ok']) {
                $this->assertNotNull($check['why'], $check['key'] . ' fails without saying why');
            }
        }
    }
}
