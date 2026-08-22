<?php

namespace Tests\Feature\DeepLink;

use App\Services\DeepLink\AppLinkService;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * The store link has to carry the campaign, and it has to refuse to touch anything else.
 *
 * The first half is the feature: an install that followed an Instagram campaign should be
 * attributable to it. The second half is the part that would be a bug in production — an
 * administrator can put any URL in the store field, and silently rewriting the query of a URL we do
 * not recognise would at best do nothing and at worst break the link.
 */
class AppLinkServiceTest extends TestCase
{
    private function service(array $settings): AppLinkService
    {
        $service = new class extends AppLinkService {
            /** @var array<string, mixed> */
            public array $stub = [];

            public function settings(): array
            {
                return $this->stub;
            }
        };

        $service->stub = $settings;

        return $service;
    }

    private function configured(): AppLinkService
    {
        return $this->service([
            'android_package_name' => 'com.shop.app',
            'android_sha256_fingerprint' => 'AA:BB',
            'playstore_redirect_url' => 'https://play.google.com/store/apps/details?id=com.shop.app',
            'ios_bundle_id' => 'com.shop.app',
            'ios_team_id' => 'TEAM123',
            'app_store_redirect_url' => 'https://apps.apple.com/app/id1234567890',
        ]);
    }

    public function test_the_play_store_link_carries_the_campaign_as_an_install_referrer(): void
    {
        $url = $this->configured()->storeUrl(AppLinkService::PLATFORM_ANDROID, [
            'utm_source' => 'instagram',
            'utm_medium' => 'social',
            'utm_campaign' => 'ramadan',
            'campaign_code' => 'RMD26',
        ]);

        $this->assertStringContainsString('id=com.shop.app', $url);

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertArrayHasKey('referrer', $query);

        parse_str($query['referrer'], $referrer);
        $this->assertSame('instagram', $referrer['utm_source']);
        $this->assertSame('RMD26', $referrer['campaign_code']);
    }

    public function test_the_app_store_link_carries_the_campaign_token(): void
    {
        $url = $this->configured()->storeUrl(AppLinkService::PLATFORM_IOS, [
            'utm_campaign' => 'ramadan',
            'campaign_code' => 'RMD26',
        ]);

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('RMD26', $query['ct']);
    }

    public function test_a_store_url_we_do_not_recognise_is_returned_untouched(): void
    {
        $service = $this->service([
            'android_package_name' => 'com.shop.app',
            'playstore_redirect_url' => 'https://links.example.net/get-the-app',
        ]);

        $this->assertSame(
            'https://links.example.net/get-the-app',
            $service->storeUrl(AppLinkService::PLATFORM_ANDROID, ['utm_source' => 'instagram'])
        );
    }

    public function test_without_attribution_the_store_url_is_exactly_what_the_administrator_entered(): void
    {
        $entered = 'https://play.google.com/store/apps/details?id=com.shop.app';

        $this->assertSame($entered, $this->configured()->storeUrl(AppLinkService::PLATFORM_ANDROID));
    }

    public function test_attribution_cannot_break_out_of_the_referrer_string(): void
    {
        $url = $this->configured()->storeUrl(AppLinkService::PLATFORM_ANDROID, [
            'utm_source' => "insta&id=com.attacker.app\n",
        ]);

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        parse_str($query['referrer'], $referrer);

        $this->assertSame('com.shop.app', $query['id']);
        $this->assertArrayNotHasKey('id', $referrer);
        $this->assertStringNotContainsString('&', $referrer['utm_source']);
    }

    public function test_an_unconfigured_platform_has_no_store_url(): void
    {
        $service = $this->service([]);

        $this->assertFalse($service->isConfigured(AppLinkService::PLATFORM_ANDROID));
        $this->assertFalse($service->isConfiguredForAnyPlatform());
        $this->assertNull($service->storeUrl(AppLinkService::PLATFORM_ANDROID, ['utm_source' => 'x']));
    }

    public function test_campaign_short_links_are_published_as_app_links(): void
    {
        $service = $this->configured();

        $this->assertTrue($service->opensTheApp('/go/RMD26'));
        $this->assertTrue($service->opensTheApp('/go/RMD26', AppLinkService::PLATFORM_ANDROID));
        $this->assertTrue($service->opensTheApp('/product/some-slug'));
        $this->assertFalse($service->opensTheApp('/checkout'));
    }

    public function test_the_campaign_cookie_is_preferred_over_the_query_string(): void
    {
        $request = Request::create('/product/x?utm_source=google', 'GET');
        $request->cookies->set(\App\Services\Analytics\VisitorContext::CAMPAIGN_COOKIE, 'RMD26');

        $attribution = $this->configured()->attributionFromRequest($request);

        $this->assertSame('google', $attribution['utm_source']);
        $this->assertSame('RMD26', $attribution['campaign_code']);
    }
}
