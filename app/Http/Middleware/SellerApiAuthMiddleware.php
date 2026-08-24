<?php

namespace App\Http\Middleware;

use App\Models\Seller;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class SellerApiAuthMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param Request $request
     * @param Closure(Request): (Response|RedirectResponse) $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next): mixed
    {
        $token = explode(' ', (string) $request->header('authorization'));
        if (count($token) > 1 && strlen($token[1]) > 30) {
            // Status is checked here, not only at login. It used to be enforced
            // on the way in and never again, so a vendor rejected or set back to
            // pending after signing in kept full API access — orders, POS,
            // payouts, staff — until someone happened to press suspend. What
            // login refuses to start, this refuses to continue.
            $seller = Seller::approved()->where(['auth_token' => $token['1']])->first();
            if (isset($seller)) {
                $request['seller'] = $seller;
                return $next($request);
            }
        }

        return response()->json([
            'auth-001' => translate('Your existing session token does not authorize you any more')
        ], 401);
    }
}
