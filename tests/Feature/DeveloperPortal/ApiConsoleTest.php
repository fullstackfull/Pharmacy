<?php

namespace Tests\Feature\DeveloperPortal;

use App\Services\DeveloperPortal\ApiConsole;
use App\Services\DeveloperPortal\ConsoleGuard;
use Tests\TestCase;

/**
 * A "try it" button on an admin panel is a request aimed at the shop that takes the orders.
 *
 * Every test here is a way that goes wrong. The console's value is that a developer can see what an
 * endpoint really answers; its danger is that the same button can place an order, refund a payment
 * or log somebody in — on live data, from a documentation page, with one click and no undo. So the
 * decision to send is made from the endpoint's own facts, on the server, twice: once to decide
 * whether to draw the form, and again when the button is pressed.
 */
class ApiConsoleTest extends TestCase
{
    private ConsoleGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();

        $this->guard = new ConsoleGuard();
        config()->set('developer_portal.console.enabled', true);
        config()->set('developer_portal.console.allow_writes', false);

        // The manifest reads the shop's settings for its base URL. An empty table is the honest
        // stand-in: the portal has to work on an installation that has configured nothing.
        \Illuminate\Support\Facades\Schema::dropIfExists('business_settings');
        \Illuminate\Support\Facades\Schema::create('business_settings', function ($table) {
            $table->id();
            $table->string('type')->nullable();
            $table->text('value')->nullable();
            $table->timestamps();
        });
        \Illuminate\Support\Facades\Cache::flush();

