<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * One partial per section type, and the two things that has to keep being true.
 *
 * The storefront renderer was a single 1,244-line `@switch` over thirty-seven section types. It
 * worked, and it made every change to one rail a change to the file that draws all of them: a
 * stray `@endif` in the coupon strip took the whole home page down, not the coupon strip, and the
 * only way to read one section's markup was to scroll past thirty-six others.
 *
 * Splitting it moves that risk rather than removing it, in two ways. A type whose partial is
 * missing renders nothing — silently, on a live home page. And a partial that does not compile
 * fails at request time, in front of a customer, because Blade is compiled lazily. So both are
 * checked here instead: every type this file declares renderable has a partial, and every partial
 * compiles.
 */
class ThemeSectionPartialsTest extends TestCase
{
    private const PARTIALS = 'views/theme-sections/types';

    public function test_the_renderer_dispatches_by_name_rather_than_by_switch(): void
    {
        $home = file_get_contents(resource_path('views/theme-sections/home.blade.php'));

        $this->assertStringContainsString("@includeIf('theme-sections.types.' . \$type)", $home);
        $this->assertStringNotContainsString('@switch($type)', $home);
        $this->assertStringNotContainsString("@case('product_slider')", $home);
    }

    public function test_every_renderable_type_has_a_partial(): void
    {
        // A type in this list and nowhere else is a section a merchant can add, that passes every
        // check, and draws nothing at all.
        foreach ($this->renderableTypes() as $type) {
            $this->assertFileExists(
                resource_path(self::PARTIALS . "/{$type}.blade.php"),
                "the storefront says it renders {$type} but has no partial for it",
            );
        }
    }

    public function test_no_partial_is_left_behind_by_a_type_that_was_removed(): void
    {
        $renderable = $this->renderableTypes();

        foreach ($this->partialNames() as $type) {
            $this->assertContains($type, $renderable, "{$type} has a partial that nothing includes");
        }
    }

    public function test_every_partial_compiles(): void
    {
        // Blade compiles on first request, so a partial with a broken directive is not a failing
        // test — it is a 500 on the home page, at the moment a customer opens it.
        foreach ($this->partialNames() as $type) {
            $compiled = Blade::compileString(
                File::get(resource_path(self::PARTIALS . "/{$type}.blade.php")),
            );

            $this->assertNull($this->syntaxErrorIn($compiled), "{$type}.blade.php does not compile");
        }
    }

    public function test_no_partial_still_carries_the_switch_it_came_out_of(): void
    {
        foreach ($this->partialNames() as $type) {
            $body = File::get(resource_path(self::PARTIALS . "/{$type}.blade.php"));

            $this->assertStringNotContainsString('@case(', $body, $type);
            $this->assertStringNotContainsString('@break', $body, $type);
        }
    }

    // -----------------------------------------------------------------------------------------

    /** @return array<int, string> */
    private function renderableTypes(): array
    {
        $home = file_get_contents(resource_path('views/theme-sections/home.blade.php'));

        preg_match('/\$__renderable = \[(.*?)\];/s', $home, $matches);
        $this->assertNotEmpty($matches, 'the renderable list is what decides what reaches a partial');

        preg_match_all("/'([a-z_]+)'/", $matches[1], $types);

        return $types[1];
    }

    /** @return array<int, string> */
    private function partialNames(): array
    {
        return array_map(
            static fn (string $path) => basename($path, '.blade.php'),
            File::glob(resource_path(self::PARTIALS . '/*.blade.php')),
        );
    }

    /** The PHP syntax error in a compiled template, or null when there is none. */
    private function syntaxErrorIn(string $php): ?string
    {
        $file = tempnam(sys_get_temp_dir(), 'blade') . '.php';
        file_put_contents($file, $php);

        $output = [];
        exec('php -l ' . escapeshellarg($file) . ' 2>&1', $output);
        unlink($file);

        $output = implode("\n", $output);

        return str_contains($output, 'No syntax errors') ? null : $output;
    }
}
