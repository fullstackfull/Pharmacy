<?php

namespace Tests\Feature\Analytics;

use App\Services\Analytics\Support\PrivacyGate;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Two privacy switches that did nothing.
 *
 * config/analytics.php has declared respect_do_not_track and require_consent since the pipeline
 * was built, and nothing read either — so a shop that had turned them on believed it was honouring
 * a signal it had never looked at. These are the cases that setting must now change.
 */
class PrivacyGateTest extends TestCase
{
    private function gate(): PrivacyGate
    {
        return new PrivacyGate();
    }

    private function request(array $headers = [], array $cookies = []): Request
    {
        $request = Request::create('/', 'GET');

        foreach ($headers as $name => $value) {
            $request->headers->set($name, $value);
        }

        foreach ($cookies as $name => $value) {
            $request->cookies->set($name, $value);
        }

        return $request;
    }

    public function test_by_default_everything_is_measured(): void
    {
        // Both switches are off out of the box, which is what every existing installation has.
        $this->assertTrue($this->gate()->allows($this->request(['DNT' => '1'])));
    }

    public function test_do_not_track_is_honoured_when_the_shop_asks_for_it(): void
    {
        config()->set('analytics.privacy.respect_do_not_track', true);

        $this->assertFalse($this->gate()->allows($this->request(['DNT' => '1'])));
        $this->assertSame('do_not_track', $this->gate()->reason($this->request(['DNT' => '1'])));
        $this->assertTrue($this->gate()->allows($this->request()));
    }

    public function test_global_privacy_control_counts_as_do_not_track(): void
    {
        // Sec-GPC is the header that carries legal weight in several jurisdictions; DNT is the one
        // browsers shipped first. They mean the same thing to this shop.
        config()->set('analytics.privacy.respect_do_not_track', true);

        $this->assertFalse($this->gate()->allows($this->request(['Sec-GPC' => '1'])));
    }

    public function test_consent_means_an_explicit_acceptance(): void
    {
        config()->set('analytics.privacy.require_consent', true);

        $this->assertFalse($this->gate()->allows($this->request()), 'not asked yet is not consent');
        $this->assertFalse(
            $this->gate()->allows($this->request(cookies: [PrivacyGate::CONSENT_COOKIE => 'reject'])),
            'declined is not consent',
        );
        $this->assertTrue(
            $this->gate()->allows($this->request(cookies: [PrivacyGate::CONSENT_COOKIE => 'accepted'])),
        );
    }

    public function test_the_cookie_it_reads_is_the_one_the_storefront_writes(): void
    {
        // The banner in the default theme writes this name; a gate reading a different one would
        // silently refuse every visit on a shop that had turned consent on.
        $this->assertStringContainsString(
            "'" . PrivacyGate::CONSENT_COOKIE . "=accepted",
            str_replace('"', "'", file_get_contents(resource_path('themes/default/layouts/front-end/app.blade.php'))),
        );
    }
}
