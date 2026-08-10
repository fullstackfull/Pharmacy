<?php

namespace App\Http\Controllers\Admin\Marketplace;

use App\Http\Controllers\BaseController;
use App\Models\BusinessSetting;
use App\Models\Seller;
use App\Models\SellerSlaBreach;
use App\Services\Marketplace\SlaService;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * Admin seller SLA policy (Phase 3, Stage E). Configure the thresholds, run an evaluation across
 * sellers, and read the breach ledger. The comparison and reconcile live in SlaService.
 */
class SlaController extends BaseController
{
    public function __construct(private readonly SlaService $sla)
    {
    }

    public function index(Request|null $request, ?string $type = null): View
    {
        $breaches = collect();
        $paginator = null;
        $sellers = collect();

        if (Schema::hasTable('seller_sla_breaches')) {
            $query = SellerSlaBreach::query()->orderByRaw("status = 'open' desc")->orderByDesc('id');
            if ($request?->input('status')) {
                $query->where('status', $request->input('status'));
            }
            $paginator = $query->paginate(25)->withQueryString();
            $breaches = collect($paginator->items());

            $sellerIds = $breaches->pluck('seller_id')->unique();
            if (Schema::hasTable('sellers') && $sellerIds->isNotEmpty()) {
                $sellers = Seller::whereIn('id', $sellerIds)->get()->keyBy('id');
            }
        }

        return view('admin-views.marketplace.sla', [
            'thresholds' => $this->sla->thresholds(),
            'breaches' => $breaches,
            'paginator' => $paginator,
            'sellers' => $sellers,
            'statusFilter' => $request?->input('status'),
            'openCount' => Schema::hasTable('seller_sla_breaches') ? SellerSlaBreach::open()->count() : 0,
        ]);
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'sla_max_cancellation_rate' => 'required|numeric|min:0|max:1',
            'sla_max_return_rate' => 'required|numeric|min:0|max:1',
            'sla_max_refund_rate' => 'required|numeric|min:0|max:1',
            'sla_min_rating' => 'required|numeric|min:0|max:5',
        ]);

        foreach ($validated as $type => $value) {
            BusinessSetting::updateOrCreate(['type' => $type], ['value' => (string) $value]);
        }
        cache()->flush();
        ToastMagic::success(translate('sla_policy_updated'));

        return back();
    }

    public function evaluate(): RedirectResponse
    {
        $evaluated = $this->sla->evaluateAll();
        $open = Schema::hasTable('seller_sla_breaches') ? SellerSlaBreach::open()->count() : 0;
        ToastMagic::success(translate('evaluated') . ' ' . $evaluated . ' ' . translate('sellers') . ' — ' . $open . ' ' . translate('open_breaches'));

        return back();
    }
}
