<?php

namespace Tests\Feature\DeepLink;

use App\Services\Analytics\CampaignService;
use App\Services\DeepLink\DeepLinkResolver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The app asks the shop what a link means, and a campaign short link has to survive the question.
 *
 * The case this exists for: a customer taps /go/rmd26 on a phone with the app installed. The app
 * gets the URL, asks here, and must come back with the product screen AND the campaign attribution
 * — not a web view, and not an untagged product page, which is what happened when the short link
 * and the deep-link system did not know about each other.
 */
class DeepLinkResolverTest extends TestCase
{
    private const CONNECTION = 'deeplink_test';

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
    }

    private function campaign(string $code, string $destination): void
    {
        DB::connection(self::CONNECTION)->table('analytics_campaigns')->insert([
            'name' => 'Ramadan',
            'code' => $code,
            'destination_url' => $destination,
            'utm_source' => 'instagram',
            'utm_medium' => 'social',
            'utm_campaign' => 'ramadan_2026',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_a_product_url_resolves_to_the_product_screen(): void
    {
        $resolved = app(DeepLinkResolver::class)->resolve('https://shop.test/product/vitamin-c-1000');

        $this->assertTrue($resolved['resolved']);
        $this->assertSame('product', $resolved['target']);
        $this->assertSame('vitamin-c-1000', $resolved['parameter']);
    }

    public function test_a_brand_url_comes_back_with_the_id_the_app_screen_opens_with(): void
    {
        Schema::dropIfExists('brands');
        Schema::create('brands', function (Blueprint $table) {
            $table->id(); $table->string('name'); $table->string('slug')->nullable();
            $table->integer('status')->default(1); $table->timestamps();
        });
        // The Brand model's global scope eager-loads translations for the viewer's locale.
        Schema::dropIfExists('translations');
        Schema::create('translations', function (Blueprint $table) {
            $table->id(); $table->string('translationable_type'); $table->unsignedBigInteger('translationable_id');
            $table->string('locale'); $table->string('key')->nullable(); $table->text('value')->nullable();
            $table->timestamps();
        });
        session(['local' => 'en']);
        DB::table('brands')->insert([
            ['name' => 'Panadol', 'slug' => 'panadol', 'status' => 1],
            ['name' => 'Hidden', 'slug' => 'hidden', 'status' => 0],
        ]);

        $resolved = app(DeepLinkResolver::class)->resolve('https://shop.test/brand/panadol');

        $this->assertSame('brand', $resolved['target']);
        $this->assertSame('panadol', $resolved['parameter']);
        $this->assertSame(['id' => 1, 'name' => 'Panadol'], $resolved['subject']);

        $inactive = app(DeepLinkResolver::class)->resolve('https://shop.test/brand/hidden');
        $this->assertNull($inactive['subject'], 'an inactive brand must not be handed to the app');

        $unknown = app(DeepLinkResolver::class)->resolve('https://shop.test/brand/nope');
        $this->assertNull($unknown['subject']);
    }

    public function test_a_brand_link_still_resolves_when_the_lookup_cannot_run(): void
    {
        Schema::dropIfExists('brands');

        $resolved = app(DeepLinkResolver::class)->resolve('https://shop.test/brand/panadol');

        $this->assertSame('brand', $resolved['target']);
        $this->assertSame('panadol', $resolved['parameter']);
        $this->assertNull($resolved['subject']);
    }

    public function test_a_campaign_short_link_resolves_to_its_destination_with_its_attribution(): void
    {
        $this->campaign('rmd26', 'https://shop.test/product/vitamin-c-1000');

        $resolved = app(DeepLinkResolver::class)->resolve('https://shop.test/go/rmd26');

        $this->assertSame('product', $resolved['target']);
        $this->assertSame('vitamin-c-1000', $resolved['parameter']);
        $this->assertSame('instagram', $resolved['attribution']['utm_source']);
        $this->assertSame('ramadan_2026', $resolved['attribution']['utm_campaign']);
        $this->assertSame('rmd26', $resolved['attribution']['campaign_code']);
        $this->assertSame('rmd26', $resolved['campaign']['code']);
        $this->assertStringContainsString('utm_source=instagram', $resolved['web_url']);
    }

    public function test_a_code_typed_in_capitals_still_resolves(): void
    {
        $this->campaign('rmd26', 'https://shop.test/product/vitamin-c-1000');

        $resolved = app(DeepLinkResolver::class)->resolve('https://shop.test/go/RMD26');

        $this->assertSame('product', $resolved['target']);
        $this->assertSame('rmd26', $resolved['campaign']['code']);
    }

    public function test_an_unknown_code_lands_on_the_home_screen_rather_than_an_error(): void
    {
        $resolved = app(DeepLinkResolver::class)->resolve('https://shop.test/go/nope');

        $this->assertSame('home', $resolved['target']);
        $this->assertNull($resolved['campaign']);
        $this->assertSame('unknown_code', $resolved['reason']);
    }

    public function test_a_paused_campaign_does_not_resolve(): void
    {
        $this->campaign('paused', 'https://shop.test/product/x');
        DB::connection(self::CONNECTION)->table('analytics_campaigns')->update(['is_active' => false]);

        $resolved = app(DeepLinkResolver::class)->resolve('https://shop.test/go/PAUSED');

        $this->assertSame('home', $resolved['target']);
    }

    public function test_a_link_on_another_host_is_refused_not_followed(): void
    {
        $resolved = app(DeepLinkResolver::class)->resolve('https://evil.example/product/x');

        $this->assertFalse($resolved['resolved']);
        $this->assertSame('web', $resolved['target']);
        $this->assertSame('that_link_does_not_belong_to_this_shop', $resolved['reason']);
    }

    public function test_a_shop_path_that_is_not_published_comes_back_as_a_web_view(): void
    {
        $resolved = app(DeepLinkResolver::class)->resolve('https://shop.test/checkout');

        $this->assertFalse($resolved['resolved']);
        $this->assertSame('web', $resolved['target']);
        $this->assertSame('this_path_is_not_published_as_an_app_link', $resolved['reason']);
    }

    public function test_an_app_open_of_a_short_link_is_counted_as_a_click(): void
    {
        $this->campaign('rmd26', 'https://shop.test/product/x');

        $resolved = app(CampaignService::class)->resolve('rmd26');
        $this->assertTrue($resolved['ok'], 'the campaign should resolve: ' . ($resolved['reason'] ?? ''));
        $campaign = $resolved['campaign'];
        app(CampaignService::class)->recordClick($campaign, ['surface' => 'app', 'device' => 'mobile']);

        $row = DB::connection(self::CONNECTION)->table('analytics_campaign_clicks')->first();
        $this->assertSame(1, (int) DB::connection(self::CONNECTION)->table('analytics_campaigns')->value('clicks'));
        $this->assertNotNull($row);
    }
}