        // The console now needs its own capability — reading the documentation no longer grants
        // firing requests at the installation. These tests are about the guard and the ceremony
        // around a write, so they act as somebody who holds it.
        $this->be(new class implements \Illuminate\Contracts\Auth\Authenticatable {
            public $admin_role_id = 1;
            public function getAuthIdentifierName() { return 'id'; }
            public function getAuthIdentifier() { return 1; }
            public function getAuthPassword() { return ''; }
            public function getRememberToken() { return null; }
            public function setRememberToken($value) {}
            public function getRememberTokenName() { return null; }
            public function getAuthPasswordName() { return 'password'; }
        }, 'admin');
    }

    public function test_a_read_is_allowed_and_needs_no_ceremony(): void
    {
        $verdict = $this->guard->verdict($this->endpoint(), 'GET');

        $this->assertTrue($verdict['allowed']);
        $this->assertSame(ConsoleGuard::SAFE, $verdict['tier']);
        $this->assertFalse($verdict['needs_confirmation']);
    }

    public function test_writes_are_off_until_the_installation_turns_them_on(): void
    {
        // The default, in every environment. A setting that is only safe when somebody remembers
        // to change it is not a default, so this one is safe when nobody touches it at all.
        $verdict = $this->guard->verdict($this->endpoint(['methods' => ['POST'], 'destructive' => false]), 'POST');

        $this->assertFalse($verdict['allowed']);
        $this->assertSame(ConsoleGuard::WRITE, $verdict['tier']);
        $this->assertSame('DEVELOPER_CONSOLE_ALLOW_WRITES=true', $verdict['remedy'], 'the refusal has to say what would change it');
    }

    public function test_an_enabled_write_still_has_to_be_confirmed(): void
    {
        config()->set('developer_portal.console.allow_writes', true);

        $verdict = $this->guard->verdict($this->endpoint(['methods' => ['POST'], 'destructive' => false]), 'POST');

        $this->assertTrue($verdict['allowed']);
        $this->assertTrue($verdict['needs_confirmation'], 'a live write must never be one misplaced click');
        $this->assertSame('POST', $this->guard->confirmationFor('POST'));
    }

    public function test_money_and_identity_are_never_sendable_at_any_setting(): void
    {
        // The list that no configuration reaches. There is no version of "a documentation page
        // placed an order" that is acceptable, so there is no switch that permits it.
        config()->set('developer_portal.console.allow_writes', true);

        foreach ([
            'api/v1/customer/auth/login',
            'api/v1/customer/order/place',
            'api/v1/customer/order/refund',
            'api/v1/seller/withdraw',
            'api/v1/customer/wallet/add-fund',
            'api/v1/customer/address/delete',
            'api/v1/auth/verify-otp',
        ] as $path) {
            $verdict = $this->guard->verdict($this->endpoint(['path' => '/' . $path, 'methods' => ['POST']]), 'POST');

            $this->assertFalse($verdict['allowed'], "{$path} must never be sendable");
            $this->assertSame(ConsoleGuard::BLOCKED, $verdict['tier']);
        }
    }

    public function test_a_read_under_a_blocked_prefix_is_still_a_read(): void
    {
        // The blunt list applies to writes. Refusing GET /orders as well would make the console
        // useless for the endpoints a developer most often needs to see the shape of, and reading
        // a list changes nothing — which is the whole basis on which reads are allowed.
        $this->assertTrue(
            $this->guard->verdict($this->endpoint(['path' => '/api/v1/customer/order/list']), 'GET')['allowed'],
        );
    }

    public function test_a_read_that_answers_with_a_secret_is_refused(): void
    {
        // The exception to the above: a GET changes nothing, but an endpoint that hands back a
        // token hands it to whoever is looking at the screen — and console transcripts end up in
        // screenshots.
        foreach (['/api/v1/config/webhook-secret', '/api/v1/customer/otp/status'] as $path) {
            $this->assertFalse($this->guard->verdict($this->endpoint(['path' => $path]), 'GET')['allowed'], $path);
        }
    }

    public function test_a_delete_is_never_sendable(): void
    {
        config()->set('developer_portal.console.allow_writes', true);

        $verdict = $this->guard->verdict($this->endpoint(['methods' => ['DELETE'], 'path' => '/api/v1/cart/item']), 'DELETE');

        $this->assertFalse($verdict['allowed']);
        $this->assertSame(ConsoleGuard::BLOCKED, $verdict['tier']);
    }

    public function test_the_console_can_only_aim_at_this_shops_own_api(): void
    {
        // Not defence in depth — the only defence that matters. There is no URL field in the
        // console at all; the target is a manifest entry, and an entry outside api/ is refused.
        $verdict = $this->guard->verdict($this->endpoint(['path' => '/admin/settings']), 'GET');

        $this->assertFalse($verdict['allowed']);
        $this->assertSame(ConsoleGuard::BLOCKED, $verdict['tier']);
    }

    public function test_a_method_the_endpoint_does_not_answer_is_refused(): void
    {
        $this->assertFalse($this->guard->verdict($this->endpoint(['methods' => ['GET']]), 'POST')['allowed']);
    }

    public function test_switching_the_console_off_refuses_even_a_read(): void
    {
        config()->set('developer_portal.console.enabled', false);

        $this->assertFalse($this->guard->verdict($this->endpoint(), 'GET')['allowed']);
    }

    public function test_a_destructive_get_is_treated_as_a_write(): void
    {
        // A GET that changes something is a badly designed endpoint and a real one — the manifest
        // marks it, and the console believes the marking rather than the verb.
        $verdict = $this->guard->verdict($this->endpoint(['destructive' => true]), 'GET');

        $this->assertFalse($verdict['allowed']);
        $this->assertSame(ConsoleGuard::WRITE, $verdict['tier']);
    }

    public function test_a_path_parameter_cannot_add_a_path_segment(): void
    {
        // The one place a value from the browser reaches the URL. A slash here would let the
        // console call an endpoint other than the one it is documenting.
        $console = app(ApiConsole::class);
        $endpoint = $this->endpoint([
            'path' => '/api/v1/products/{slug}',
            'path_parameters' => [['name' => 'slug', 'type' => 'string', 'required' => true]],
        ]);

        foreach (['../../admin/settings', 'a/b', 'x%2Fy', "line\nbreak"] as $hostile) {
            $answer = $console->send($endpoint, 'GET', ['slug' => $hostile], [], null);

            $this->assertFalse($answer['ok'], "'{$hostile}' must not reach the router");
        }
    }

    public function test_a_missing_required_path_parameter_is_refused_rather_than_sent_with_a_brace_in_it(): void
    {
        $answer = app(ApiConsole::class)->send(
            $this->endpoint([
                'path' => '/api/v1/products/{slug}',
                'path_parameters' => [['name' => 'slug', 'type' => 'string', 'required' => true]],
            ]),
            'GET',
            [],
            [],
            null,
        );

        $this->assertFalse($answer['ok']);
    }

    public function test_a_real_call_goes_through_the_real_middleware_and_comes_back_redacted(): void
    {
        $console = app(ApiConsole::class);

        $answer = $console->send($this->endpoint([
            'path' => '/api/v1/deep-link/config',
            'methods' => ['GET'],
        ]), 'GET', [], [], token: 'a-token-that-must-not-come-back');

        $this->assertTrue($answer['ok']);
        $this->assertSame(200, $answer['response']['status']);
        $this->assertIsArray($answer['response']['json']);

        // The token was used and is not in the transcript, in either direction.
        $this->assertTrue($answer['request']['authenticated']);
        $this->assertStringNotContainsString(
            'a-token-that-must-not-come-back',
            json_encode($answer),
        );
    }

    public function test_the_outer_request_survives_the_call(): void
    {
        // app()->handle() rebinds the container's request. Without putting the original back, the
        // admin page finishes rendering as though it were the sub-request — which is how a "try
        // it" console starts returning JSON to the browser instead of a page.
        $before = app('request');

        app(ApiConsole::class)->send($this->endpoint(['path' => '/api/v1/deep-link/config']), 'GET', [], [], null);

        $this->assertSame($before, app('request'));
    }

    public function test_the_response_body_is_bounded(): void
    {
        $reflection = new \ReflectionClass(ApiConsole::class);

        $this->assertLessThanOrEqual(
            256_000,
            $reflection->getConstant('MAX_BODY_BYTES'),
            'a console is not a file viewer',
        );
    }

    public function test_the_consoles_own_probes_are_not_counted_as_shop_traffic(): void
    {
        // A deliberately malformed probe from a documentation page must not land in the route
        // timings and the error rate an operator reads to decide whether the shop is healthy.
        $marked = \Illuminate\Http\Request::create('/api/v1/products/latest', 'GET');
        $marked->headers->set(ApiConsole::MARKER_HEADER, '1');

        \Illuminate\Support\Facades\Schema::dropIfExists('telemetry_requests');
        \Illuminate\Support\Facades\Schema::create('telemetry_requests', function ($table) {
            $table->id();
            $table->unsignedBigInteger('session_id')->nullable();
            $table->string('visitor_id', 64)->nullable();
            $table->string('channel', 8);
            $table->string('method', 8);
            $table->string('route_name', 128)->nullable();
            $table->string('path', 191);
            $table->unsignedSmallInteger('status');
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('user_type', 16)->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('referrer_domain', 191)->nullable();
            $table->timestamp('created_at');
        });

        app(\App\Services\Telemetry\TelemetryRecorder::class)->record(
            $marked,
            new \Symfony\Component\HttpFoundation\Response('{}', 200, ['Content-Type' => 'application/json']),
            microtime(true),
        );

        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('telemetry_requests')->count());

        // Analytics flags rather than drops it, which is the rule everywhere else in this system:
        // a filter nobody can audit is indistinguishable from one that stopped working.
        $this->assertTrue((new \App\Services\Analytics\Support\BotDetector())->isInternal($marked));
    }

    public function test_the_page_renders_with_the_console_on_it(): void
    {
        // Rendered, not merely compiled: a missing key in the payload is a 500 on a page an
        // administrator opens, and it would not show up in a syntax check.
        $stub = sys_get_temp_dir() . '/console-view-stub';
        @mkdir($stub . '/layouts/admin', 0777, true);
        file_put_contents($stub . '/layouts/admin/app.blade.php', '@yield("content")');
        \Illuminate\Support\Facades\View::getFinder()->prependLocation($stub);

        $payload = app(\App\Services\Telemetry\DeveloperPortalService::class)
            ->endpoint($this->realEndpointId('/api/v1/deep-link/config', 'GET'));

        $html = view('admin-views.telemetry.developer-endpoint', ['endpoint' => $payload])->render();

        $this->assertStringContainsString('data-console', $html);
        // The tier the guard decided, carried into the page as data rather than re-derived in it.
        // Escaped, because it rides in an attribute — which is also how the browser reads it back.
        $this->assertStringContainsString('tier&quot;:&quot;safe', $html);
    }

    public function test_a_refused_method_reaches_the_page_as_a_refusal_not_as_a_button(): void
    {
        $verdicts = app(\App\Services\Telemetry\DeveloperPortalService::class)
            ->endpoint($this->realEndpointId('/api/v1/auth/login', 'POST'))['console'];

        $this->assertFalse($verdicts['POST']['allowed']);
        $this->assertNotNull($verdicts['POST']['message'], 'the page needs a sentence to show instead of the form');
    }

    public function test_the_endpoint_that_sends_is_a_post_behind_the_admin_panel(): void
    {
        // A GET would be prefetchable, shareable and crawlable — three ways for a request at the
        // live API to be sent by something other than the person reading the page.
        $route = collect(\Illuminate\Support\Facades\Route::getRoutes()->getRoutes())
            ->first(fn ($candidate) => $candidate->getName() === 'admin.developer.try');

        $this->assertNotNull($route, 'the console route is not registered');
        $this->assertSame(['POST'], array_values(array_diff($route->methods(), ['HEAD'])));

        $middleware = $route->gatherMiddleware();
        $this->assertContains('admin', $middleware, 'the console must not be reachable without an administrator');
        $this->assertContains('module:system_settings', $middleware);
    }

    public function test_the_controller_refuses_a_blocked_call_even_when_the_browser_asks_for_it(): void
    {
        // The page hides the button; this is what happens when somebody un-hides it. The guard runs
        // again on the server, because a decision made only in the page is not a decision.
        config()->set('developer_portal.console.allow_writes', true);

        $response = $this->callConsole($this->realEndpointId('/api/v1/auth/login', 'POST'), ['method' => 'POST']);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function test_the_controller_refuses_a_write_that_was_not_confirmed(): void
    {
        config()->set('developer_portal.console.allow_writes', true);

        $id = $this->realEndpointId('/api/v1/cart/select-cart-items', 'POST');

        $this->assertSame(422, $this->callConsole($id, ['method' => 'POST'])->getStatusCode());
        $this->assertSame(422, $this->callConsole($id, ['method' => 'POST', 'confirm' => 'yes'])->getStatusCode());
    }

    public function test_the_console_is_rate_limited_per_administrator(): void
    {
        \Illuminate\Support\Facades\RateLimiter::clear('developer-console:unknown');
        config()->set('developer_portal.console.rate_limit_per_minute', 2);

        $id = $this->realEndpointId('/api/v1/deep-link/config', 'GET');

        $this->assertSame(200, $this->callConsole($id, ['method' => 'GET'])->getStatusCode());
        $this->assertSame(200, $this->callConsole($id, ['method' => 'GET'])->getStatusCode());
        $this->assertSame(429, $this->callConsole($id, ['method' => 'GET'])->getStatusCode());

        \Illuminate\Support\Facades\RateLimiter::clear('developer-console:unknown');
    }

    public function test_an_endpoint_that_is_not_in_the_manifest_is_not_a_target(): void
    {
        $this->assertSame(404, $this->callConsole('0000000000000000', ['method' => 'GET'])->getStatusCode());
    }

    // ---------------------------------------------------------------------------------------

    /** @param array<string, mixed> $input */
    public function test_an_admin_without_the_console_capability_is_refused_before_anything_is_sent(): void
    {
        // The gate that made this split worth making: reading the documentation used to be the
        // only thing standing between an operator and a real request against this installation.
        $this->be(new class implements \Illuminate\Contracts\Auth\Authenticatable {
            public $admin_role_id = 7;
            public $role;
            public function __construct() { $this->role = (object) ['module_access' => json_encode(['system_settings'])]; }
            public function getAuthIdentifierName() { return 'id'; }
            public function getAuthIdentifier() { return 2; }
            public function getAuthPassword() { return ''; }
            public function getRememberToken() { return null; }
            public function setRememberToken($value) {}
            public function getRememberTokenName() { return null; }
            public function getAuthPasswordName() { return 'password'; }
        }, 'admin');

        $response = $this->callConsole('0000000000000000', ['method' => 'GET']);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertFalse($response->getData(true)['ok']);
    }

    private function callConsole(string $id, array $input): \Illuminate\Http\JsonResponse
    {
        $request = \Illuminate\Http\Request::create('/admin/developer/try/' . $id, 'POST', $input);

        return app(\App\Http\Controllers\Admin\Telemetry\DeveloperPortalController::class)
            ->try($request, $id, app(ApiConsole::class), $this->guard);
    }

    private function realEndpointId(string $path, string $method): string
    {
        $endpoint = app(\App\Services\DeveloperPortal\ApiManifest::class)->findByPath($path, $method);

        $this->assertNotNull($endpoint, "{$path} is not in the route table any more; this test needs a live example");

        return $endpoint['id'];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function endpoint(array $overrides = []): array
    {
        return array_merge([
            'id' => 'abcdef0123456789',
            'path' => '/api/v1/products/latest',
            'methods' => ['GET'],
            'destructive' => false,
            'path_parameters' => [],
        ], $overrides);
    }
}
