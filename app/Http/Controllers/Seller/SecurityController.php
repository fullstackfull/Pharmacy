<?php

namespace App\Http\Controllers\Seller;

use App\Models\SellerApiKey;
use App\Services\Marketplace\SellerAuditTrailService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * Who can act as this shop right now, and what has been done in its name.
 *
 * Two questions a seller can only answer by reading three tables, so nobody ever answers them.
 * Access is read from the credentials themselves rather than from a session store: the owner holds
 * a token, each staff member holds their own, and each API key is a third kind of door. "Has a live
 * credential" is the honest answer to who can act as this shop — a list of accounts is not.
 *
 * The trail is filterable by action prefix because that is how it is actually read: somebody asks
 * "who changed the automation rules", not "show me everything". Somebody who has since left still
 * appears in the history of what they did, which is precisely when a seller wants to look.
 */
class SecurityController extends SellerCenterController
{
    /** The prefixes worth offering as one-click filters — the areas people actually ask about. */
    private const TRAIL_FILTERS = ['seller.staff', 'seller.automation', 'integration', 'payout', 'product'];

    private const TRAIL_LIMIT = 100;

    public function __construct(private readonly SellerAuditTrailService $trail)
    {
    }

    public function index(Request $request): View
    {
        $principal = $this->principal($request);
        $sellerId = $principal->sellerId();
        $seller = $principal->seller;
        $action = $this->action($request);

        $holders = $this->trail->accessHolders(
            sellerId: $sellerId,
            ownerName: trim("{$seller->f_name} {$seller->l_name}"),
            ownerHasToken: !empty($seller->auth_token),
        );

        $trail = $this->trail->recent(sellerId: $sellerId, limit: self::TRAIL_LIMIT, action: $action);

        return view('seller-views.security.index', [
            'holders' => $holders,
            'keys' => $this->liveKeys($sellerId),
            'entries' => $trail['entries'],
            'total' => $trail['total'],
            'filters' => self::TRAIL_FILTERS,
            'currentFilter' => $action,
            'state' => $this->listState(count($trail['entries']), $action !== null),
        ]);
    }

    /**
     * Keys are a door into the shop and belong in the access review beside the people.
     *
     * Revoked and expired keys are left out on purpose: this list answers "who can act right now",
     * and a key that cannot act is not an answer to it.
     */
    private function liveKeys(int $sellerId)
    {
        if (!Schema::hasTable('seller_api_keys')) {
            return collect();
        }

        return SellerApiKey::where('seller_id', $sellerId)
            ->whereNull('revoked_at')
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->orderByDesc('id')
            ->get();
    }

    private function action(Request $request): ?string
    {
        $action = $request->query('action');

        return is_string($action) && in_array($action, self::TRAIL_FILTERS, true) ? $action : null;
    }
}
