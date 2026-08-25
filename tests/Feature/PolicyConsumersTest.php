<?php

namespace Tests\Feature;

use App\Models\BusinessSetting;
use App\Services\Commerce\SegmentRules;
use App\Services\Marketplace\StockPolicy;
use App\Services\Platform\Policy;
use App\Services\Platform\PolicyRegistry;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * A declared policy that nothing reads is worse than a constant, because it looks settable.
 *
 * These tests hold the other half of the registry's contract: the classes that used to carry the
 * constants now ask for the value, so changing it on the admin screen changes what the platform
 * does. Each case sets a policy to something clearly different from the shipped default and asserts
 * the behaviour moved with it.
 */
class PolicyConsumersTest extends TestCase
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

    private function set(string $key, mixed $value): void
    {
        BusinessSetting::updateOrCreate(['type' => $key], ['value' => (string) $value]);
        cache()->flush();
        app()->forgetInstance(Policy::class);
    }

    /** Four surfaces used to disagree about cover; they read one ordered ladder now. */
    public function test_the_stock_cover_ladder_is_ordered_whatever_the_settings_say(): void
    {
        $this->set('stock_cover_critical_days', 10);
        $this->set('stock_cover_low_days', 2);
        $this->set('stock_cover_raise_days', 1);
        $this->set('stock_cover_opportunity_days', 0.5);

        $bands = (new StockPolicy())->coverBands();

        $this->assertSame(
            [10.0, 10.0, 10.0, 10.0],
            [$bands['critical'], $bands['low'], $bands['raise'], $bands['opportunity']],
            'a step of the ladder was allowed to sit inside the step below it',
        );
    }

    public function test_the_inventory_screen_colours_by_the_configured_bands(): void
    {
        $list = app(\App\Services\SellerCenter\Lists\InventoryList::class);

        $this->assertSame('healthy', $list->stateFor(available: 100, coverage: 5.0, threshold: 1)['state']);

        $this->set('stock_cover_low_days', 6);

        $this->assertSame('low_stock', $list->stateFor(available: 100, coverage: 5.0, threshold: 1)['state']);
    }

    /** The opportunity card and the inventory screen measure velocity over the same window. */
    public function test_one_velocity_window_serves_every_surface(): void
    {
        $this->set('stock_velocity_days', 21);

        $this->assertSame(21, (new StockPolicy())->velocityDays());
        $this->assertSame(21, app(\App\Services\SellerCenter\Automation\Opportunities::class)->windowDays());
    }

    /** Going one over a merchandising limit is refused and named, not silently sliced off. */
    public function test_a_merchandising_limit_refuses_rather_than_truncates(): void
    {
        $this->set('commerce_max_segment_rules', 2);

        $rule = ['field' => 'orders_count', 'operator' => 'gte', 'value' => 1];
        $result = app(SegmentRules::class)->validate([$rule, $rule, $rule]);

        $this->assertSame([], $result['rules']);
        $this->assertSame(['rules:at_most_2'], $result['errors']);
    }

    /** One password rule now, where a seller could register at eight characters and reset to six. */
    public function test_one_password_minimum_applies_wherever_a_password_is_chosen(): void
    {
        $this->assertSame('required|string|min:8|max:100', app(\App\Services\Platform\PasswordPolicy::class)->ruleString());

        $this->set('password_minimum_length', 14);

        $this->assertSame('required|string|min:14|max:100', app(\App\Services\Platform\PasswordPolicy::class)->ruleString());
        $this->assertSame('nullable|string|min:14|max:100', app(\App\Services\Platform\PasswordPolicy::class)->ruleString(required: false));
    }

    /** Signing in is deliberately NOT bound by it: raising the minimum must not lock people out. */
    public function test_the_password_minimum_does_not_reach_the_sign_in_validators(): void
    {
        $loginValidators = [
            'app/Http/Controllers/RestAPI/v3/seller/auth/LoginController.php',
            'app/Http/Controllers/RestAPI/v2/seller/auth/LoginController.php',
            'app/Http/Controllers/RestAPI/v1/auth/PassportAuthController.php',
        ];

        foreach ($loginValidators as $file) {
            $source = file_get_contents(base_path($file));
            $login = substr($source, strpos($source, 'function login'));
            $this->assertStringNotContainsString('PasswordPolicy', substr($login, 0, 600), $file);
        }
    }

    /** Six route files carried the same literal; the limiter is one setting now. */
    public function test_the_auth_throttle_is_a_named_limiter_not_a_literal(): void
    {
        foreach (glob(base_path('routes/**/*.php')) + glob(base_path('routes/**/**/*.php')) as $file) {
            $this->assertStringNotContainsString('throttle:20,1', file_get_contents($file), $file);
        }

        $this->assertNotNull(\Illuminate\Support\Facades\RateLimiter::limiter('auth'));
    }

    public function test_the_webhook_delivery_promise_is_the_configured_one(): void
    {
        $dispatcher = app(\App\Services\Marketplace\SellerWebhookDispatcher::class);
        $this->assertSame(5, $dispatcher->maxAttempts());

        $this->set('webhook_max_attempts', 12);

        $this->assertSame(12, app(\App\Services\Marketplace\SellerWebhookDispatcher::class)->maxAttempts());
    }

    /**
     * Every rule the registry declares is read somewhere.
     *
     * A key that nothing consumes is a control that does nothing — the same defect the audit found,
     * wearing a settings page. The search is deliberately crude (the key as a literal anywhere under
     * app/), because the point is only that something asks for it.
     */
    public function test_every_declared_policy_is_read_by_the_code(): void
    {
        $sources = $this->phpSourcesUnder(base_path('app'));
        $unread = [];

        foreach (array_keys(PolicyRegistry::definitions()) as $key) {
            $found = false;

            foreach ($sources as $file) {
                // The registry itself declares every key; a consumer is any other file.
                if (str_ends_with($file, 'PolicyRegistry.php')) {
                    continue;
                }
                if (str_contains(file_get_contents($file), "'" . $key . "'")) {
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                $unread[] = $key;
            }
        }

        $this->assertSame([], $unread, 'declared but read by nothing: ' . implode(', ', $unread));
    }

    /** @return array<int, string> */
    private function phpSourcesUnder(string $directory): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory));

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
