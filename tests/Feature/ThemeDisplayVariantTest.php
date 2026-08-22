<?php

namespace Tests\Feature;

use App\Services\Theme\SectionRegistry;
use Tests\TestCase;

/**
 * A "display style" the storefront ignores is the worst kind of option: the merchant picks it, the
 * page does not change, and nothing anywhere says why.
 *
 * The existing coverage test only asks whether the KEY is read somewhere — and `style` is read by
 * eight sections already, so adding a ninth that ignores it passes. This asks the harder question:
 * every VALUE a section offers must appear in the renderer that draws that section.
 */
class ThemeDisplayVariantTest extends TestCase
{
    /** Options whose values are written into a class or attribute rather than compared. */
    private const RENDERED_AS_A_CLASS = ['width', 'alignment', 'ratio'];

    public function test_every_display_option_value_is_drawn_by_a_renderer(): void
    {
        $unimplemented = [];

        foreach (app(SectionRegistry::class)->types() as $type => $definition) {
            foreach ($definition['schema'] ?? [] as $key => $field) {
                if (($field['type'] ?? null) !== 'select' || !in_array($key, ['style', 'layout'], true)) {
                    continue;
                }

                foreach ($field['options'] ?? [] as $option) {
                    if (in_array($key, self::RENDERED_AS_A_CLASS, true)) {
                        continue;
                    }

                    // The default needs no branch of its own — it is what the else renders.
                    if ($option === ($field['default'] ?? null)) {
                        continue;
                    }

                    // Searched in THIS section's own renderer, not the whole file: 'grid' appears
                    // in a dozen places, and a global match would pass a section that ignores it.
                    // A variant may also be drawn entirely in CSS — the markup carries a modifier
                    // class built from the value — so a rule for it counts as drawing it.
                    if (!str_contains($this->rendererFor($type), "'" . $option . "'")
                        && !$this->hasStyleRuleFor($option)) {
                        $unimplemented[] = $type . '.' . $key . '=' . $option;
                    }
                }
            }
        }

        $this->assertSame([], $unimplemented, 'these display styles are offered and nothing draws them');
    }

    /**
     * Is there a CSS rule for this variant's modifier class?
     *
     * `ml-quotes--wall` is a real implementation even though the blade only interpolates the value
     * into a class name — but only if something styles it. A modifier class with no rule changes
     * nothing on the page, which is the failure this whole test exists to catch.
     */
    private function hasStyleRuleFor(string $option): bool
    {
        $styles = (string) file_get_contents(resource_path('views/theme-sections/home.blade.php'))
            . (string) file_get_contents(resource_path('views/theme-sections/footer.blade.php'));

        return str_contains($styles, '--' . $option . '{')
            || str_contains($styles, '--' . $option . ' ')
            || str_contains($styles, '.is-' . $option);
    }

    /**
     * The markup that draws one section: its own @case block, plus any partial that block includes.
     */
    private function rendererFor(string $type): string
    {
        $sources = array_merge(
            glob(resource_path('views/theme-sections/*.blade.php')),
            glob(resource_path('views/theme-sections/partials/*.blade.php')),
        );

        $markup = '';

        foreach ($sources as $file) {
            $contents = (string) file_get_contents($file);

            // header.blade.php and footer.blade.php render one section each without a @switch.
            if (!str_contains($contents, "@case('")) {
                $markup .= $contents;

                continue;
            }

            if (preg_match("/@case\('" . preg_quote($type, '/') . "'\)(.*?)@break/s", $contents, $matches) === 1) {
                $markup .= $matches[1];
            }
        }

        foreach (['hero', 'banner-grid', 'banner-mosaic', 'banner-split', 'banner-strip', 'product-card', 'usp-icon', 'vendor-card'] as $partial) {
            if (str_contains($markup, "partials.{$partial}") || str_contains($markup, "partials." . str_replace('-', '', $partial))) {
                $markup .= (string) @file_get_contents(resource_path("views/theme-sections/partials/{$partial}.blade.php"));
            }
        }

        return $markup;
    }
}
