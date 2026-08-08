<?php

namespace Tests\Feature;

use App\Http\Middleware\ApplySeoRedirects;
use App\Services\Seo\RedirectResolverService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Tests\TestCase;

/**
 * Boots the app (so redirect()/container helpers work) but stubs data access, so the middleware's
 * behavior is verified without the absent core database schema.
 */
class ApplySeoRedirectsTest extends TestCase
{
    /** @param array<int, array<string, mixed>> $rules */
    private function middleware(array $rules): ApplySeoRedirects
    {
        return new class(new RedirectResolverService(), $rules) extends ApplySeoRedirects {
            /** @param array<int, array<string, mixed>> $stubRules */
            public function __construct(RedirectResolverService $resolver, private array $stubRules)
            {
                parent::__construct($resolver);
            }

            protected function loadRules(): array
            {
                return $this->stubRules;
            }

            protected function recordHit(string $path): void
            {
                // no DB in tests
            }
        };
    }

    private function next(): Closure
    {
        return fn ($request) => response('OK', 200);
    }

    public function test_redirects_on_exact_match(): void
    {
        $response = $this->middleware([
            ['from_path' => '/old', 'to_path' => '/new', 'status_code' => 301, 'match_type' => 'exact', 'is_active' => true],
        ])->handle(Request::create('/old', 'GET'), $this->next());

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame(301, $response->getStatusCode());
        $this->assertStringEndsWith('/new', $response->getTargetUrl());
    }

    public function test_passes_through_when_no_rule_matches(): void
    {
        $response = $this->middleware([
            ['from_path' => '/old', 'to_path' => '/new', 'is_active' => true],
        ])->handle(Request::create('/something-else', 'GET'), $this->next());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('OK', $response->getContent());
    }

    public function test_never_redirects_panel_or_api_paths(): void
    {
        $admin = $this->middleware([['from_path' => '/admin/x', 'to_path' => '/y', 'is_active' => true]])
            ->handle(Request::create('/admin/x', 'GET'), $this->next());
        $this->assertSame(200, $admin->getStatusCode());

        $api = $this->middleware([['from_path' => '/api/v1/thing', 'to_path' => '/z', 'is_active' => true]])
            ->handle(Request::create('/api/v1/thing', 'GET'), $this->next());
        $this->assertSame(200, $api->getStatusCode());
    }

    public function test_no_active_rules_is_a_noop(): void
    {
        $response = $this->middleware([])->handle(Request::create('/anything', 'GET'), $this->next());
        $this->assertSame(200, $response->getStatusCode());
    }
}
