<?php

namespace Tests\Feature\Analytics;

use App\Services\Analytics\Support\ShareLink;
use Tests\TestCase;

/**
 * Links the shop hands out have to say where they came from.
 *
 * The campaign builder tags anything an administrator creates. Everything the SHOP itself hands
 * out — a customer's referral link, a product's share buttons, the tracking button in an order
 * email — was untagged, so all of it came back as (direct)/(none): the referral programme looked
 * like it produced nothing, and a share sent through a messaging app, which carries no referrer,
 * looked like somebody typing the address by hand.
 */
class ShareLinkTest extends TestCase
{
    private function link(): ShareLink
    {
        return app(ShareLink::class);
    }

    public function test_a_referral_link_keeps_its_referral_code(): void
    {
        $tagged = $this->link()->forReferral('https://shop.test/?referral_code=ABC123');

        parse_str((string) parse_url($tagged, PHP_URL_QUERY), $query);

        $this->assertSame('ABC123', $query['referral_code'], 'the link still has to work');
        $this->assertSame('referral', $query['utm_source']);
        $this->assertSame('customer_referral', $query['utm_medium']);
        $this->assertSame('refer_a_friend', $query['utm_campaign']);
    }

    public function test_each_social_network_is_told_apart(): void
    {
        foreach (['facebook', 'whatsapp', 'linkedin'] as $network) {
            $tagged = $this->link()->forSocialShare('https://shop.test/product/vitamin-c', $network);

            parse_str((string) parse_url($tagged, PHP_URL_QUERY), $query);

            $this->assertSame($network, $query['utm_source']);
            $this->assertSame('social', $query['utm_medium']);
        }
    }

    public function test_a_transactional_email_is_not_direct_and_not_marketing(): void
    {
        $tagged = $this->link()->forTransactionalEmail(
            'https://shop.test/track-order/result?order_id=42',
            'order_confirmation',
        );

        parse_str((string) parse_url($tagged, PHP_URL_QUERY), $query);

        $this->assertSame('42', $query['order_id']);
        $this->assertSame('email', $query['utm_source']);
        $this->assertSame('transactional', $query['utm_medium']);
        $this->assertSame('order_confirmation', $query['utm_campaign']);
    }

    public function test_the_browser_helper_and_this_class_agree_on_what_a_share_is(): void
    {
        // One handler tags every share button in the storefront. If the two drift, a share is
        // recorded under two different mediums depending on which path produced the link.
        $javascript = file_get_contents(public_path('assets/front-end/js/custom.js'));

        $this->assertStringContainsString('"utm_medium", "social"', $javascript);
        $this->assertStringContainsString('parsed.searchParams.has("utm_source")', $javascript);
    }
}
