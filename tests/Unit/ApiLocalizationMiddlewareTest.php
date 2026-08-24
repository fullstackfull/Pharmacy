<?php

namespace Tests\Unit;

use App\Http\Middleware\APILocalizationMiddleware;
use Illuminate\Http\Request;
use Tests\TestCase;

class ApiLocalizationMiddlewareTest extends TestCase
{
    private function localeFor(string $lang): string
    {
        $request = Request::create('/api/v1/products/latest');
        $request->headers->set('lang', $lang);

        (new APILocalizationMiddleware())->handle($request, fn () => response('ok'));

        return app()->getLocale();
    }

    public function test_language_codes_pass_through(): void
    {
        $this->assertSame('ar', $this->localeFor('ar'));
        $this->assertSame('en', $this->localeFor('en'));
    }

    public function test_country_codes_from_old_app_builds_fold_into_their_language(): void
    {
        $this->assertSame('ar', $this->localeFor('sa'));
        $this->assertSame('en', $this->localeFor('US'));
    }
}
