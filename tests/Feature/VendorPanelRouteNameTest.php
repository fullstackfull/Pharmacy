<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Every route name the seller's panel writes into a page must exist.
 *
 * `route()` throws when the name is unknown, so a template naming a route that has been renamed does
 * not degrade — it returns a 500 the moment that page is rendered. The barcode page did exactly
 * that: it linked to `vendor.products.edit`, which had become `vendor.products.update`, so a seller
 * opening the barcode sheet for any product without a code got an error instead.
 *
 * Guarded here rather than found in production. Names inside a `Route::has()` check are the
 * deliberate way to reference a screen that may not have shipped, so those are left alone; a bare
 * `route('vendor.…')` is a promise that the name is real.
 */
class VendorPanelRouteNameTest extends TestCase
{
    public function test_every_vendor_route_name_written_into_a_page_exists(): void
    {
        $missing = [];
        $skipped = [];

        foreach ($this->panelBlades() as $file) {
            $blade = (string) file_get_contents($file);

            foreach ($this->routeNames($blade) as $name) {
                if ($this->isGuarded($blade, $name) || Route::has($name)) {
                    continue;
                }

                // A name belonging to a module this install does not have cannot be asserted: its
                // routes are not registered because its files are not here. The panel guards those
                // sections with a module-published check rather than Route::has, which is why they
                // render nothing rather than throwing.
                $module = $this->moduleBehind($name);
                if ($module !== null && !$this->moduleInstalled($module)) {
                    $skipped[] = $name . ' (' . $module . ' not installed)';
                    continue;
                }

                $missing[] = str_replace(resource_path('views/'), '', $file) . ' → ' . $name;
            }
        }

        // Said out loud rather than swallowed: a check that quietly skips things reads as coverage
        // it does not have.
        if ($skipped !== []) {
            fwrite(STDERR, "\n  not checked: " . implode(', ', array_unique($skipped)) . "\n");
        }

        $this->assertSame([], $missing, "these names do not exist:\n" . implode("\n", $missing));
    }

    /** The optional module a route name belongs to, or null when it belongs to the core panel. */
    private function moduleBehind(string $name): ?string
    {
        foreach (['auction' => 'Auction', 'tax' => 'TaxModule', 'blog' => 'Blog'] as $segment => $module) {
            if (str_starts_with($name, 'vendor.' . $segment)) {
                return $module;
            }
        }

        return null;
    }

    private function moduleInstalled(string $module): bool
    {
        $statuses = json_decode((string) @file_get_contents(base_path('modules_statuses.json')), true) ?: [];

        return ($statuses[$module] ?? false) === true && is_dir(base_path('Modules/' . $module));
    }

    /** @return array<int, string> */
    private function panelBlades(): array
    {
        $files = [];

        foreach ([resource_path('views/vendor-views'), resource_path('views/layouts/vendor')] as $directory) {
            if (!is_dir($directory)) {
                continue;
            }

            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory)) as $file) {
                if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
                    $files[] = $file->getPathname();
                }
            }
        }

        return $files;
    }

    /** @return array<int, string> */
    private function routeNames(string $blade): array
    {
        preg_match_all("/route\(\s*'(vendor\.[a-z0-9._-]+)'/", $blade, $found);

        return array_values(array_unique($found[1] ?? []));
    }

    /**
     * A name checked with `Route::has()` first is a deliberate reference to something that may not
     * be there — the pattern the Seller Center's own navigation uses through eight waves.
     */
    private function isGuarded(string $blade, string $name): bool
    {
        return str_contains($blade, "Route::has('" . $name . "')")
            || str_contains($blade, 'Route::has("' . $name . '")');
    }
}
