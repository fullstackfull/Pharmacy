<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The seller panel's logical direction utilities.
 *
 * The vendor panel is served by Bootstrap 4.5, which predates ms-/me-/ps-/pe-. 6Valley hand-added a
 * scattered handful, so the set looked available while most of it was missing — and three classes
 * that DID exist were physical despite their logical names:
 *
 *     .me-3        margin-right: 1rem   (bootstrap.min.css)
 *     .float-start float: left          (custom.css)
 *     .float-end   float: right         (custom.css)
 *
 * A seller writing me-3 to mean "end" therefore got no mirroring at all in Arabic: the class read
 * as correct and behaved as wrong. These tests guard the corrections against a future asset update
 * quietly reinstating the physical versions.
 */
class VendorLogicalUtilitiesTest extends TestCase
{
    private const STYLESHEET = 'public/assets/back-end/css/vendor/logical-utilities.css';
    private const STYLE_PARTIAL = 'resources/views/layouts/vendor/partials/_style-partials.blade.php';

    private function css(): string
    {
        return file_get_contents(base_path(self::STYLESHEET));
    }

    /**
     * Every stylesheet the vendor layout links, concatenated.
     *
     * The requirement is that a class resolves logically SOMEWHERE in the bundle, not that this
     * particular file defines it — ps-2 and pe-3 were already correct in custom.css and are
     * deliberately not duplicated here.
     */
    private function vendorBundle(): string
    {
        $partial = file_get_contents(base_path(self::STYLE_PARTIAL));
        preg_match_all('#public/assets/[^"\']*\.css#', $partial, $hrefs);

        $css = '';
        foreach (array_unique($hrefs[0]) as $href) {
            $path = base_path($href);
            if (is_file($path)) {
                $css .= "\n" . file_get_contents($path);
            }
        }

        return $css;
    }

    /** Every step the templates use must exist, or converting ml-3 to ms-3 deletes the spacing. */
    public function test_the_full_spacing_scale_resolves_logically(): void
    {
        $css = $this->vendorBundle();

        foreach (['ms' => 'margin-inline-start', 'me' => 'margin-inline-end',
                  'ps' => 'padding-inline-start', 'pe' => 'padding-inline-end'] as $prefix => $property) {
            foreach ([0, 1, 2, 3, 4] as $step) {
                $this->assertMatchesRegularExpression(
                    '/\.' . $prefix . '-' . $step . '\s*\{[^}]*' . preg_quote($property, '/') . '/',
                    $css,
                    ".{$prefix}-{$step} does not resolve to {$property} anywhere in the vendor bundle, "
                    . 'so converting a physical class to it would remove the spacing'
                );
            }
        }

        foreach (['ms-auto' => 'margin-inline-start', 'me-auto' => 'margin-inline-end'] as $class => $property) {
            $this->assertMatchesRegularExpression(
                '/\.' . $class . '\s*\{[^}]*' . preg_quote($property, '/') . '/',
                $css,
                ".{$class} does not resolve logically"
            );
        }
    }

    /** The three misnamed classes must now use logical properties. */
    public function test_the_physically_defined_classes_are_corrected(): void
    {
        $css = $this->css();

        $this->assertMatchesRegularExpression('/\.me-3\s*\{[^}]*margin-inline-end/', $css);
        $this->assertMatchesRegularExpression('/\[dir="rtl"\]\s*\.float-start\s*\{[^}]*float:\s*right/', $css);
        $this->assertMatchesRegularExpression('/\[dir="rtl"\]\s*\.float-end\s*\{[^}]*float:\s*left/', $css);
    }

    /**
     * ms-5 must NOT be redefined. 6Valley set it to 2rem rather than Bootstrap's 3rem, and
     * overriding it here would silently move every layout already using it.
     */
    public function test_the_existing_deviating_step_is_left_alone(): void
    {
        $this->assertStringNotContainsString('.ms-5', $this->css());
    }

    /** Load order is the whole mechanism: earlier, and bootstrap.min.css/custom.css win. */
    public function test_it_is_loaded_after_the_stylesheets_it_corrects(): void
    {
        $partial = file_get_contents(base_path(self::STYLE_PARTIAL));

        $ours = strpos($partial, 'vendor/logical-utilities.css');
        $this->assertNotFalse($ours, 'the stylesheet is not loaded at all');

        foreach (['bootstrap.min.css', 'back-end/css/custom.css'] as $earlier) {
            $position = strpos($partial, $earlier);
            $this->assertNotFalse($position, "{$earlier} is no longer loaded — re-check the load order");
            $this->assertGreaterThan(
                $position,
                $ours,
                "logical-utilities.css must load after {$earlier} or its corrections lose"
            );
        }
    }

    /** Only the vendor panel: the admin panel gets these from Bootstrap 5.3 already. */
    public function test_it_is_not_loaded_into_the_admin_panel(): void
    {
        $adminPartial = file_get_contents(base_path('resources/views/layouts/admin/partials/_style-partials.blade.php'));

        $this->assertStringNotContainsString('vendor/logical-utilities.css', $adminPartial);
    }
}
