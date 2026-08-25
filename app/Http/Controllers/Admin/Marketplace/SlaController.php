<?php

namespace App\Http\Controllers\Admin\Marketplace;

use App\Http\Controllers\BaseController;
use App\Models\BusinessSetting;
use App\Models\Seller;
use App\Models\SellerSlaBreach;
use App\Services\Marketplace\OperationsPolicy;
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
    public function __construct(
        private readonly SlaService $sla,
        private readonly OperationsPolicy $operations,
    )
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
            // The windows the detectors judge by, on the same page as the rates they sit beside —
            // they are the same policy, and the seller feels them the same way.
            'operations' => $this->operations->all(),
            'operationLimits' => OperationsPolicy::LIMITS,
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
            // Whole hours, and at least one: a zero-hour deadline would mark every order late the
            // instant it arrived.
            'sla_processing_hours' => 'required|integer|min:1|max:720',
        ] + $this->operationRules());

        foreach ($validated as $type => $value) {
            BusinessSetting::updateOrCreate(['type' => $type], ['value' => (string) $value]);
        }
        cache()->flush();
        ToastMagic::success(translate('sla_policy_updated'));

        return back();
    }

    /**
     * Validation for the operations windows, built from the policy's own limits.
     *
     * Read from the service rather than repeated here: the bounds exist because each value drives a
     * deadline, and a form that accepted what the policy clamps would silently save one number and
     * apply another.
     *
     * @return array<string, string>
     */
    private function operationRules(): array
    {
        $rules = [];

        foreach (OperationsPolicy::LIMITS as $key => $limits) {
            $numeric = is_float(OperationsPolicy::DEFAULTS[$key]) ? 'numeric' : 'integer';
            $rules[$key] = "required|{$numeric}|min:{$limits['min']}|max:{$limits['max']}";
        }

        return $rules;
    }

    public function evaluate(): RedirectResponse
    {
        $evaluated = $this->sla->evaluateAll();
        $open = Schema::hasTable('seller_sla_breaches') ? SellerSlaBreach::open()->count() : 0;
        ToastMagic::success(translate('evaluated') . ' ' . $evaluated . ' ' . translate('sellers') . ' — ' . $open . ' ' . translate('open_breaches'));

        return back();
    }
}
