<?php

namespace App\Http\Middleware;

use App\Utils\Helpers;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

class APILocalizationMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param Request $request
     * @param Closure $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next): mixed
    {
        $requested = strtolower((string) $request->header('lang'));
        // Customer-app builds in the field sent the COUNTRY code here ('sa'/'us'); everything
        // localized keys on the language code, so those aliases are folded in rather than
        // letting an installed app fall out of its own language.
        $requested = ['sa' => 'ar', 'us' => 'en'][$requested] ?? $requested;
        $local = $requested !== '' ? $requested : Helpers::default_lang();
        App::setLocale($local);
        return $next($request);
    }
}
