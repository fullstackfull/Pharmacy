<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AuditLog;
use App\Services\DeveloperPortal\Support\AuthResolver;
use App\Services\Monitoring\Panels\SecurityPanel;
use App\Services\RecaptchaService;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The things a security review looks for, and could not find.
 *
 * A rejected password left no trace anywhere. The audit page rendered the word "changed" over rows
 * that held the old and the new value. The trail itself sat behind an unrelated module flag. The
 * portal reported the vendor API as public. And the platform's only bot defence was switched off in
 * a class comment with no way to switch it back on.
 */
class SecurityCoverageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('audit_logs');
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('actor_type', 40)->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_name', 191)->nullable();
            $table->string('action', 120);
            $table->string('subject_type', 191)->nullable();
            $table->string('subject_id', 64)->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->json('context')->nullable();
            $table->string('ip_address', 64)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestamps();
        });
    }

    // ─────────────────────────────────────────────────── authentication events

    public function test_a_successful_sign_in_is_recorded(): void
    {
        Event::dispatch(new Login('admin', new Admin(['id' => 4, 'f_name' => 'Rana']), false));

        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.signed_in']);
    }

    /** A credential-stuffing run used to be indistinguishable from silence. */
    public function test_a_rejected_password_leaves_a_trace(): void
    {
        Event::dispatch(new Failed('seller', null, ['email' => 'target@example.test', 'password' => 'hunter2']));

        $entry = AuditLog::where('action', 'auth.sign_in_failed')->firstOrFail();

        $this->assertSame('target@example.test', $entry->context['identity']);
        $this->assertFalse($entry->context['account_exists']);
    }

    /** An audit trail is the last place an attempted password may be written down. */
    public function test_the_attempted_password_is_never_recorded(): void
    {
        Event::dispatch(new Failed('seller', null, ['email' => 'target@example.test', 'password' => 'hunter2']));

        $this->assertStringNotContainsString('hunter2', json_encode(AuditLog::first()?->toArray()));
    }

    public function test_a_lockout_is_recorded(): void
    {
        Event::dispatch(new Lockout(request()->merge(['email' => 'target@example.test'])));

        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.locked_out']);
    }

    // ──────────────────────────────────────────────────────── audit readability

    /** The page reported three of its four families permanently missing over coverage that existed. */
    public function test_the_security_page_recognises_the_vocabulary_this_platform_writes(): void
    {
        $families = (new \ReflectionClass(SecurityPanel::class))->getConstant('SECURITY_ACTION_FAMILIES');
        $prefixes = array_merge(...array_values($families));

        foreach (['auth', 'access', 'settings'] as $written) {
            $this->assertContains($written, $prefixes, "the security page cannot see '{$written}.*' actions");
        }
    }

    /** Every module writing to the trail was invisible to a role without an unrelated flag. */
    public function test_reading_the_audit_trail_is_a_system_capability_not_a_marketplace_one(): void
    {
        $route = collect(RouteFacade::getRoutes())->first(
            fn ($route) => $route->getName() === 'admin.marketplace.audit-log',
        );

        $this->assertNotNull($route);
        $this->assertContains('module:system_settings', $route->gatherMiddleware());
        $this->assertNotContains('module:marketplace', $route->gatherMiddleware());
        $this->assertSame('admin/marketplace/audit-log', $route->uri(), 'the URL every existing link uses must not move');
    }

    /** The IA reserved this route from Wave 1 and it did not exist, so the menu item was dropped. */
    public function test_a_seller_can_read_their_own_trail_on_the_web(): void
    {
        $this->assertTrue(RouteFacade::has('seller.audit.index'));
    }

    // ─────────────────────────────────────────────────────────── the portal

    /** The single most dangerous claim a portal can make. */
    public function test_the_v2_seller_api_is_not_reported_as_public(): void
    {
        $route = new \Illuminate\Routing\Route(['GET'], 'api/v2/seller/balance-withdraw', [
            'controller' => \App\Http\Controllers\RestAPI\v2\seller\SellerController::class . '@balance_withdraw',
        ]);

        $resolved = app(AuthResolver::class)->resolve($route);

        $this->assertTrue($resolved['required'], 'the portal tells every reader that balance-withdraw is public');
        $this->assertSame('seller_token', $resolved['mechanism']);
    }

    /**
     * And a controller in the same namespace that authenticates nothing is not claimed as protected.
     *
     * BrandController lists brands under the seller prefix and calls no auth at all. A rule declared
     * per namespace would have documented it as requiring a token, which is the same class of lie in
     * the opposite direction.
     */
    public function test_a_genuinely_public_endpoint_beside_it_is_not_claimed_as_protected(): void
    {
        $route = new \Illuminate\Routing\Route(['GET'], 'api/v2/seller/brands', [
            'controller' => \App\Http\Controllers\RestAPI\v2\seller\BrandController::class . '@getBrands',
        ]);

        $this->assertFalse(app(AuthResolver::class)->resolve($route)['required']);
    }

    /** The real gate on the seller API resolved empty for all 537 endpoints. */
    public function test_the_seller_scope_an_endpoint_requires_is_resolved(): void
    {
        $route = (new \Illuminate\Routing\Route(['GET'], 'api/v3/seller/orders', []))
            ->middleware(['seller_can:orders.view,orders.manage']);

        $this->assertSame(['orders.view', 'orders.manage'], app(AuthResolver::class)->permissions($route));
    }

    // ────────────────────────────────────────────────────────────── recaptcha

    /** Off is still the shipped answer, but it is a setting now rather than a class comment. */
    public function test_recaptcha_is_not_enforced_until_it_is_configured_and_switched_on(): void
    {
        Schema::dropIfExists('business_settings');
        Schema::create('business_settings', function (Blueprint $table) {
            $table->id();
            $table->string('type')->nullable();
            $table->text('value')->nullable();
            $table->timestamps();
        });
        cache()->flush();

        $this->assertFalse(RecaptchaService::isEnforced());

        \App\Models\BusinessSetting::updateOrCreate(['type' => 'recaptcha'], ['value' => json_encode([
            'status' => 1, 'site_key' => '', 'secret_key' => '',
        ])]);
        cache()->flush();

        $this->assertFalse(RecaptchaService::isEnforced(), 'enforcing without keys would refuse every sign-in on the shop');

        \App\Models\BusinessSetting::updateOrCreate(['type' => 'recaptcha'], ['value' => json_encode([
            'status' => 1, 'site_key' => 'site', 'secret_key' => 'secret',
        ])]);
        cache()->flush();

        $this->assertTrue(RecaptchaService::isEnforced());
    }

    /** A staff member's token reached the analytics data while the web page 403'd them. */
    public function test_seller_staff_reach_the_shops_own_analytics_page(): void
    {
        $middleware = app(\App\Http\Middleware\SellerStaffAccessMiddleware::class);
        $required = (new \ReflectionMethod($middleware, 'requiredPermission'));
        $required->setAccessible(true);

        $request = \Illuminate\Http\Request::create('/vendor/analytics', 'GET');

        $this->assertSame('finance.view', $required->invoke($middleware, $request));
    }
}
