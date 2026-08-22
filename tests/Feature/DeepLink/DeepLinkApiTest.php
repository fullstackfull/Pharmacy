<?php

namespace Tests\Feature\DeepLink;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The two endpoints the apps call.
 *
 * Both must answer without a token — a campaign link arrives before anyone has logged in — and
 * neither may be a way to make the app open a page on somebody else's domain.
 */
class DeepLinkApiTest extends TestCase
{
    private const CONNECTION = 'deeplink_api_test';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.url', 'https://shop.test');
        config()->set('database.connections.' . self::CONNECTION, [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);
        config()->set('analytics.connection', self::CONNECTION);

        DB::purge(self::CONNECTION);
        DB::connection(self::CONNECTION)->getPdo();

        foreach (glob(database_path('migrations/*_create_analytics_tables.php')) as $migration) {
            (require $migration)->up();
        }
        foreach (glob(database_path('migrations/*_add_surface_to_analytics_campaign_clicks.php')) as $migration) {
            (require $migration)->up();
        }
    }

    public function test_the_config_endpoint_publishes_the_path_list_without_authentication(): void
    {
        $response = $this->getJson('/api/v1/deep-link/config');

        $response->assertOk();
        $this->assertContains('/go/*', $response->json('ios.paths'));
        $this->assertContains('/go/*', $response->json('android.paths'));
        $this->assertSame('/go/{code}', $response->json('campaign_path'));
    }

    public function test_the_config_endpoint_does_not_leak_the_signing_fingerprint(): void
    {
        $body = $this->getJson('/api/v1/deep-link/config')->getContent();

        $this->assertStringNotContainsString('sha256', strtolower($body));
        $this->assertStringNotContainsString('fingerprint', strtolower($body));
        $this->assertStringNotContainsString('team_id', strtolower($body));
    }

    public function test_resolving_a_campaign_link_returns_the_screen_and_counts_the_click(): void
    {
        DB::connection(self::CONNECTION)->table('analytics_campaigns')->insert([
            'name' => 'Ramadan',
            'code' => 'rmd26',
            'destination_url' => 'https://shop.test/product/vitamin-c-1000',
            'utm_source' => 'instagram',
            'utm_medium' => 'social',
            'utm_campaign' => 'ramadan_2026',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->getJson('/api/v1/deep-link/resolve?url=' . urlencode('https://shop.test/go/rmd26'));

        $response->assertOk()
            ->assertJsonPath('target', 'product')
            ->assertJsonPath('parameter', 'vitamin-c-1000')
            ->assertJsonPath('attribution.utm_source', 'instagram')
            ->assertJsonPath('campaign.code', 'rmd26');

        $click = DB::connection(self::CONNECTION)->table('analytics_campaign_clicks')->first();
        $this->assertNotNull($click, 'an app open of a short link has to be counted');
        $this->assertSame('app', $click->surface);
    }

    public function test_a_foreign_url_is_refused(): void
    {
        $this->getJson('/api/v1/deep-link/resolve?url=' . urlencode('https://evil.example/product/x'))
            ->assertOk()
            ->assertJsonPath('resolved', false)
            ->assertJsonPath('target', 'web')
            // The foreign path is not reflected back: an app that opens web_url in a web view
            // would otherwise render the attacker's page inside the shop's own app.
            ->assertJsonPath('web_url', 'https://shop.test/')
            ->assertJsonPath('reason', 'that_link_does_not_belong_to_this_shop');
    }

    public function test_resolve_requires_a_url(): void
    {
        $this->getJson('/api/v1/deep-link/resolve')->assertStatus(422);
    }
}
