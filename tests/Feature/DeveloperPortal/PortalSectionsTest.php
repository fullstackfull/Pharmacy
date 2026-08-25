<?php

namespace Tests\Feature\DeveloperPortal;

use App\Models\VendorPayoutRequest;
use App\Services\DeveloperPortal\PortalNavigation;
use App\Services\DeveloperPortal\PortalReference;
use App\Services\DeveloperPortal\Support\EndpointClassifier;
use App\Services\DeveloperPortal\WebhookContract;
use App\Services\Marketplace\SellerWebhookDispatcher;
use Illuminate\Routing\Route;
use Tests\TestCase;

/**
 * Sections the navigation declared and that opened onto an empty card.
 *
 * A placeholder is worse than an unlisted section: a developer who clicks "Models and enums" and
 * finds nothing concludes the API has no documented shapes, and the webhooks entry was the worst of
 * them — its capability probe returned true so it rendered enabled, over a complete signed-delivery
 * system with six events, an SSRF-guarded dialler and a retry ledger that nobody could read about.
 */
class PortalSectionsTest extends TestCase
{
    /** Every section the navigation offers has to render something. */
    public function test_no_declared_section_is_left_without_content(): void
    {
        $reflection = new \ReflectionMethod(\App\Http\Controllers\Admin\Telemetry\DeveloperPortalController::class, 'dataFor');
        $source = file_get_contents($reflection->getFileName());
        $body = substr($source, $reflection->getStartLine() * 0);

        $unbuilt = [];

        foreach (array_keys(PortalNavigation::sections()) as $section) {
            // Downloads and per-endpoint pages have their own routes rather than a data branch.
            if (in_array($section, ['openapi', 'postman', 'console', 'explorer'], true)) {
                continue;
            }

            $hasBranch = str_contains($body, "'" . $section . "'");
            $hasView = is_file(resource_path('views/admin-views/telemetry/developer/' . $section . '.blade.php'));

            if (!$hasBranch && !$hasView) {
                $unbuilt[] = $section;
            }
        }

        $this->assertSame([], $unbuilt, 'declared in the navigation and rendering a placeholder: ' . implode(', ', $unbuilt));
    }

    /** Read from the running system, so the page cannot promise a guarantee the platform does not make. */
    public function test_the_webhook_contract_is_read_from_the_dispatcher_and_the_policy(): void
    {
        $contract = app(WebhookContract::class)->describe();

        $this->assertSame(
            SellerWebhookDispatcher::EVENTS,
            array_column($contract['events'], 'event'),
            'the documented event list has drifted from the one the dispatcher sends',
        );

        $this->assertSame('X-Seller-Signature', $contract['signature']['header']);
        $this->assertSame(5, $contract['retries']['max_attempts']);

        // 2 + 4 + 8 + 16 across four gaps between five attempts.
        $this->assertSame(30, $contract['retries']['total_window_minutes']);
    }

    /** Every event an integrator can subscribe to says what it means. */
    public function test_every_event_carries_a_meaning(): void
    {
        foreach (app(WebhookContract::class)->describe()['events'] as $event) {
            $this->assertNotNull($event['meaning'], $event['event'] . ' is offered with no explanation of when it fires');
        }
    }

    public function test_the_enum_reference_reads_the_classes_own_constants(): void
    {
        $enums = collect(app(PortalReference::class)->models()['enums'])->keyBy('name');

        $this->assertSame(VendorPayoutRequest::STATUSES, $enums['payout_status']['values']);
        $this->assertSame(SellerWebhookDispatcher::EVENTS, $enums['webhook_event']['values']);

        foreach ($enums as $enum) {
            $this->assertNotNull($enum['values'], $enum['name'] . ' could not be read from ' . $enum['declared_in']);
        }
    }

    /**
     * The callbacks that move money were classified as panel routes.
     *
     * They sit under /payment/* rather than api/, so the explorer, the OpenAPI export and the
     * coverage score all skipped the endpoints most likely to be pointed at the wrong host during a
     * migration.
     */
    public function test_an_inbound_payment_callback_is_part_of_the_api_surface(): void
    {
        $classifier = app(EndpointClassifier::class);

        $callback = new Route(['POST'], 'payment/paystack/callback', []);
        $panel = new Route(['GET'], 'admin/dashboard', []);

        $this->assertSame('api', $classifier->classify($callback, ['actor' => 'partner'], null)['surface']);
        $this->assertSame('panel', $classifier->classify($panel, ['actor' => 'admin'], null)['surface']);
    }

    /** A callback nothing guards is a URL anybody can POST an order state into. */
    public function test_the_integrations_page_says_which_inbound_endpoints_are_unguarded(): void
    {
        $inbound = app(PortalReference::class)->integrations()['inbound'];

        $this->assertNotEmpty($inbound, 'this shop has inbound integration endpoints and the page lists none');

        foreach ($inbound as $endpoint) {
            $this->assertArrayHasKey('guarded', $endpoint);
            $this->assertIsBool($endpoint['guarded']);
        }
    }
}
