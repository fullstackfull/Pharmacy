<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Contracts\Repositories\BusinessSettingRepositoryInterface;
use App\Http\Controllers\BaseController;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class OrderSettingsController extends BaseController
{

    public function __construct(
        private readonly BusinessSettingRepositoryInterface $businessSettingRepo,
    )
    {
    }

    /**
     * @param Request|null $request
     * @param string|null $type
     * @return View Index function is the starting point of a controller
     * Index function is the starting point of a controller
     */
    public function index(Request|null $request, ?string $type = null): View
    {
        return view('admin-views.business-settings.order-settings.index');
    }


    public function update(Request $request): RedirectResponse
    {
        $this->businessSettingRepo->updateOrInsert(type: 'billing_input_by_customer', value: $request->get('billing_input_by_customer', 0));
        $this->businessSettingRepo->updateOrInsert(type: 'minimum_order_amount_status', value: $request->get('minimum_order_amount_status', 0));
        $this->businessSettingRepo->updateOrInsert(type: 'order_verification', value: $request->get('order_verification', 0));
        $this->businessSettingRepo->updateOrInsert(type: 'free_delivery_status', value: $request->get('free_delivery_status', 0));
        $this->businessSettingRepo->updateOrInsert(type: 'free_delivery_responsibility', value: $request['free_delivery_responsibility']);
        $this->businessSettingRepo->updateOrInsert(type: 'guest_checkout', value: $request->get('guest_checkout', 0));
        $this->businessSettingRepo->updateOrInsert(type: 'free_delivery_over_amount_seller', value: currencyConverter(amount: $request['free_delivery_over_amount_seller']) ?? 0);
        // The checkout item floor. Seeded at install, shipped to all three mobile apps in
        // /api/v1/config and written by nothing — so the apps enforced a rule the operator could
        // neither see nor change. Clamped rather than trusted: a negative floor is not a rule, and
        // a floor in the thousands is a shop nobody can buy from.
        $this->businessSettingRepo->updateOrInsert(
            type: 'minimum_order_limit',
            value: max(0, min(1000, (int) $request->get('minimum_order_limit', 0))),
        );
        clearWebConfigCacheKeys();
        ToastMagic::success(translate('successfully_updated'));
        return back();
    }


}
