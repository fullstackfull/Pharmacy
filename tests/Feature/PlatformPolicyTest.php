<?php

namespace Tests\Feature;

use App\Models\BusinessSetting;
use App\Services\Platform\Policy;
use App\Services\Platform\PolicyRegistry;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The registry's contract.
 *
 * Ninety hard-coded thresholds became one declaration, one reader and one screen, and what makes
 * that safe rather than merely tidier is the set of properties below: every declared rule is
 * bounded, labelled and validated the same way it is read, a stored value can never take the
 * platform outside those bounds, and an install that changes nothing keeps today's behaviour.
 */
class PlatformPolicyTest extends TestCase
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

    private function policy(): Policy
    {
        return new Policy();
    }

    private function set(string $key, mixed $value): void
    {
        BusinessSetting::updateOrCreate(['type' => $key], ['value' => (string) $value]);
        cache()->flush();
    }

    public function test_every_declared_policy_is_complete(): void
    {
        foreach (PolicyRegistry::definitions() as $key => $definition) {
            $this->assertArrayHasKey('type', $definition, $key);
            $this->assertArrayHasKey('default', $definition, $key);
            $this->assertArrayHasKey('label', $definition, $key);

            if (in_array($definition['type'], ['int', 'decimal', 'ratio'], true)) {
                $this->assertArrayHasKey('min', $definition, $key . ' is numeric but unbounded');
                $this->assertArrayHasKey('max', $definition, $key . ' is numeric but unbounded');
                $this->assertGreaterThan($definition['min'], $definition['max'], $key);
                $this->assertGreaterThanOrEqual($definition['min'], $definition['default'], $key . ' default is below its own minimum');
                $this->assertLessThanOrEqual($definition['max'], $definition['default'], $key . ' default is above its own maximum');
            }

            if (in_array($definition['type'], ['choice', 'multi_choice'], true)) {
                $this->assertNotEmpty($definition['options'] ?? [], $key . ' offers no options');
            }
        }
    }

    /** A key declared twice in two groups would make the last declaration silently win. */
    public function test_no_policy_key_is_declared_twice(): void
    {
        $declared = [];
        foreach (PolicyRegistry::GROUPS as $group => $meta) {
            foreach (array_keys($meta['policies']) as $key) {
                $this->assertArrayNotHasKey($key, $declared, $key . ' is declared in both ' . ($declared[$key] ?? '') . ' and ' . $group);
                $declared[$key] = $group;
            }
        }

        $this->assertCount(count($declared), PolicyRegistry::definitions());
    }

    public function test_an_untouched_install_reads_the_shipped_defaults(): void
    {
        $policy = $this->policy();

        foreach (PolicyRegistry::definitions() as $key => $definition) {
            // Identical, not merely equal: a decimal declared as `0` would be read back as 0.0, and a
            // declaration whose own type is wrong is exactly the drift this registry replaced.
            $this->assertSame($definition['default'], $policy->get($key), $key);
        }
    }

    public function test_a_stored_value_is_read_back(): void
    {
        $this->set('password_minimum_length', 12);
        $this->set('webhook_max_attempts', 9);

        $this->assertSame(12, $this->policy()->int('password_minimum_length'));
        $this->assertSame(9, $this->policy()->int('webhook_max_attempts'));
    }

    public function test_a_value_outside_its_bounds_is_clamped(): void
    {
        $this->set('password_minimum_length', 2);
        $this->set('auth_attempts_per_minute', 999999);

        $this->assertSame(6, $this->policy()->int('password_minimum_length'));
        $this->assertSame(600, $this->policy()->int('auth_attempts_per_minute'));
    }

    public function test_an_unusable_value_falls_back_to_the_default(): void
    {
        $this->set('stock_cover_low_days', 'sometimes');
        $this->assertSame(3.0, $this->policy()->float('stock_cover_low_days'));

        $this->set('stock_cover_low_days', '');
        $this->assertSame(3.0, $this->policy()->float('stock_cover_low_days'));
    }

    public function test_an_unknown_policy_is_a_programming_error_not_a_silent_null(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->policy()->get('ops_the_one_nobody_declared');
    }

    public function test_saving_writes_clamps_and_reports_only_what_changed(): void
    {
        $policy = $this->policy();

        $changes = $policy->save([
            'webhook_max_attempts' => 7,
            'webhook_timeout_seconds' => 8,
            'not_a_policy' => 3,
        ]);

        $this->assertSame(['webhook_max_attempts' => ['from' => 5, 'to' => 7]], $changes);
        $this->assertSame(7, $this->policy()->int('webhook_max_attempts'));
        $this->assertDatabaseMissing('business_settings', ['type' => 'not_a_policy']);

        $this->assertSame([], $policy->save(['webhook_max_attempts' => 7]));
    }

    /** The form must refuse exactly what the reader would clamp, or it saves one number and applies another. */
    public function test_the_generated_rules_bound_every_numeric_policy(): void
    {
        $rules = $this->policy()->rules();

        foreach (PolicyRegistry::definitions() as $key => $definition) {
            $this->assertArrayHasKey($key, $rules, $key . ' has no validation rule');

            if (in_array($definition['type'], ['int', 'decimal', 'ratio'], true)) {
                $this->assertStringContainsString('min:' . $definition['min'], $rules[$key], $key);
                $this->assertStringContainsString('max:' . $definition['max'], $rules[$key], $key);
            }
        }
    }

    public function test_rules_can_be_narrowed_to_one_group(): void
    {
        $rules = $this->policy()->rules('security');

        $this->assertSame(PolicyRegistry::keysIn('security'), array_keys($rules));
    }
}
