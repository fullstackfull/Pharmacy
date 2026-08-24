<?php

namespace Tests\Feature;

use App\Models\Seller;
use App\Services\Analytics\VisitorContext;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The seller's credential must survive the instrumentation that reads the request.
 *
 * Passport erases the Authorization header whenever the bearer it is handed is not one of its own
 * tokens — `TokenGuard::getPsrRequestViaBearerToken` does `$request->headers->set('Authorization',
 * '', true)` in its catch. The analytics middleware runs globally on the `api` group, before the
 * seller guard, and asked the api guard to identify the visitor. So every authenticated seller
 * request arrived at its own middleware with an empty header and was answered 401 — by a healthy
 * server, holding a valid token, on every endpoint at once.
 *
 * It only appeared where Passport keys exist, which is every real deployment and no sandbox, which
 * is why a live sweep of all 169 endpoints could come back clean while the app saw nothing but 401s.
 *
 * The rule this encodes: instrumentation observes a request, it does not change what the request
 * means.
 */
class SellerBearerSurvivesAnalyticsTest extends TestCase
{
    private const TOKEN = 'a-seller-bearer-token-that-is-well-over-thirty-characters';

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['sellers', 'business_settings'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('business_settings', function (Blueprint $table) {
            $table->id();
            $table->string('type')->nullable();
            $table->text('value')->nullable();
            $table->timestamps();
        });
        Schema::create('sellers', function (Blueprint $table) {
            $table->id();
            $table->string('f_name')->nullable();
            $table->string('email')->nullable();
            $table->string('status', 20)->default('approved');
            $table->string('auth_token')->nullable();
            $table->timestamps();
        });

        Seller::insert([[
            'id' => 1, 'f_name' => 'Seller', 'email' => 'seller@example.com',
            'status' => 'approved', 'auth_token' => self::TOKEN,
        ]]);
    }

    public function test_identifying_the_visitor_leaves_the_bearer_where_it_found_it(): void
    {
        $request = Request::create('/api/v3/seller/shop-info', 'GET');
        $request->headers->set('Authorization', 'Bearer ' . self::TOKEN);
        // The resolver the framework installs on a real request. Without it
        // `$request->user('api')` returns null without ever reaching Passport,
        // and this test would pass while the bug was still there.
        $request->setUserResolver(static fn ($guard = null) => auth()->guard($guard)->user());
        // Passport's guard reads the request the container holds, not whatever
        // object it is handed, so this has to be the bound one or the guard
        // never sees the bearer and the test cannot fail.
        $this->app->instance('request', $request);

        app(VisitorContext::class)->resolve($request);

        $this->assertSame(
            'Bearer ' . self::TOKEN,
            $request->headers->get('Authorization'),
            'Analytics read the request and handed back a different one.',
        );
    }

    public function test_a_seller_bearer_still_authenticates_after_the_whole_middleware_stack(): void
    {
        // The end-to-end shape of the bug: the request passes through the global api middleware —
        // telemetry, monitoring, analytics, response-shape recording — before it ever reaches the
        // seller guard. Any of them that resolves a guard can strip the credential on the way.
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . self::TOKEN,
            'Accept' => 'application/json',
        ])->getJson('/api/v3/seller/seller-info');

        $this->assertNotSame(401, $response->getStatusCode(),
            'The seller was rejected while holding the exact token the sellers table stores.');
    }

    public function test_a_credential_that_is_genuinely_absent_is_still_rejected(): void
    {
        // Restoring the header must restore what was there, not invent one.
        $this->getJson('/api/v3/seller/seller-info')->assertStatus(401);

        $this->withHeaders(['Authorization' => 'Bearer short', 'Accept' => 'application/json'])
            ->getJson('/api/v3/seller/seller-info')
            ->assertStatus(401);
    }
}
