<?php

namespace Tests\Feature\Analytics;

use App\Services\Analytics\AnalyticsPermissionService;
use App\Services\Analytics\Reporting\AnalyticsReporting;
use App\Services\Analytics\Reporting\Window;
use App\Services\Analytics\Support\AttributionEngine;
use App\Services\Analytics\Support\BotDetector;
use App\Services\Analytics\Support\CampaignDestination;
use App\Services\Analytics\Support\PathNormalizer;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * The rules that make these numbers worth reading. Each one is here because breaking it produces a
 * report that looks fine and is wrong, which is the only failure mode that matters in analytics.
 */
class AnalyticsPipelineTest extends TestCase
{
    // ── Attribution ──────────────────────────────────────────────────────────────────────────

    public function test_an_explicit_campaign_tag_beats_the_referrer_that_carried_it(): void
    {
        // A visitor arriving from a Google search on a link that was tagged for a Facebook ad came
        // from the ad. Preferring the referrer would credit organic search with every paid click
        // that happened to be shared on.
        $engine = new AttributionEngine();

        $request = Request::create('/?utm_source=facebook&utm_medium=cpc&utm_campaign=ramadan', 'GET');
        $request->headers->set('referer', 'https://www.google.com/search?q=pharmacy');

        $resolved = $engine->resolve($request);

        $this->assertSame('facebook', $resolved['source']);
        $this->assertSame('cpc', $resolved['medium']);
        $this->assertSame('utm', $resolved['attribution_basis']);
        // The referrer is still recorded — it is evidence, just not the answer.
        $this->assertSame('google.com', $resolved['referrer_domain']);
    }

    public function test_an_advertising_click_id_is_recognised_without_any_utm(): void
    {
        // Ad platforms routinely strip or omit UTMs and leave only their click id. Treating those
        // visits as "direct" is how paid traffic disappears from a report.
        $request = Request::create('/?gclid=abc123', 'GET');
        $resolved = (new AttributionEngine())->resolve($request);

        $this->assertSame('google', $resolved['source']);
        $this->assertSame('cpc', $resolved['medium']);
        $this->assertSame('click_id', $resolved['attribution_basis']);
    }

    public function test_our_own_pages_are_not_a_traffic_source(): void
    {
        // The single most common way a self-hosted analytics table ends up reporting its own
        // domain as its biggest referrer.
        $request = Request::create('http://localhost/products', 'GET');
        $request->headers->set('referer', 'http://localhost/');

        $this->assertNull((new AttributionEngine())->referrerDomain($request));
    }

    public function test_a_search_engine_is_organic_and_a_blog_is_a_referral(): void
    {
        $engine = new AttributionEngine();

        $this->assertSame(['google', 'organic'], $engine->classify('google.co.uk'));
        $this->assertSame(['facebook', 'social'], $engine->classify('m.facebook.com'));
        $this->assertSame(['somebodysblog.net', 'referral'], $engine->classify('somebodysblog.net'));
    }

    // ── Who counts as a visitor ──────────────────────────────────────────────────────────────

    public function test_crawlers_are_identified_including_the_ones_that_do_not_say_bot(): void
    {
        $detector = new BotDetector();

        foreach ([
            'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
            'facebookexternalhit/1.1',
            'python-requests/2.31.0',
            'curl/8.4.0',
            'WhatsApp/2.23',
            'PharmacyMonitoring/1.0 (synthetic check)',
        ] as $agent) {
            $request = Request::create('/', 'GET', [], [], [], ['HTTP_USER_AGENT' => $agent]);
            $this->assertTrue($detector->isBot($request), "{$agent} was not identified as a bot");
        }
    }

    public function test_a_real_browser_is_never_mistaken_for_a_crawler(): void
    {
        // Erring the other way deletes real customers from every report, silently and permanently.
        $detector = new BotDetector();

        foreach ([
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
            'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1',
            'Mozilla/5.0 (Linux; Android 13; SM-A536B) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/119.0.0.0 Mobile Safari/537.36',
        ] as $agent) {
            $request = Request::create('/', 'GET', [], [], [], ['HTTP_USER_AGENT' => $agent, 'HTTP_ACCEPT' => 'text/html']);
            $this->assertFalse($detector->isBot($request), "{$agent} was wrongly treated as a bot");
        }
    }

    // ── Cardinality ──────────────────────────────────────────────────────────────────────────

    public function test_urls_carrying_an_id_collapse_to_one_page(): void
    {
        // Without this, a catalogue of ten thousand products makes "top pages" a list of ten
        // thousand rows with one hit each, and the dimension grows without bound forever.
        $paths = new PathNormalizer();

        $this->assertSame('/product/{slug}', $paths->normalise('/product/panadol-500mg-42a1'));
        $this->assertSame('/product/{slug}', $paths->normalise('/product/aspirin-100mg-9c3f'));
        $this->assertSame('/order/{id}', $paths->normalise('/order/48213'));
        $this->assertSame('/', $paths->normalise('/'));
    }

    public function test_the_panel_and_the_api_are_not_customer_pages(): void
    {
        $paths = new PathNormalizer();

        $this->assertTrue($paths->isIgnored('admin/dashboard'));
        $this->assertTrue($paths->isIgnored('api/v1/config'));
        $this->assertTrue($paths->isIgnored('analytics/collect'));
        $this->assertFalse($paths->isIgnored('product/something'));
    }

    // ── Campaign links ───────────────────────────────────────────────────────────────────────

