<?php

namespace Tests\Unit;

use App\Services\Security\OutboundUrlGuard;
use PHPUnit\Framework\TestCase;

/**
 * SSRF guard for the image proxy. The headline case is cloud instance metadata
 * (169.254.169.254), which hands out IAM credentials to anything that can make the request from
 * inside the network.
 */
class OutboundUrlGuardTest extends TestCase
{
    private OutboundUrlGuard $guard;

    protected function setUp(): void
    {
        $this->guard = new OutboundUrlGuard();
    }

    // ---- destination checks (no DNS needed: literal IPs) ----

    public function test_blocks_cloud_metadata_endpoint(): void
    {
        $r = $this->guard->check('http://169.254.169.254/latest/meta-data/iam/security-credentials/');

        $this->assertFalse($r['allowed']);
        $this->assertSame('destination_is_private_or_reserved', $r['reason']);
    }

    public function test_blocks_loopback(): void
    {
        $this->assertFalse($this->guard->check('http://127.0.0.1/admin')['allowed']);
        $this->assertFalse($this->guard->check('http://127.0.0.1:3306/')['allowed']);
    }

    public function test_blocks_private_ranges(): void
    {
        foreach (['http://10.0.0.5/', 'http://192.168.1.1/', 'http://172.16.0.9/'] as $url) {
            $this->assertFalse($this->guard->check($url)['allowed'], $url);
        }
    }

    public function test_blocks_cgnat_and_reserved(): void
    {
        $this->assertFalse($this->guard->check('http://100.64.0.1/')['allowed']);
        $this->assertFalse($this->guard->check('http://0.0.0.0/')['allowed']);
    }

    public function test_allows_a_public_ip(): void
    {
        $this->assertTrue($this->guard->check('https://1.1.1.1/logo.png')['allowed']);
    }

    // ---- scheme / format checks ----

    public function test_blocks_non_http_schemes(): void
    {
        foreach (['file:///etc/passwd', 'gopher://x/', 'dict://x:11/', 'ftp://x/'] as $url) {
            $r = $this->guard->check($url);
            $this->assertFalse($r['allowed'], $url);
        }
    }

    public function test_blocks_credentials_in_url(): void
    {
        // parser-confusion trick: the real host can differ from what a human reads
        $r = $this->guard->check('http://expected.test@169.254.169.254/');
        $this->assertFalse($r['allowed']);
    }

    public function test_blocks_empty_and_malformed(): void
    {
        $this->assertFalse($this->guard->check(null)['allowed']);
        $this->assertFalse($this->guard->check('')['allowed']);
        $this->assertFalse($this->guard->check('not a url')['allowed']);
    }

    // ---- IPv6 ----

    public function test_blocks_ipv6_loopback_and_unique_local(): void
    {
        $this->assertFalse($this->guard->isPublicIp('::1'));
        $this->assertFalse($this->guard->isPublicIp('fd00::1'));
        $this->assertFalse($this->guard->isPublicIp('fe80::1'));
    }

    public function test_blocks_ipv4_mapped_ipv6_pointing_at_metadata(): void
    {
        // ::ffff:169.254.169.254 must be judged on the embedded IPv4 address
        $this->assertFalse($this->guard->isPublicIp('::ffff:169.254.169.254'));
    }

    public function test_allows_public_ipv6(): void
    {
        $this->assertTrue($this->guard->isPublicIp('2606:4700:4700::1111'));
    }
}
