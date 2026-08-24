<?php

namespace Tests\Feature;

use App\Models\Theme;
use App\Models\ThemeBlock;
use App\Models\ThemeSection;
use App\Models\ThemeVersion;
use App\Services\Theme\SectionRegistry;
use App\Services\Theme\StorefrontThemeRenderer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The storefront home page, actually rendered.
 *
 * Every other theme test asks a service a question. None of them rendered the page, and that gap
 * cost a live outage: splitting the renderer into one partial per type moved each body into its own
 * view, where `$__data` — the variable the sections read their data from — means something else.
 * Blade names the array of variables it passes into a view `$__data`, and `@include` excludes that
 * name from what it forwards, so inside every partial the resolver was an array and the first
 * section to call a method on it took the whole page down with a 500.
 *
 * Nothing static could have caught it: the extracted bodies were byte-for-byte identical to the
 * cases they replaced, every partial compiled, and the option-coverage scan found every key it
 * looked for. Only rendering finds it. So this renders — every section type the builder offers for
 * the home page, one at a time, so a failure names the type rather than the page.
 */
class StorefrontRendersTest extends TestCase
{
    private ThemeVersion $version;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        session(['local' => 'en']);

        foreach (['theme_blocks', 'theme_sections', 'theme_versions', 'themes', 'business_settings'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::create('business_settings', function (Blueprint $table) {
            $table->id(); $table->string('type')->nullable(); $table->longText('value')->nullable();
            $table->timestamps();
        });
        Schema::create('themes', function (Blueprint $table) {
            $table->id(); $table->string('name'); $table->string('slug', 120)->unique();
            $table->boolean('is_active')->default(false); $table->boolean('is_system')->default(false);
            $table->timestamps();
        });
        Schema::create('theme_versions', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('theme_id'); $table->string('label')->nullable();
            $table->string('status', 20)->default('draft'); $table->json('settings')->nullable();
            $table->unsignedBigInteger('based_on_version_id')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->unsignedInteger('revision')->default(0); $table->string('checksum', 64)->nullable();
            $table->timestamps();
        });
        Schema::create('theme_sections', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('theme_version_id'); $table->uuid('uuid')->nullable();
            $table->string('page', 60)->default('home'); $table->string('type', 80);
            $table->unsignedInteger('sort_order')->default(0); $table->boolean('is_visible')->default(true);
            $table->json('settings')->nullable();
            $table->timestamp('starts_at')->nullable(); $table->timestamp('ends_at')->nullable();
            $table->json('platforms')->nullable(); $table->json('audience')->nullable();
            $table->timestamps();
        });
        Schema::create('theme_blocks', function (Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('theme_section_id'); $table->string('type', 80);
            $table->unsignedInteger('sort_order')->default(0); $table->boolean('is_visible')->default(true);
            $table->json('settings')->nullable(); $table->timestamps();
        });

