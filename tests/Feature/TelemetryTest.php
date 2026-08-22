<?php

namespace Tests\Feature;

use App\Services\Telemetry\TelemetryRecorder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class TelemetryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach (['telemetry_requests', 'telemetry_daily', 'visit_sessions', 'orders', 'products', 'shops'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('visit_sessions', function ($table) {
            $table->id();
            $table->string('visitor_id', 64)->index();
            $table->string('channel', 8)->default('web');
            $table->string('user_type', 16)->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('entry_path', 191)->nullable();
            $table->string('referrer_domain', 191)->nullable();
            $table->string('utm_source', 96)->nullable();
            $table->string('utm_medium', 96)->nullable();
            $table->string('utm_campaign', 96)->nullable();
            $table->string('device', 16)->nullable();
            $table->string('ip_hash', 64)->nullable();
            $table->unsignedInteger('pageviews')->default(0);
            $table->timestamp('started_at');
            $table->timestamp('last_activity_at');
        });
        Schema::create('telemetry_requests', function ($table) {
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
        Schema::create('telemetry_daily', function ($table) {
            $table->id();
            $table->date('date');
            $table->string('scope', 24);
            $table->string('key', 191);
            $table->unsignedBigInteger('hits')->default(0);
            $table->unsignedBigInteger('visitors')->default(0);
            $table->unsignedBigInteger('errors')->default(0);
            $table->unsignedInteger('avg_duration_ms')->nullable();
            $table->decimal('revenue', 24, 2)->nullable();
            $table->unsignedBigInteger('orders')->nullable();
            $table->unique(['date', 'scope', 'key']);
        });
        Schema::create('orders', function ($table) {
            $table->id();
            $table->decimal('order_amount', 24, 2)->default(0);
            $table->timestamps();
        });
        Schema::create('products', function ($table) {
            $table->id();
            $table->string('slug');
        });
        Schema::create('shops', function ($table) {
            $table->id();
            $table->string('slug');
        });

        config(['telemetry.enabled' => true]);
    }

    private function webRequest(string $uri, array $headers = [], array $cookies = []): Request
    {
        $request = Request::create($uri, 'GET');
        foreach ($headers as $key => $value) {
            $request->headers->set($key, $value);
        }
        $request->cookies->add($cookies);
        return $request;
    }

    private function htmlResponse(int $status = 200): Response
    {
        return new Response('<html></html>', $status, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    public function test_web_navigation_records_request_and_session_with_source(): void
    {
        $recorder = app(TelemetryRecorder::class);
        $request = $this->webRequest(
            '/products?utm_source=facebook&utm_medium=cpc',
            ['referer' => 'https://www.google.com/search?q=serum', 'User-Agent' => 'Mozilla/5.0 (iPhone; Mobile Safari)'],
            [TelemetryRecorder::VISITOR_COOKIE => 'visitor-a'],
        );

        $recorder->record($request, $this->htmlResponse(), microtime(true) - 0.2);

        $row = DB::table('telemetry_requests')->first();
        $this->assertSame('web', $row->channel);
        $this->assertSame('products', $row->path);
        $this->assertSame(200, (int) $row->status);
        $this->assertGreaterThanOrEqual(190, (int) $row->duration_ms);
        $this->assertSame('www.google.com', $row->referrer_domain);

        $session = DB::table('visit_sessions')->first();
        $this->assertSame('visitor-a', $session->visitor_id);
        $this->assertSame('facebook', $session->utm_source);
        $this->assertSame('mobile', $session->device);
        $this->assertSame(1, (int) $session->pageviews);
    }

    public function test_second_navigation_within_gap_reuses_the_session(): void
    {
        $recorder = app(TelemetryRecorder::class);
        $cookie = [TelemetryRecorder::VISITOR_COOKIE => 'visitor-b'];
        $recorder->record($this->webRequest('/', [], $cookie), $this->htmlResponse(), microtime(true));
        $recorder->record($this->webRequest('/products', [], $cookie), $this->htmlResponse(), microtime(true));

        $this->assertSame(1, DB::table('visit_sessions')->count());
        $this->assertSame(2, (int) DB::table('visit_sessions')->value('pageviews'));
    }

    public function test_ajax_and_non_html_do_not_create_sessions(): void
    {
        $recorder = app(TelemetryRecorder::class);
        $request = $this->webRequest('/cart/nav-cart-items', [], [TelemetryRecorder::VISITOR_COOKIE => 'visitor-c']);
        $request->headers->set('X-Requested-With', 'XMLHttpRequest');
        $recorder->record($request, new Response('{}', 200, ['Content-Type' => 'application/json']), microtime(true));

        $this->assertSame(1, DB::table('telemetry_requests')->count());
        $this->assertSame(0, DB::table('visit_sessions')->count());
    }

    public function test_ignored_prefixes_record_nothing(): void
    {
        app(TelemetryRecorder::class)->record(
            $this->webRequest('/_debugbar/assets/stylesheets'),
            $this->htmlResponse(),
            microtime(true),
        );
        $this->assertSame(0, DB::table('telemetry_requests')->count());
    }

    public function test_api_requests_record_on_the_api_channel_without_sessions(): void
    {
        app(TelemetryRecorder::class)->record(
            $this->webRequest('/api/v1/products/latest'),
            new Response('{}', 401, ['Content-Type' => 'application/json']),
            microtime(true),
        );

        $row = DB::table('telemetry_requests')->first();
        $this->assertSame('api', $row->channel);
        $this->assertSame(401, (int) $row->status);
        // Not "api:guest:<address hash>" any more: a signed-out API caller that sends no
        // installation id is identified by its network, which is a network and says so.
        $this->assertStringStartsWith('net:', $row->visitor_id);
        $this->assertSame(0, DB::table('visit_sessions')->count());
    }

    public function test_rollup_aggregates_and_prune_respects_retention(): void
    {
        config(['telemetry.retention_days' => 7]);
        $today = now();

        DB::table('telemetry_requests')->insert([
            ['channel' => 'web', 'method' => 'GET', 'path' => 'products', 'status' => 200, 'duration_ms' => 100, 'visitor_id' => 'a', 'created_at' => $today],
            ['channel' => 'web', 'method' => 'GET', 'path' => 'products', 'status' => 200, 'duration_ms' => 300, 'visitor_id' => 'b', 'created_at' => $today],
            ['channel' => 'web', 'method' => 'GET', 'path' => 'product/serum-x', 'status' => 200, 'duration_ms' => 80, 'visitor_id' => 'a', 'created_at' => $today],
            ['channel' => 'api', 'method' => 'GET', 'path' => 'api/v1/products/latest', 'status' => 500, 'duration_ms' => 40, 'visitor_id' => 'api:guest:x', 'created_at' => $today],
            ['channel' => 'web', 'method' => 'GET', 'path' => 'old', 'status' => 200, 'duration_ms' => 10, 'visitor_id' => 'old', 'created_at' => $today->copy()->subDays(9)],
        ]);
        DB::table('visit_sessions')->insert([
            'visitor_id' => 'a', 'channel' => 'web', 'entry_path' => 'products',
            'referrer_domain' => 'google.com', 'utm_source' => 'newsletter', 'device' => 'desktop',
            'pageviews' => 3, 'started_at' => $today, 'last_activity_at' => $today,
        ]);
        DB::table('products')->insert(['id' => 42, 'slug' => 'serum-x']);
        DB::table('orders')->insert(['order_amount' => 150.50, 'created_at' => $today, 'updated_at' => $today]);

        Artisan::call('telemetry:rollup', ['--prune' => true]);

        $day = $today->toDateString();
        $totals = DB::table('telemetry_daily')->where(['date' => $day, 'scope' => 'totals', 'key' => 'web'])->first();
        $this->assertSame(3, (int) $totals->hits);
        $this->assertSame(2, (int) $totals->visitors);

        $product = DB::table('telemetry_daily')->where(['date' => $day, 'scope' => 'product', 'key' => '42'])->first();
        $this->assertNotNull($product);
        $this->assertSame(1, (int) $product->hits);

        $orders = DB::table('telemetry_daily')->where(['date' => $day, 'scope' => 'totals', 'key' => 'orders'])->first();
        $this->assertSame(150.5, (float) $orders->revenue);

        $statusClass = DB::table('telemetry_daily')->where(['date' => $day, 'scope' => 'status_class', 'key' => '5xx'])->first();
        $this->assertSame(1, (int) $statusClass->errors);

        $apiGroup = DB::table('telemetry_daily')->where(['date' => $day, 'scope' => 'api_group', 'key' => 'api/v1/products'])->first();
        $this->assertSame(1, (int) $apiGroup->hits);

        $this->assertSame(0, DB::table('telemetry_requests')->where('path', 'old')->count());
    }

    public function test_disabled_telemetry_records_nothing(): void
    {
        config(['telemetry.enabled' => false]);
        app(TelemetryRecorder::class)->record($this->webRequest('/'), $this->htmlResponse(), microtime(true));
        $this->assertSame(0, DB::table('telemetry_requests')->count());
    }
}
