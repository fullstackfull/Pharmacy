<?php

namespace Tests\Feature\Analytics;

use App\Models\ProductCompare;
use App\Services\Analytics\Analytics;
use App\Services\Analytics\AnalyticsEvent;
use App\Services\Analytics\CommerceInstrumentation;
use App\Services\Analytics\EventRecorder;
use App\Services\Analytics\Support\PathNormalizer;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * An event in the catalogue that nothing produces is worse than a missing feature.
 *
 * It reads as a measurement: the name appears in the event dimension, the funnel has a step for it,
 * and the step is empty — which a merchant reads as "nobody does this" rather than "nobody counts
 * this". cart_viewed and compare_added sat on the browser allow-list for exactly that reason, and
 * could never be sent because no element in the theme carries the attribute that would have sent
 * them. Four more — cart_removed, coupon_applied, signed_in, campaign_clicked — had a method on the
 * one door and no caller anywhere.
 *
 * So the catalogue is checked against the code that produces it, mechanically.
 */
class EveryEventCanFireTest extends TestCase
{
    public function test_every_event_the_catalogue_names_is_emitted_by_the_one_door(): void
    {
        // Everything the server records goes through Analytics. A name in the catalogue that no
        // method there emits is a name that appears in the event dimension and never in the data.
        $door = (string) file_get_contents(app_path('Services/Analytics/Analytics.php'));
        $constants = array_flip(array_filter(
            (new ReflectionClass(AnalyticsEvent::class))->getConstants(),
            static fn ($value) => is_string($value),
        ));
        $missing = [];

        foreach (AnalyticsEvent::names() as $name) {
            if (AnalyticsEvent::isClientAllowed($name)) {
                continue;
            }

            if (!str_contains($door, 'AnalyticsEvent::' . $constants[$name])) {
                $missing[] = $name;
            }
        }

        $this->assertSame([], $missing, 'these events are in the catalogue and nothing can ever record them');
    }

    public function test_every_event_the_browser_may_send_is_sent_by_something(): void
    {
        // The failure this whole test exists for. cart_viewed and compare_added were accepted from
        // the browser and no element in the theme carried the data-analytics attribute that would
        // have sent either, so the allow-list described a client that did not exist.
        $script = (string) file_get_contents(public_path('assets/front-end/js/analytics-beacon.js'));
        $senders = (string) preg_replace('/var ALLOWED = \[.*?\];/s', '', $script) . $this->themeMarkup();

        foreach (AnalyticsEvent::names() as $name) {
            if (!AnalyticsEvent::isClientAllowed($name)) {
                continue;
            }

            $this->assertTrue(
                str_contains($senders, "'" . $name . "'")
                    || str_contains($senders, 'data-analytics="' . $name . '"')
                    // The other way an element names its own event: a section reports itself when
                    // it scrolls into view, which is a thing no click handler can see.
                    || str_contains($senders, 'data-analytics-view="' . $name . '"'),
                "{$name} is accepted from the browser and nothing in the beacon or the theme ever sends it",
            );
        }
    }

    public function test_every_method_on_the_one_door_is_called_from_somewhere(): void
    {
        // The other half of the same rule: a recording method nobody calls is a dead event with a
        // convincing implementation behind it.
        $uncalled = [];

        foreach ((new ReflectionClass(Analytics::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isConstructor() || in_array($method->name, ['fromClient', 'flush'], true)) {
                continue;
            }

            if ($this->callSitesOf('->' . $method->name . '(') === 0) {
                $uncalled[] = $method->name;
            }
        }

        $this->assertSame([], $uncalled, 'these recording methods have no caller, so their events never happen');
    }

    public function test_the_cart_page_is_recorded_by_the_server_that_serves_it(): void
    {
        config()->set('currency_code', 'SYP');

        $recorded = $this->capture(fn (Analytics $analytics) => $analytics->cartViewed(items: 3, value: 249.5));

        $this->assertSame(AnalyticsEvent::CART_VIEWED, $recorded->name);
        $this->assertSame(3, $recorded->quantity);
        $this->assertSame(249.5, $recorded->value);
    }

    public function test_adding_to_the_compare_tray_is_recorded_from_the_row_it_writes(): void
    {
        // Through the model event, so the API and anything added later are covered without a second
        // instrumentation point that will be forgotten.
        $recorded = null;
        $recorder = $this->recorderCapturing($recorded);

        $this->bootInstrumentation($recorder);

        Schema::dropIfExists('product_compares');
        Schema::create('product_compares', function ($table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamps();
        });

        ProductCompare::create(['product_id' => 77, 'user_id' => 4]);

        $this->assertSame(AnalyticsEvent::COMPARE_ADDED, $recorded?->name);
        $this->assertSame('77', $recorded?->entityId);
    }

    public function test_signing_in_is_taken_from_the_framework_event_and_only_for_shoppers(): void
    {
        $recorded = null;
        $recorder = $this->recorderCapturing($recorded);

        $this->bootInstrumentation($recorder);

        $user = new \App\Models\User();
        $user->id = 9;

        // Staff first: an administrator in the conversion funnel is a corrupted funnel.
        Event::dispatch(new Login('admin', $user, false));
        $this->assertNull($recorded);

        Event::dispatch(new Login('customer', $user, false));
        $this->assertSame(AnalyticsEvent::SIGNED_IN, $recorded?->name);
        $this->assertSame('9', $recorded?->entityId);
    }

    // ---------------------------------------------------------------------------------------

    private function themeMarkup(): string
    {
        $markup = '';

        $views = array_merge(
            glob(resource_path('themes/default/web-views/**/*.blade.php'), GLOB_BRACE) ?: [],
            // The composed storefront: its shell marks the sections, and its per-type partials
            // carry the banner links.
            glob(resource_path('views/theme-sections/*.blade.php')) ?: [],
            glob(resource_path('views/theme-sections/**/*.blade.php')) ?: [],
        );

        foreach ($views as $view) {
            $markup .= (string) file_get_contents($view);
        }

        return $markup;
    }

    private function callSitesOf(string $needle): int
    {
        $found = 0;

        foreach ([app_path(), base_path('Modules')] as $root) {
            if (!is_dir($root)) {
                continue;
            }

            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $path = $file->getPathname();

                // The catalogue declares the names and the one door spells them; neither is a
                // producer, so neither counts as one.
                if (str_ends_with($path, 'AnalyticsEvent.php') || str_ends_with($path, 'Analytics/Analytics.php')) {
                    continue;
                }

                if (str_contains((string) file_get_contents($path), $needle)) {
                    $found++;
                }
            }
        }

        return $found;
    }

    private function capture(callable $record): AnalyticsEvent
    {
        $recorded = null;
        $recorder = $this->recorderCapturing($recorded);

        $record(new Analytics($recorder, app(PathNormalizer::class)));

        $this->assertNotNull($recorded);

        return $recorded;
    }

    private function bootInstrumentation(EventRecorder $recorder): void
    {
        (new CommerceInstrumentation(new Analytics($recorder, app(PathNormalizer::class))))->boot();
    }

    private function recorderCapturing(?AnalyticsEvent &$recorded): EventRecorder
    {
        return new class ($recorded) extends EventRecorder {
            public function __construct(private ?AnalyticsEvent &$captured)
            {
            }

            public function record(AnalyticsEvent $event, $request = null): void
            {
                $this->captured = $event;
            }
        };
    }
}
