<?php

namespace Tests\Unit;

use App\Services\Seo\RedirectResolverService;
use PHPUnit\Framework\TestCase;

class RedirectResolverTest extends TestCase
{
    private RedirectResolverService $svc;

    protected function setUp(): void
    {
        $this->svc = new RedirectResolverService();
    }

    public function test_exact_match_returns_target_and_status(): void
    {
        $rules = [['from_path' => '/old', 'to_path' => '/new', 'status_code' => 301, 'match_type' => 'exact', 'is_active' => true]];
        $this->assertSame(['to' => '/new', 'status' => 301], $this->svc->resolve('/old', $rules));
    }

    public function test_no_match_returns_null(): void
    {
        $rules = [['from_path' => '/old', 'to_path' => '/new', 'is_active' => true]];
        $this->assertNull($this->svc->resolve('/other', $rules));
    }

    public function test_trailing_slash_and_query_are_normalized(): void
    {
        $rules = [['from_path' => '/old', 'to_path' => '/new', 'is_active' => true]];
        $this->assertSame(['to' => '/new', 'status' => 301], $this->svc->resolve('/old/?ref=x', $rules));
    }

    public function test_inactive_rule_is_ignored(): void
    {
        $rules = [['from_path' => '/old', 'to_path' => '/new', 'is_active' => false]];
        $this->assertNull($this->svc->resolve('/old', $rules));
    }

    public function test_exact_takes_precedence_over_prefix(): void
    {
        $rules = [
            ['from_path' => '/shop', 'to_path' => '/store', 'match_type' => 'prefix', 'is_active' => true],
            ['from_path' => '/shop', 'to_path' => '/exact', 'match_type' => 'exact', 'is_active' => true],
        ];
        $this->assertSame('/exact', $this->svc->resolve('/shop', $rules)['to']);
    }

    public function test_longest_prefix_wins(): void
    {
        $rules = [
            ['from_path' => '/a', 'to_path' => '/short', 'match_type' => 'prefix', 'is_active' => true],
            ['from_path' => '/a/b', 'to_path' => '/long', 'match_type' => 'prefix', 'is_active' => true],
        ];
        $this->assertSame('/long', $this->svc->resolve('/a/b/c', $rules)['to']);
    }

    public function test_prefix_respects_segment_boundary(): void
    {
        // "/apple" must NOT match a "/a" prefix rule.
        $rules = [['from_path' => '/a', 'to_path' => '/x', 'match_type' => 'prefix', 'is_active' => true]];
        $this->assertNull($this->svc->resolve('/apple', $rules));
    }

    public function test_self_referential_rule_is_skipped(): void
    {
        $rules = [['from_path' => '/loop', 'to_path' => '/loop/', 'is_active' => true]];
        $this->assertNull($this->svc->resolve('/loop', $rules));
    }

    public function test_invalid_status_falls_back_to_301(): void
    {
        $rules = [['from_path' => '/o', 'to_path' => '/n', 'status_code' => 999, 'is_active' => true]];
        $this->assertSame(301, $this->svc->resolve('/o', $rules)['status']);
    }

    public function test_302_is_preserved(): void
    {
        $rules = [['from_path' => '/o', 'to_path' => '/n', 'status_code' => 302, 'is_active' => true]];
        $this->assertSame(302, $this->svc->resolve('/o', $rules)['status']);
    }

    public function test_absolute_url_target_is_allowed(): void
    {
        $rules = [['from_path' => '/go', 'to_path' => 'https://example.com/x', 'is_active' => true]];
        $this->assertSame('https://example.com/x', $this->svc->resolve('/go', $rules)['to']);
    }
}
