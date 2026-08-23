<?php

namespace Tests\Feature;

use App\Models\Theme;
use App\Models\ThemeSection;
use App\Models\ThemeVersion;
use App\Services\Theme\SectionVisibility;
use App\Services\Theme\ThemeBuilderService;
use App\Services\Theme\ThemeDelivery;
use App\Services\Theme\ThemeManager;
use App\Services\Theme\ViewerContext;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ThemeVisibilityGuaranteeTest extends TestCase
{
    private Theme $theme;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        foreach (['theme_blocks','theme_sections','theme_versions','themes'] as $t) Schema::dropIfExists($t);
        Schema::create('themes', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('slug',120)->unique();
            $t->boolean('is_active')->default(false); $t->boolean('is_system')->default(false); $t->timestamps();
        });
        Schema::create('theme_versions', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('theme_id'); $t->string('label')->nullable();
            $t->string('status',20)->default('draft'); $t->unsignedInteger('revision')->default(0);
            $t->string('checksum',64)->nullable(); $t->json('settings')->nullable();
            $t->unsignedBigInteger('based_on_version_id')->nullable(); $t->timestamp('published_at')->nullable(); $t->timestamps();
        });
        Schema::create('theme_sections', function (Blueprint $t) {
            $t->id(); $t->uuid('uuid')->nullable(); $t->unsignedBigInteger('theme_version_id');
            $t->string('page',60)->default('home'); $t->string('type',80); $t->unsignedInteger('sort_order')->default(0);
            $t->boolean('is_visible')->default(true); $t->timestamp('starts_at')->nullable(); $t->timestamp('ends_at')->nullable();
            $t->json('platforms')->nullable(); $t->json('audience')->nullable(); $t->json('settings')->nullable(); $t->timestamps();
        });
        Schema::create('theme_blocks', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('theme_section_id'); $t->string('type',80);
            $t->unsignedInteger('sort_order')->default(0); $t->boolean('is_visible')->default(true);
            $t->json('settings')->nullable(); $t->timestamps();
        });
        $this->theme = Theme::create(['name'=>'T','slug'=>'t','is_active'=>true]);
    }

    /** The user's exact flow: rules saved through the builder service, published, fetched as the app. */
    private function appSees(array $rules): array
    {
        $builder = app(ThemeBuilderService::class);
        $draft = ThemeVersion::create(['theme_id'=>$this->theme->id,'status'=>ThemeVersion::STATUS_DRAFT]);
        $section = $builder->addSection($draft, 'home', 'usp_strip');
        $saved = $builder->setDeliveryRules($section, $rules);
        app(ThemeManager::class)->publish($draft->refresh());
        Cache::flush();

        $viewer = new ViewerContext(
            platform: ViewerContext::PLATFORM_APP,
            device: ViewerContext::DEVICE_MOBILE,
            authenticated: false,
        );
        $payload = app(ThemeDelivery::class)->payload('home', $viewer);
        return ['saved'=>$saved, 'stored'=>$section->fresh()->platforms,
                'types'=>array_column($payload['sections'],'type')];
    }

    public function test_all_options_ticked_still_shows_in_the_app(): void
    {
        $r = $this->appSees(['platforms'=>['web','app','desktop','tablet','mobile'],
                             'audience'=>['guest','customer']]);
        $this->assertContains('usp_strip', $r['types'],
            'ALL boxes ticked must mean "everywhere", stored='.json_encode($r['stored']));
    }

    public function test_app_only_ticked_shows_in_the_app(): void
    {
        $r = $this->appSees(['platforms'=>['app']]);
        $this->assertContains('usp_strip', $r['types'], 'stored='.json_encode($r['stored']));
    }

    public function test_app_plus_desktop_ticked_shows_in_the_app(): void
    {
        // The trap case: the merchant means "app, and on web only desktop" — the device tokens
        // must not smuggle a hide onto the app they explicitly ticked.
        $r = $this->appSees(['platforms'=>['app','desktop']]);
        $this->assertContains('usp_strip', $r['types'], 'stored='.json_encode($r['stored']));
    }

    public function test_web_only_ticked_hides_from_the_app(): void
    {
        $r = $this->appSees(['platforms'=>['web']]);
        $this->assertNotContains('usp_strip', $r['types']);
    }

    /**
     * The 100% guarantee, exhaustively: over ALL 32 subsets of the place checkboxes, a subset
     * containing "app" is visible to the app, a subset containing "web" is visible to the web,
     * and the empty subset is visible everywhere. A checked box can never be the reason a section
     * disappears from the very place it names.
     */
    public function test_every_subset_containing_app_shows_in_the_app(): void
    {
        $vis = new SectionVisibility();
        $tokens = ['web', 'app', 'desktop', 'tablet', 'mobile'];
        $appPhone = new ViewerContext(platform: 'app', device: 'mobile');
        $appTablet = new ViewerContext(platform: 'app', device: 'tablet');
        $webDesktop = new ViewerContext(platform: 'web', device: 'desktop');

        for ($mask = 0; $mask < 32; $mask++) {
            $subset = array_values(array_filter($tokens, fn ($t, $i) => $mask & (1 << $i), ARRAY_FILTER_USE_BOTH));
            $section = ['is_visible' => true, 'settings' => [], 'platforms' => $subset,
                        'audience' => null, 'starts_at' => null, 'ends_at' => null];
            $label = json_encode($subset);

            if ($subset === [] || in_array('app', $subset, true)) {
                $this->assertTrue($vis->passes($section, $appPhone), "app phone must see $label");
                $this->assertTrue($vis->passes($section, $appTablet), "app tablet must see $label");
            }
            if ($subset === [] || in_array('web', $subset, true)) {
                $this->assertTrue($vis->passes($section, $webDesktop), "web must see $label");
            }
            if ($subset !== [] && !in_array('app', $subset, true)
                && !in_array('mobile', $subset, true) && !in_array('tablet', $subset, true)) {
                $this->assertFalse($vis->passes($section, $appPhone), "app phone must NOT see $label");
            }
        }
    }

    public function test_place_union_matrix(): void
    {
        $vis = new SectionVisibility();
        $app = new ViewerContext(platform:'app', device:'mobile');
        $web = new ViewerContext(platform:'web', device:'desktop');
        foreach ([
            [['web','app','desktop','tablet','mobile'], true,  true],
            [['app'],                                   true,  false],
            [['app','mobile'],                          true,  false],
            // The reported trap: app ticked beside a web breakpoint — BOTH places show it now.
            [['app','desktop'],                         true,  true ],
            [['web','desktop'],                         false, true ],
            [[],                                        true,  true ],
        ] as [$platforms, $appExpected, $webExpected]) {
            $section = ['is_visible'=>true,'settings'=>[],'platforms'=>$platforms,'audience'=>null,
                        'starts_at'=>null,'ends_at'=>null];
            $this->assertSame($appExpected, $vis->passes($section,$app),
                'app viewer vs '.json_encode($platforms).' reason='.json_encode($vis->reasonFor($section,$app)));
            $this->assertSame($webExpected, $vis->passes($section,$web),
                'web viewer vs '.json_encode($platforms).' reason='.json_encode($vis->reasonFor($section,$web)));
        }
    }
}
