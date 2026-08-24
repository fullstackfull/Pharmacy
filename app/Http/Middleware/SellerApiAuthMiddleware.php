<?php

namespace App\Http\Middleware;

use App\Services\Marketplace\SellerPermissionService;
use App\Services\Marketplace\SellerPrincipal;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class SellerApiAuthMiddleware
{
    /** The seller middleware rejects anything shorter than this, so a malformed header never queries. */
    private const MINIMUM_TOKEN_LENGTH = 30;

    /** Where the resolved principal lives on the request, for anything downstream that needs it. */
    public const PRINCIPAL = 'seller_principal';

    public function __construct(private readonly SellerPermissionService $permissions)
    {
    }

    /**
     * Resolve the bearer token into who is acting, and on whose shop.
     *
     * `$request->seller` is still the shop, exactly as before — every controller that scopes on
     * `$request->seller->id` keeps meaning what it meant, whether the caller is the owner or one of
     * their staff. `$request->seller_principal` is the new part: it also names the person, and what
     * that person is allowed to do.
     *
     * Handle an incoming request.
     *
     * @param Request $request
     * @param Closure(Request): (Response|RedirectResponse) $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next): mixed
    {
        $token = explode(' ', (string) $request->header('authorization'));

        if (count($token) > 1 && strlen($token[1]) > self::MINIMUM_TOKEN_LENGTH) {
            // Status is checked here, not only at login. It used to be enforced on the way in and
            // never again, so a vendor rejected or set back to pending after signing in kept full
            // API access — orders, POS, payouts, staff — until someone happened to press suspend.
            // What login refuses to start, this refuses to continue. The same holds for a staff
            // member whose account or role has since been switched off.
            $principal = $this->permissions->principalFor($token[1]);

            if ($principal instanceof SellerPrincipal) {
                $request['seller'] = $principal->seller;
                // On the request's attribute bag, not its input bag: the input bag holds what the
                // caller sent and accepts only scalars and arrays. Attributes are where the
                // framework itself keeps per-request objects, and a caller cannot forge one.
                $request->attributes->set(self::PRINCIPAL, $principal);

                return $next($request);
            }
        }

        return response()->json([
            'auth-001' => translate('Your existing session token does not authorize you any more')
        ], 401);
    }
}
