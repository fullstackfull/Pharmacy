<?php

namespace Tests\Feature;

use App\Services\Theme\SectionRegistry;
use Tests\TestCase;

/**
 * Every option the Theme Builder offers must actually do something.
 *
 * A schema key with no reader is a switch that silently does nothing — the merchant flips it, the
 * storefront ignores it, and the theme looks broken. These tests scan the storefront renderers and
 * the theme services for each declared key, so a new option cannot ship without a renderer.
 */
class ThemeOptionCoverageTest extends TestCase
{
    /** Keys read through a loop variable rather than a literal index, checked by hand. */
    private const READ_INDIRECTLY = ['facebook', 'instagram', 'tiktok', 'youtube', 'x'];

    private string $haystack;

    protected function setUp(): void
    {
        parent::setUp();

        $sources = array_merge(
            glob(resource_path('views/theme-sections/*.blade.php')),
            // The storefront renderer is a directory now: one partial per section type, plus the
            // shared pieces they include. A scan that still read only home.blade.php would pass by
            // finding nothing to check.
            glob(resource_path('views/theme-sections/types/*.blade.php')),
            glob(resource_path('views/theme-sections/partials/*.blade.php')),
            glob(app_path('Services/Theme/*.php')),
        );

        $this->haystack = implode("\n", array_map(fn ($file) => file_get_contents($file), $sources));
    }

    private function isRead(string $key): bool
    {
        return in_array($key, self::READ_INDIRECTLY, true)
            || str_contains($this->haystack, "['" . $key . "']")
            || str_contains($this->haystack, '$' . $key);
    }

    public function test_every_section_option_is_read_by_a_renderer(): void
    {
        $registry = new SectionRegistry();

        foreach ($registry->types() as $type => $definition) {
            foreach (array_keys($definition['schema']) as $key) {
                $this->assertTrue($this->isRead($key), "Section option {$type}.{$key} is declared but never read.");
            }
        }
    }

    public function test_every_block_option_is_read_by_a_renderer(): void
    {
        $registry = new SectionRegistry();

        foreach ($registry->blockTypes() as $type => $definition) {
            foreach (array_keys($definition['schema']) as $key) {
                $this->assertTrue($this->isRead($key), "Block option {$type}.{$key} is declared but never read.");
            }
        }
    }

    public function test_every_shared_option_is_read_by_a_renderer(): void
    {
        foreach (array_keys((new SectionRegistry())->commonSchema()) as $key) {
            $this->assertTrue($this->isRead($key), "Shared option {$key} is declared but never read.");
        }
    }

    public function test_every_section_type_offers_a_picker_preview(): void
    {
        foreach ((new SectionRegistry())->types() as $type => $definition) {
            $this->assertArrayHasKey('preview', $definition, "Section {$type} has no picker preview shape.");
            $this->assertArrayHasKey('hint', $definition, "Section {$type} has no picker hint.");
        }
    }

    public function test_every_home_section_type_has_a_renderer(): void
    {
        foreach ((new SectionRegistry())->types() as $type => $definition) {
            if (!in_array('home', $definition['pages'], true)) {
                continue;
            }
            $this->assertFileExists(
                resource_path('views/theme-sections/types/' . $type . '.blade.php'),
                "Section {$type} can be added to the home page but the renderer has no partial for it.",
            );
        }
    }

    public function test_every_picker_preview_shape_is_drawn(): void
    {
        $builder = file_get_contents(resource_path('views/admin-views/theme/builder.blade.php'));

        foreach ((new SectionRegistry())->types() as $type => $definition) {
            $this->assertStringContainsString(
                '[data-shape="' . $definition['preview'] . '"]',
                $builder,
                "Section {$type} previews as \"{$definition['preview']}\", a shape the picker never draws.",
            );
        }
    }

    public function test_breakpoint_css_is_emitted_only_for_overrides_that_exist(): void
    {
        $this->assertSame('', theme_section_breakpoint_css(settings: ['padding_top' => 40], selector: '#s1'));

        $css = theme_section_breakpoint_css(
            settings: ['padding_top' => 40, 'padding_top_mobile' => 8, 'columns_tablet' => 3, 'visible_mobile' => false],
            selector: '#s1',
        );

        $this->assertStringContainsString('@media (max-width:991.98px){#s1{--tb-cols:3;', $css);
        $this->assertStringContainsString('padding-top:8px;', $css);
        $this->assertStringContainsString('display:none;', $css);
    }

    public function test_breakpoint_css_coerces_values_it_writes(): void
    {
        $css = theme_section_breakpoint_css(
            settings: ['padding_top_mobile' => '12px;} body{display:none', 'columns_mobile' => -4],
            selector: '#s1',
        );

        $this->assertStringNotContainsString('body', $css);
        $this->assertStringContainsString('padding-top:12px;', $css);
        $this->assertStringContainsString('--tb-cols:1;', $css);
    }
}
