<?php

namespace Tests\Feature;

use App\Services\Theme\SectionReadiness;
use App\Services\Theme\SectionRegistry;
use Tests\TestCase;

/**
 * A section that will show nothing must say so in the builder — and say the same thing the
 * storefront does.
 *
 * The storefront is right to skip a section with nothing in it: a coupon strip with no live coupon
 * opens a padded band with nothing inside, which reads as a broken page. But the builder showed
 * those sections as ordinary rows, so eight of the thirty-three could be added, be visible, and
 * never appear, with no explanation anywhere. A merchant then concludes the theme is broken, or —
 * worse — that the section is on the page.
 *
 * The risk in fixing it is drift: two copies of "will this render", one in a blade and one behind a
 * badge, disagreeing after the next change. So there is one object, and these hold it to that.
 */
class SectionReadinessTest extends TestCase
{
    public function test_the_badge_and_the_storefront_answer_from_the_same_object(): void
    {
        // Proof that the blade actually calls the shared rule rather than keeping its own copy.
        $view = file_get_contents(resource_path('views/theme-sections/home.blade.php'));

        $this->assertStringContainsString('SectionReadiness', $view);
        $this->assertStringContainsString('willRender(', $view);

        // The hand-written skip conditions are gone; one call replaced them.
        $this->assertStringNotContainsString("@continue(\$type === 'coupon_strip'", $view);
        $this->assertStringNotContainsString("@continue(\$type === 'bundle'", $view);
    }

    public function test_a_section_that_needs_a_choice_says_which_choice(): void
    {
        $readiness = app(SectionReadiness::class);

        foreach (['category_showcase', 'vendor_showcase', 'bundle'] as $type) {
            $verdict = $readiness->verdict($type, [], []);

            $this->assertSame(SectionReadiness::NEEDS_CHOICE, $verdict['state'], $type);
            $this->assertNotNull($verdict['reason_key'], "{$type} must say what is missing");
        }
    }

    public function test_a_block_section_with_no_cards_is_not_confused_with_one_whose_cards_are_empty(): void
    {
        // Two different jobs for the merchant: add a card, or fill in the one that is there.
        $readiness = app(SectionReadiness::class);

        $none = $readiness->verdict('stories', [], []);
        $empty = $readiness->verdict('stories', [], [['settings' => ['title' => 'A story with no image']]]);

        $this->assertSame(SectionReadiness::NEEDS_CHOICE, $none['state']);
        $this->assertSame(SectionReadiness::NEEDS_CHOICE, $empty['state']);
        $this->assertNotSame($none['reason_key'], $empty['reason_key'], 'the two need different instructions');
    }

    public function test_a_section_with_nothing_to_choose_is_always_ready(): void
    {
        // A spacer has no data source and cannot fail to render; flagging it would train the
        // merchant to ignore the flag.
        foreach (['spacer', 'custom_html', 'newsletter', 'faq', 'usp_strip', 'announcement_bar'] as $type) {
            $this->assertSame(
                SectionReadiness::READY,
                app(SectionReadiness::class)->verdict($type, [], [])['state'],
                $type,
            );
        }
    }

    public function test_every_declared_section_type_gets_a_verdict_without_throwing(): void
    {
        // The registry is where sections are added, and a new one must not be able to break the
        // builder's structure panel by having no branch here.
        $readiness = app(SectionReadiness::class);

        foreach (array_keys(app(SectionRegistry::class)->types()) as $type) {
            $verdict = $readiness->verdict($type, [], []);

            $this->assertContains($verdict['state'], [
                SectionReadiness::READY,
                SectionReadiness::NEEDS_CHOICE,
                SectionReadiness::NO_CONTENT,
                SectionReadiness::NOT_NOW,
            ], $type);
        }
    }

    public function test_the_new_sections_never_open_an_empty_band(): void
    {
        // Each of these draws on something the shop may not have yet — a brand pick, a measured
        // search, this visitor's own history, a configured app listing. None of them may render a
        // padded band with nothing in it, which is what an empty section looks like on the page.
        $readiness = app(SectionReadiness::class);

        $this->assertFalse($readiness->willRender('brand_showcase', [], [], ['brandShowcase' => null]));
        $this->assertTrue($readiness->willRender('brand_showcase', [], [], ['brandShowcase' => ['brand' => 1]]));

        $this->assertFalse($readiness->willRender('trending_searches', [], [], ['searchTerms' => []]));
        $this->assertTrue($readiness->willRender('trending_searches', [], [], ['searchTerms' => [['term' => 'x']]]));

        $this->assertFalse($readiness->willRender('recently_viewed', [], [], ['seenProducts' => []]));
        $this->assertTrue($readiness->willRender('recently_viewed', [], [], ['seenProducts' => [1]]));

        $this->assertFalse($readiness->willRender('app_download', [], [], ['appStores' => []]));
        $this->assertTrue($readiness->willRender('app_download', [], [], ['appStores' => ['android' => 'https://play.google.com/x']]));

        // Tabs and price bands are nothing but their blocks.
        $this->assertFalse($readiness->willRender('product_tabs', [], [], []));
        $this->assertFalse($readiness->willRender('price_tiles', [], [], []));
    }

    public function test_a_visitors_own_history_is_the_only_history_it_reads(): void
    {
        // recently_viewed shows a person their own products. Reading anyone else's would be both
        // wrong and a privacy failure, so the query is scoped to the visitor's own cookie and a
        // visitor without one has no history rather than somebody else's.
        $resolver = file_get_contents(app_path('Services/Theme/SectionDataResolver.php'));

        $this->assertStringContainsString('VISITOR_COOKIE', $resolver);
        $this->assertStringContainsString("where('visitor_id', \$visitorId)", $resolver);
    }

    public function test_the_storefront_rule_reads_what_the_view_already_resolved(): void
    {
        // willRender must not query: it is called inside the loop that renders a customer's page,
        // and the view has already fetched everything it needs.
        $readiness = app(SectionReadiness::class);

        $this->assertFalse($readiness->willRender('coupon_strip', [], [], ['coupons' => []]));
        $this->assertTrue($readiness->willRender('coupon_strip', [], [], ['coupons' => [['code' => 'X']]]));
        $this->assertFalse($readiness->willRender('shipping_cutoff', [], [], ['secondsLeft' => null]));
        $this->assertTrue($readiness->willRender('shipping_cutoff', [], [], ['secondsLeft' => 900]));
        $this->assertTrue($readiness->willRender('spacer', [], [], []));
    }
}
