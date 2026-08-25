<?php

namespace App\Http\Controllers\Seller;

use App\Models\BrandClaim;
use App\Services\Marketplace\BrandRegistryService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * Which brands this shop is allowed to sell, and which of its listings depend on that.
 *
 * The registry has recorded claims, documents and decisions since Wave 3C and the seller could see
 * none of it on the web: two navigation entries pointed at a route nobody wrote. The consequence is
 * specific rather than cosmetic — an authorisation that lapses takes listings down, and a seller who
 * cannot see the expiry date finds out when the listings go.
 *
 * Brand protection is the same registry read from the other side: how many of this shop's listings
 * sit under each brand, and therefore what a revocation would cost.
 */
class BrandController extends SellerCenterController
{
    public function __construct(private readonly BrandRegistryService $brands)
    {
    }

    public function index(Request $request): View
    {
        $sellerId = $this->sellerId($request);
        $available = Schema::hasTable('brand_claims');
        $claims = $available ? $this->brands->claimsFor($sellerId) : collect();
        $view = $this->view($request);

        return view('seller-views.brands.index', [
            'claims' => $view === 'authorization'
                ? $claims->filter(fn (BrandClaim $claim) => $claim->status === BrandClaim::STATUS_APPROVED)
                : $claims,
            'currentView' => $view,
            'enforcing' => $this->brands->isEnforcing(),
            'available' => $available,
            'state' => $this->listState($claims->count(), $view !== 'all'),
        ]);
    }

    /**
     * What a revocation would cost, counted in listings rather than described in the abstract.
     */
    public function protection(Request $request): View
    {
        $sellerId = $this->sellerId($request);

        return view('seller-views.brands.protection', [
            'exposure' => Schema::hasTable('brand_claims') ? $this->brands->brandExposure($sellerId) : [],
            'enforcing' => $this->brands->isEnforcing(),
            'available' => Schema::hasTable('brand_claims'),
        ]);
    }

    private function view(Request $request): string
    {
        $view = (string) $request->query('view', 'all');

        return in_array($view, ['all', 'authorization'], true) ? $view : 'all';
    }
}
