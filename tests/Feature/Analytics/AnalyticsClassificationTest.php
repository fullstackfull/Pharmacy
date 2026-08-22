<?php

namespace Tests\Feature\Analytics;

use App\Services\Analytics\Support\AttributionEngine;
use App\Services\Analytics\Support\BotDetector;
use App\Services\Analytics\Support\PathNormalizer;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Who a visit was, and where it came from.
 *
 * Every case here was a real misclassification found by auditing: traffic put in the wrong bucket
 * produces a report that looks perfectly reasonable and sends a merchant the wrong way.
 */
class AnalyticsClassificationTest extends TestCase
{
    // ── Who ──────────────────────────────────────────────────────────────────────────────────

    /**
     * @return array<string, array{0: string, 1: array<string, string>, 2: bool}>
     */
    public static function clients(): array
    {
        return [
            // okhttp is the default user agent of an Android app built on Retrofit — this shop's
            // own included — so treating it as a crawler deleted the store's app customers from
            // every report while Monitoring counted the same requests as the Android app.
            'the shop android app' => ['okhttp/4.12.0', ['X-Platform' => 'android'], false],
            'the shop ios app' => ['', ['X-Platform' => 'ios'], false],
            'an app that sends only its version' => ['okhttp/4.12.0', ['X-App-Version' => '3.1.0'], false],
            'an unidentified http client' => ['okhttp/4.12.0', [], true],
            'a python scraper' => ['python-requests/2.31', [], true],
            // A declared crawler stays a crawler however it dresses up.
            'a crawler claiming to be the app' => ['Googlebot/2.1', ['X-Platform' => 'android'], true],
        ];
    }

    /**
     * @param  array<string, string>  $headers
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('clients')]
    public function test_who_counts_as_a_person(string $agent, array $headers, bool $isBot): void
    {
        $request = Request::create('/api/v1/products', 'GET');
        $agent === '' ? $request->headers->remove('User-Agent') : $request->headers->set('User-Agent', $agent);

        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }

        $this->assertSame($isBot, (new BotDetector())->isBot($request));
    }

    public function test_a_native_app_is_a_phone_not_a_desktop(): void
    {
        $request = Request::create('/api/v1/products', 'GET');
        $request->headers->set('User-Agent', 'okhttp/4.12.0');
        $request->headers->set('X-Platform', 'android');

        $this->assertSame('mobile', (new BotDetector())->device($request));
    }

    public function test_a_browser_that_accepts_nothing_is_still_a_crawler_on_the_web(): void
    {
        $request = Request::create('/', 'GET');
        $request->headers->set('User-Agent', 'Mozilla/5.0');
        $request->headers->set('Accept', '');

        $this->assertTrue((new BotDetector())->isBot($request));
    }

    public function test_a_staff_login_is_not_a_customer_pageview(): void
    {
        // The admin and vendor sign-in page is registered outside the /admin prefix, so it used to
        // land in the shop's top-pages table as if a customer had browsed it.
        $paths = app(PathNormalizer::class);

        $this->assertTrue($paths->isIgnored('login/employee'));
        $this->assertTrue($paths->isIgnored('/login/{loginUrl}'));
        $this->assertFalse($paths->isIgnored('product/vitamin-c'));
    }

    // ── Where from ───────────────────────────────────────────────────────────────────────────

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function referrers(): array
    {
        return [
            // mail.google.com contains "google", and the search table used to be consulted first.
            'gmail is email, not organic search' => ['mail.google.com', 'mail.google.com', 'email'],
            'outlook is email' => ['outlook.live.com', 'outlook.live.com', 'email'],
            'any webmail host is email' => ['webmail.acme.tv', 'webmail.acme.tv', 'email'],
            'a google search is organic' => ['www.google.com', 'google', 'organic'],
            'so is a national google' => ['www.google.co.uk', 'google', 'organic'],
            // str_contains credited these to a search engine they never touched.
            'a shop that merely contains the word' => ['notgoogle.example.com', 'notgoogle.example.com', 'referral'],
            'and one that contains another' => ['mybrave.shop', 'mybrave.shop', 'referral'],
            'instagram is social' => ['l.instagram.com', 'instagram', 'social'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('referrers')]
    public function test_a_referrer_is_classified_by_its_labels(string $host, string $source, string $medium): void
    {
        $this->assertSame([$source, $medium], (new AttributionEngine())->classify($host));
    }

    public function test_a_facebook_click_id_alone_is_not_a_paid_click(): void
    {
        // Facebook appends fbclid to every outbound link, organic posts included, so reporting it
        // as cpc made paid traffic look larger than the ad account it supposedly came from.
        $resolved = (new AttributionEngine())->resolve(Request::create('/?fbclid=abc123', 'GET'));

        $this->assertSame('facebook', $resolved['source']);
        $this->assertSame('social', $resolved['medium']);
    }

    public function test_an_advertising_click_id_still_reads_as_paid(): void
    {
        $resolved = (new AttributionEngine())->resolve(Request::create('/?gclid=abc123', 'GET'));

        $this->assertSame('cpc', $resolved['medium']);
    }

    public function test_a_campaign_tagged_without_a_source_is_not_thrown_away(): void
    {
        // Most mail tools tag a medium and a campaign and leave the source out. That link used to
        // be recorded as direct with the campaign discarded.
        $resolved = (new AttributionEngine())->resolve(
            Request::create('/?utm_medium=email&utm_campaign=ramadan_2026', 'GET')
        );

        $this->assertSame('utm', $resolved['attribution_basis']);
        $this->assertSame('ramadan_2026', $resolved['campaign']);
        $this->assertSame('email', $resolved['medium']);
    }
}