    public function test_a_campaign_link_cannot_be_pointed_off_this_shop(): void
    {
        // An open redirect on a shop's own domain is a phishing page that arrives carrying the
        // shop's name in the referrer.
        $guard = new CampaignDestination();
        $own = parse_url((string) config('app.url'), PHP_URL_HOST);

        $this->assertTrue($guard->check("https://{$own}/products")['allowed']);

        foreach ([
            'https://evil.net/phish',
            "https://{$own}.evil.net/phish",       // substring match would pass this
            "https://{$own}@evil.net/",            // credentials trick
            'javascript:alert(1)',
            '//evil.net',
            '',
        ] as $url) {
            $this->assertFalse($guard->check($url)['allowed'], "{$url} was allowed as a destination");
        }
    }

    public function test_campaign_tagging_overrides_whatever_was_pasted_into_the_url(): void
    {
        // A stale utm_source left on a copied link would otherwise attribute the new campaign to
        // the old one.
        $guard = new CampaignDestination();

        $tagged = $guard->withUtm('https://shop.test/products?utm_source=old&page=2', [
            'utm_source' => 'whatsapp',
            'utm_medium' => 'social',
        ]);

        $this->assertStringContainsString('utm_source=whatsapp', $tagged);
        $this->assertStringNotContainsString('utm_source=old', $tagged);
        $this->assertStringContainsString('page=2', $tagged, 'existing parameters must survive');
    }

    // ── Permissions ──────────────────────────────────────────────────────────────────────────

    public function test_the_sensitive_capabilities_are_not_granted_by_the_reports_module(): void
    {
        // Holding Reports keeps the read-only view working for roles that already exist. Following
        // an individual, issuing a public link, and changing what is collected are each a decision
        // somebody has to take.
        $implied = (new \ReflectionClass(AnalyticsPermissionService::class))->getConstant('IMPLIED_BY_MODULE');

        $this->assertContains(AnalyticsPermissionService::VIEW, $implied);
        $this->assertNotContains(AnalyticsPermissionService::JOURNEYS, $implied);
        $this->assertNotContains(AnalyticsPermissionService::CAMPAIGNS, $implied);
        $this->assertNotContains(AnalyticsPermissionService::EXPORT, $implied);
        $this->assertNotContains(AnalyticsPermissionService::SETTINGS, $implied);
    }

    public function test_every_capability_is_offered_in_the_role_editor(): void
    {
        // A capability the controller checks but the editor never offers can only be granted by
        // editing the database by hand.
        $editors = file_get_contents(resource_path('views/admin-views/custom-role/create.blade.php'))
            . file_get_contents(resource_path('views/admin-views/custom-role/edit.blade.php'));

        $this->assertSame(2, substr_count($editors, 'AnalyticsPermissionService::all()'));
    }

    public function test_an_unauthenticated_visitor_holds_no_capability(): void
    {
        $permissions = new AnalyticsPermissionService();

        foreach (array_keys(AnalyticsPermissionService::all()) as $capability) {
            $this->assertFalse($permissions->can($capability));
        }
    }

    public function test_a_full_permission_set_still_fits_the_column_that_stores_it(): void
    {
        // The monitoring capabilities already pushed this column past varchar(250) once, and a
        // truncated JSON array decodes to null — which takes ALL of an administrator's permissions
        // away at the moment they were given more.
        $everything = array_merge(
            array_keys(\App\Enums\GlobalConstant::EMPLOYEE_ROLE_MODULE_PERMISSION),
            array_keys(\App\Services\Monitoring\MonitoringPermissionService::all()),
            array_keys(AnalyticsPermissionService::all()),
        );

        if (!\Illuminate\Support\Facades\Schema::hasTable('admin_roles')) {
            $this->markTestSkipped('admin_roles is not present in this test schema.');
        }

        $type = collect(\Illuminate\Support\Facades\DB::select('SHOW COLUMNS FROM admin_roles LIKE ?', ['module_access']))
            ->first()?->Type ?? '';

        $this->assertMatchesRegularExpression(
            '/^(text|mediumtext|longtext)$/i',
            strtolower($type),
            'admin_roles.module_access is ' . $type . ', which cannot hold ' . strlen(json_encode($everything)) . ' characters',
        );
    }

    // ── Windows ──────────────────────────────────────────────────────────────────────────────

    public function test_a_window_compares_against_the_same_length_immediately_before_it(): void
    {
        // Comparing seven days against "last month" would make every Monday look like a collapse.
        $window = Window::make('7d');

        $this->assertSame(7, $window->days);
        $this->assertCount(7, $window->dates());
        $this->assertSame(
            $window->from->copy()->subDay()->toDateString(),
            $window->previousToDate(),
            'the comparison period must end the day before this one starts',
        );
    }

    public function test_a_reversed_custom_range_is_corrected_rather_than_returning_nothing(): void
    {
        $window = Window::between('2026-08-22', '2026-08-01');

        $this->assertSame('2026-08-01', $window->fromDate());
        $this->assertSame('2026-08-22', $window->toDate());
    }

    // ── Collection health ────────────────────────────────────────────────────────────────────

    public function test_the_reader_names_why_a_section_is_empty(): void
    {
        // "No data" is not an answer. Four different situations produce an empty screen and only
        // one of them means the shop had a quiet week.
        $health = app(AnalyticsReporting::class)->collectionHealth();

        $this->assertContains($health['state'], [
            'healthy', 'not_installed', 'disabled', 'no_events', 'rollup_never_ran', 'rollup_stale',
        ]);

        if ($health['state'] !== 'healthy') {
            $this->assertNotEmpty($health['message'], 'an unhealthy state must say what to do about it');
        }
    }
}