        $theme = Theme::create(['name' => 'Storefront', 'slug' => 'storefront', 'is_active' => true]);
        $this->version = ThemeVersion::create([
            'theme_id' => $theme->id, 'status' => ThemeVersion::STATUS_PUBLISHED, 'revision' => 1,
        ]);
    }

    public function test_every_home_section_type_renders_without_taking_the_page_down(): void
    {
        $failures = [];

        foreach ($this->homeTypes() as $type) {
            $this->only($type);

            try {
                view('theme-sections.home')->render();
            } catch (\Throwable $exception) {
                $failures[$type] = $exception->getMessage();
            }
        }

        $this->assertSame([], $failures, 'these section types throw while rendering the storefront');
    }

    public function test_every_section_renders_with_its_cards_in_it(): void
    {
        // An empty section takes the "nothing to show" path, which is the one path that draws no
        // card markup at all. Every hero slide, promo tile and FAQ row lives past that branch.
        $failures = [];

        foreach ($this->homeTypes() as $type) {
            $blockType = app(SectionRegistry::class)->defaultBlockType($type);
            if ($blockType === null) {
                continue;
            }

            $section = $this->only($type);
            ThemeBlock::create([
                'theme_section_id' => $section->id, 'type' => $blockType, 'sort_order' => 1,
                'is_visible' => true,
                // Enough for any card shape: a title, an image, a link, and the before/after pair.
                'settings' => [
                    'title' => 'Ramadan', 'subtitle' => 'Up to 40%', 'label' => 'Vitamins',
                    'body' => 'Delivered the same day.', 'image' => '/storage/banner/a.webp',
                    'after' => '/storage/banner/b.webp', 'link' => '/products',
                    'button_text' => 'Shop', 'value' => '4.9', 'icon' => 'truck',
                ],
            ]);
            $this->flush();

            try {
                $html = view('theme-sections.home')->render();
                $this->assertNotSame('', trim($html), $type);
            } catch (\Throwable $exception) {
                $failures[$type] = $exception->getMessage();
            }
        }

        $this->assertSame([], $failures, 'these section types throw once they have a card in them');
    }

    public function test_the_header_and_footer_pages_render_too(): void
    {
        // Same renderer, two more pages. A section type that only ever appears in the footer would
        // otherwise be covered by nothing at all.
        $registry = app(SectionRegistry::class);

        foreach (['header', 'footer'] as $page) {
            $types = array_keys(array_filter(
                $registry->types(),
                static fn (array $definition) => in_array($page, $definition['pages'], true),
            ));

            ThemeSection::where('theme_version_id', $this->version->id)->delete();
            foreach ($types as $index => $type) {
                ThemeSection::create([
                    'theme_version_id' => $this->version->id, 'page' => $page, 'type' => $type,
                    'sort_order' => $index + 1, 'is_visible' => true, 'settings' => [],
                ]);
            }
            $this->flush();

            view('theme-sections.' . $page)->render();
        }

        // Reached only if neither page threw; the render itself is the assertion.
        $this->assertTrue(true);
    }

    public function test_no_partial_reads_a_name_blade_reserves(): void
    {
        // $__data cost an outage. The others in this list cost the same way: Blade either strips
        // them at the include boundary or overwrites them inside the view, so a section reading one
        // gets something other than what the shell put there.
        $reserved = ['$__data', '$__path', '$__currentLoopData'];

        foreach (glob(resource_path('views/theme-sections/**/*.blade.php'), GLOB_BRACE)
                 + glob(resource_path('views/theme-sections/*.blade.php')) as $view) {
            foreach ($reserved as $name) {
                $this->assertStringNotContainsString(
                    $name,
                    file_get_contents($view),
                    basename($view) . ' reads ' . $name . ', which belongs to Blade',
                );
            }
        }
    }

    public function test_a_page_of_every_section_at_once_still_renders(): void
    {
        // Types interact: one section leaves state behind for the next, and the shell resolves data
        // before the wrapper opens. A page is not the sum of its sections rendered separately.
        foreach ($this->homeTypes() as $index => $type) {
            ThemeSection::create([
                'theme_version_id' => $this->version->id, 'page' => 'home', 'type' => $type,
                'sort_order' => $index + 1, 'is_visible' => true, 'settings' => [],
            ]);
        }
        $this->flush();

        $html = view('theme-sections.home')->render();

        $this->assertNotSame('', trim($html));
        $this->assertStringContainsString('theme-builder-sections', $html);
    }

    public function test_the_resolver_reaches_the_partials_it_was_split_into(): void
    {
        // The exact failure: a partial that reads its data through a name Blade reserves gets an
        // array instead of the resolver, and the first method call on it is a 500.
        $this->only('category_grid');

        $this->assertStringNotContainsString(
            '$__data',
            file_get_contents(resource_path('views/theme-sections/home.blade.php')),
            'Blade excludes $__data from what @include forwards — it can never be the resolver in a partial',
        );

        foreach (glob(resource_path('views/theme-sections/types/*.blade.php')) as $partial) {
            $this->assertStringNotContainsString('$__data', file_get_contents($partial), basename($partial));
        }
    }

    public function test_nothing_published_renders_nothing_rather_than_failing(): void
    {
        $this->version->update(['status' => ThemeVersion::STATUS_DRAFT]);
        $this->flush();

        $this->assertSame('', trim(view('theme-sections.home')->render()));
    }

    /** @return array<int, string> */
    private function homeTypes(): array
    {
        return array_keys(array_filter(
            app(SectionRegistry::class)->types(),
            static fn (array $definition) => in_array('home', $definition['pages'], true),
        ));
    }

    private function only(string $type): ThemeSection
    {
        ThemeSection::where('theme_version_id', $this->version->id)->delete();

        $section = ThemeSection::create([
            'theme_version_id' => $this->version->id, 'page' => 'home', 'type' => $type,
            'sort_order' => 1, 'is_visible' => true, 'settings' => [],
        ]);
        $this->flush();

        return $section;
    }

    private function flush(): void
    {
        app(StorefrontThemeRenderer::class)->flush();
        Cache::flush();
    }
}
