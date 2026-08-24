<?php

namespace Tests\Feature;

use App\Services\Theme\Channel;
use App\Services\Theme\ComponentCapabilityRegistry;
use App\Services\Theme\SectionRegistry;
use Tests\TestCase;

/**
 * The library, as something a builder can organise rather than a flat list of 39.
 *
 * The metadata here is DERIVED — a type's variants come from the select the storefront actually
 * branches on, its channels from the capability registry, its family from one map. That is the
 * point: a hand-written variant list beside a schema is a list that will one day disagree with the
 * field it describes, and the disagreement will be invisible until a merchant picks a layout that
 * does nothing.
 */
class SectionCatalogueTest extends TestCase
{
    private SectionRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = app(SectionRegistry::class);
    }

    public function test_every_type_lands_in_a_family(): void
    {
        $categories = array_keys(SectionRegistry::CATEGORIES);

        foreach ($this->registry->types() as $key => $definition) {
            $this->assertArrayHasKey('category', $definition, "{$key} has no family");
            $this->assertContains($definition['category'], $categories,
                "{$key} claims a family the picker does not show: {$definition['category']}");
        }
    }

    public function test_the_catalogue_groups_the_whole_library_and_nothing_else(): void
    {
        $grouped = $this->registry->catalogue();

        $counted = array_sum(array_map(fn (array $group) => count($group['types']), $grouped));
        $this->assertSame(count($this->registry->types()), $counted, 'grouping must not lose or invent a type');

        // Group order is the order a page is built in, not alphabetical.
        $this->assertSame('hero', array_key_first($grouped));
    }

    public function test_a_variant_list_is_the_select_that_drives_it(): void
    {
        // Read from the definition rather than asserted by hand: if somebody edits the options,
        // this test follows them — what it pins is that the two never drift apart.
        foreach ($this->registry->types() as $key => $definition) {
            $variantKey = $definition['variant_key'];

            if ($variantKey === null) {
                $this->assertSame([], $definition['variants'], "{$key} has no variant field but lists variants");
                continue;
            }

            $this->assertSame(
                $definition['schema'][$variantKey]['options'],
                $definition['variants'],
                "{$key}: the variant list and the {$variantKey} select disagree"
            );
        }
    }

    public function test_types_that_offer_layouts_are_found_by_the_same_question(): void
    {
        // Most say `style`; the dashboard-banner section says `layout`. One question, either word.
        $this->assertSame('style', $this->registry->variantKeyFor('product_slider'));
        $this->assertSame('layout', $this->registry->variantKeyFor('store_banner'));
        $this->assertNull($this->registry->variantKeyFor('spacer'));

        $this->assertContains('carousel', $this->registry->variantsFor('store_banner'));
        $this->assertContains('spotlight', $this->registry->variantsFor('product_slider'));
        $this->assertSame([], $this->registry->variantsFor('spacer'));
    }

    public function test_each_type_declares_the_surfaces_that_can_draw_it(): void
    {
        $capabilities = app(ComponentCapabilityRegistry::class);

        foreach ($this->registry->types() as $key => $definition) {
            $this->assertContains(Channel::WEB, $definition['channels'], "{$key} must at least render on the web");

            $this->assertSame(
                $capabilities->isAppSafe($key),
                in_array(Channel::CUSTOMER_APP, $definition['channels'], true),
                "{$key}: the catalogue and the capability registry disagree about the app"
            );
        }
    }

    public function test_every_type_carries_a_contract_version(): void
    {
        foreach ($this->registry->types() as $key => $definition) {
            $this->assertIsInt($definition['version'], "{$key} has no version");
            $this->assertGreaterThanOrEqual(1, $definition['version']);
        }
    }

    public function test_an_unaliased_type_resolves_to_itself(): void
    {
        $resolved = $this->registry->resolveAlias('hero_banner');

        $this->assertSame('hero_banner', $resolved['type']);
        $this->assertNull($resolved['variant'], 'nothing is aliased yet — the mechanism ships before its first use');
    }

    public function test_every_alias_points_at_a_real_type_and_a_real_variant(): void
    {
        // Guards the change this mechanism exists for: the day the six banner types collapse into
        // one, an alias that names a variant the target does not offer would silently render the
        // default layout instead of the one the merchant chose.
        $aliases = $this->registry->aliases();

        // Empty today. Asserted rather than skipped so this test is a live guard the moment the
        // first alias is added, not a loop that quietly never runs.
        $this->assertIsArray($aliases);

        foreach ($aliases as $alias => $target) {
            $this->assertTrue($this->registry->has($target['type']),
                "alias {$alias} points at unknown type {$target['type']}");
            $this->assertContains($target['variant'], $this->registry->variantsFor($target['type']),
                "alias {$alias} names a variant {$target['type']} does not offer");
            $this->assertArrayNotHasKey($alias, $this->registry->types(),
                "{$alias} is both an alias and a live type — one of them is wrong");
        }
    }

    public function test_the_decoration_does_not_disturb_what_was_already_there(): void
    {
        // Everything the builder and the storefront already read must survive being decorated.
        $hero = $this->registry->types()['hero_banner'];

        $this->assertSame('hero_banner', $hero['label']);
        $this->assertSame(['home'], $hero['pages']);
        $this->assertSame(['slide'], $hero['blocks']);
        $this->assertArrayHasKey('autoplay', $hero['schema']);
        $this->assertSame('hero', $hero['preview']);
    }

    public function test_every_setting_lands_in_a_drawer_that_describes_it(): void
    {
        // The inspector showed one column of twenty fields per section. Grouping them is only
        // useful if the grouping is right, and it is derived from the key rather than written into
        // each of thirty-nine definitions — which is what keeps it from disagreeing with the shared
        // vocabulary the whole registry is built on.
        $registry = app(SectionRegistry::class);
        $stray = [];

        foreach (array_keys($registry->types()) as $type) {
            $groups = $registry->fieldGroupsFor($type);

            // Every key the type declares appears exactly once, in exactly one drawer.
            $grouped = array_merge(...array_values($groups ?: [[]]));
            $declared = array_keys($registry->ownSchemaFor($type));

            sort($grouped);
            sort($declared);
            $this->assertSame($declared, $grouped, $type);

            // And the content drawer holds only things a merchant types — anything else landing
            // there is a key nobody classified.
            foreach ($groups['content'] ?? [] as $key) {
                if (!in_array($key, ['title', 'subtitle', 'eyebrow', 'text', 'content', 'image', 'products', 'stats'], true)) {
                    $stray[] = $type . '.' . $key;
                }
            }
        }

        $this->assertSame([], $stray, 'these settings fell into "content" because nothing classified them');
    }

    public function test_a_section_with_one_kind_of_setting_is_not_given_headings(): void
    {
        // A spacer has a height and nothing else. Labelling that single field "arrangement" is
        // ceremony, not organisation.
        $groups = app(SectionRegistry::class)->fieldGroupsFor('spacer');

        $this->assertCount(1, $groups);
    }
}
