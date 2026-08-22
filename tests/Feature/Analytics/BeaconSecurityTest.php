<?php

namespace Tests\Feature\Analytics;

use App\Services\Analytics\AnalyticsEvent;
use Tests\TestCase;

/**
 * The beacon is a public, unauthenticated, CSRF-exempt POST on a live shop. What keeps that safe
 * is not one control but the narrowness of what it will accept, so these guard the narrowness.
 */
class BeaconSecurityTest extends TestCase
{
    public function test_the_client_may_not_report_anything_involving_money(): void
    {
        // The rule that matters most: a page able to post an order with a value of its choosing
        // would make every revenue figure in the system a suggestion.
        foreach ([
            AnalyticsEvent::ORDER_PLACED,
            AnalyticsEvent::PAYMENT_SUCCEEDED,
            AnalyticsEvent::PAYMENT_FAILED,
            AnalyticsEvent::CHECKOUT_PAYMENT,
            AnalyticsEvent::COUPON_APPLIED,
            AnalyticsEvent::SIGNED_UP,
            AnalyticsEvent::CART_ADDED,
        ] as $name) {
            $this->assertFalse(
                AnalyticsEvent::isClientAllowed($name),
                "{$name} must never be accepted from a browser",
            );
        }
    }

    public function test_the_client_may_not_report_anything_the_server_already_records(): void
    {
        // Accepting these would double count, and deduplication would NOT catch it: the server
        // stores a normalised route pattern and the browser sends its actual URL, so the two hash
        // to different keys.
        foreach ([
            AnalyticsEvent::PRODUCT_VIEWED,
            AnalyticsEvent::CATEGORY_VIEWED,
            AnalyticsEvent::SHOP_VIEWED,
            AnalyticsEvent::BRAND_VIEWED,
            AnalyticsEvent::WISHLIST_ADDED,
            AnalyticsEvent::CHECKOUT_STARTED,
            AnalyticsEvent::PAGE_VIEWED,
        ] as $name) {
            $this->assertFalse(
                AnalyticsEvent::isClientAllowed($name),
                "{$name} is recorded server-side, so accepting it from a browser double counts it",
            );
        }
    }

    public function test_the_client_may_report_exactly_what_the_server_cannot_see(): void
    {
        // The product filter navigates with pushState and returns JSON, so those navigations are
        // correctly not pageviews and are lost without the beacon.
        $this->assertTrue(AnalyticsEvent::isClientAllowed(AnalyticsEvent::PRODUCT_LIST_VIEWED));
        $this->assertTrue(AnalyticsEvent::isClientAllowed(AnalyticsEvent::CART_VIEWED));
        $this->assertTrue(AnalyticsEvent::isClientAllowed(AnalyticsEvent::COMPARE_ADDED));
    }

    public function test_the_javascript_allow_list_matches_the_server_one(): void
    {
        // A browser-side list that drifts from the server's produces events that are sent on every
        // page load and silently discarded — invisible until somebody notices a missing report.
        $script = file_get_contents(public_path('assets/front-end/js/analytics-beacon.js'));

        preg_match('/var ALLOWED = \[(.*?)\];/s', $script, $matches);
        $this->assertNotEmpty($matches, 'the beacon script has no ALLOWED list');

        preg_match_all("/'([a-z_]+)'/", $matches[1], $names);
        $inScript = $names[1];
        sort($inScript);

        $onServer = array_values(array_filter(
            AnalyticsEvent::names(),
            static fn (string $name) => AnalyticsEvent::isClientAllowed($name),
        ));
        sort($onServer);

        $this->assertSame($onServer, $inScript);
    }

    public function test_the_endpoint_is_exempt_from_csrf_because_sendbeacon_cannot_sign(): void
    {
        // Documented as a deliberate decision rather than an oversight: the protection is the
        // Origin check and the allow-list, not a token the browser is unable to attach.
        $except = (new \ReflectionClass(\App\Http\Middleware\VerifyCsrfToken::class))
            ->newInstanceWithoutConstructor();
        $property = (new \ReflectionClass($except))->getProperty('except');

        $this->assertContains('analytics/collect', $property->getValue($except));
    }

    public function test_it_answers_the_same_way_to_everything(): void
    {
        // 204 regardless, so a prober cannot learn which event names exist by watching responses.
        foreach ([
            ['events' => [['name' => 'cart_viewed']]],
            ['events' => [['name' => 'order_placed', 'value' => 999999]]],
            ['events' => 'not-an-array'],
            [],
        ] as $payload) {
            $this->postJson('/analytics/collect', $payload)->assertNoContent();
        }
    }
}
