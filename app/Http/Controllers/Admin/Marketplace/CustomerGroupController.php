<?php

namespace App\Http\Controllers\Admin\Marketplace;

use App\Http\Controllers\BaseController;
use App\Models\CustomerGroup;
use App\Models\CustomerGroupPrice;
use App\Services\Marketplace\B2BPricingService;
use App\Services\AuditLogger;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Admin customer groups & B2B pricing (Phase 3, Stage E). Manage groups, their members and per-product
 * prices, and preview the price a customer resolves to. The resolver is non-breaking — a customer in
 * no group keeps the base price.
 */
class CustomerGroupController extends BaseController
{
    public function __construct(
        private readonly B2BPricingService $pricing,
        private readonly AuditLogger $audit,
    ) {
    }

    public function index(Request|null $request, ?string $type = null): View
    {
        $groups = Schema::hasTable('customer_groups') ? CustomerGroup::orderBy('name')->get() : collect();

        $selected = null;
        $members = collect();
        $prices = collect();
        if ($request?->filled('group_id') && Schema::hasTable('customer_groups')) {
            $selected = CustomerGroup::find((int) $request->input('group_id'));
            if ($selected) {
                $members = DB::table('customer_group_customer')->where('customer_group_id', $selected->id)->pluck('customer_id');
                $prices = CustomerGroupPrice::where('customer_group_id', $selected->id)->get();
            }
        }

        // Optional price preview.
        $preview = null;
        if ($request?->filled('preview_product_id')) {
            $preview = [
                'input' => $request->only('preview_product_id', 'preview_customer_id', 'preview_base'),
                'result' => $this->pricing->priceFor(
                    (int) $request->input('preview_product_id'),
                    $request->input('preview_customer_id') ? (int) $request->input('preview_customer_id') : null,
                    (float) $request->input('preview_base', 0),
                ),
            ];
        }

        return view('admin-views.marketplace.customer-groups', compact('groups', 'selected', 'members', 'prices', 'preview'));
    }

    public function store(Request $request): RedirectResponse
    {
        $v = $request->validate([
            'name' => 'required|string|max:191',
            'type' => 'nullable|string|max:40',
            'default_discount_percent' => 'nullable|numeric|min:0|max:100',
        ]);
        $group = CustomerGroup::create($v + ['created_by' => auth('admin')->id()]);
        $this->audit->record(action: 'b2b.group_created', subject: ['type' => 'customer_group', 'id' => $group->id], after: ['name' => $group->name]);
        ToastMagic::success(translate('customer_group_created'));

        return back();
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $group = CustomerGroup::findOrFail($id);
        $group->update($request->validate([
            'name' => 'required|string|max:191',
            'type' => 'nullable|string|max:40',
            'default_discount_percent' => 'nullable|numeric|min:0|max:100',
            'status' => 'nullable|in:active,inactive',
        ]));
        ToastMagic::success(translate('customer_group_updated'));

        return back();
    }

    public function destroy(int $id): RedirectResponse
    {
        $group = CustomerGroup::findOrFail($id);
        DB::table('customer_group_customer')->where('customer_group_id', $id)->delete();
        CustomerGroupPrice::where('customer_group_id', $id)->delete();
        $group->delete();
        ToastMagic::success(translate('customer_group_deleted'));

        return back();
    }

    public function addMember(Request $request, int $id): RedirectResponse
    {
        CustomerGroup::findOrFail($id);
        $v = $request->validate(['customer_id' => 'required|integer']);

        DB::table('customer_group_customer')->updateOrInsert(
            ['customer_group_id' => $id, 'customer_id' => $v['customer_id']],
            ['updated_at' => now(), 'created_at' => now()],
        );
        ToastMagic::success(translate('member_added'));

        return back();
    }

    public function removeMember(Request $request, int $id): RedirectResponse
    {
        $v = $request->validate(['customer_id' => 'required|integer']);
        DB::table('customer_group_customer')->where(['customer_group_id' => $id, 'customer_id' => $v['customer_id']])->delete();
        ToastMagic::success(translate('member_removed'));

        return back();
    }

    public function setPrice(Request $request, int $id): RedirectResponse
    {
        CustomerGroup::findOrFail($id);
        $v = $request->validate([
            'product_id' => 'required|integer',
            'price' => 'nullable|numeric|min:0',
            'discount_percent' => 'nullable|numeric|min:0|max:100',
        ]);

        // Absent nullable fields are omitted from the validated array, so coalesce before comparing.
        $price = $v['price'] ?? null;
        $discount = $v['discount_percent'] ?? null;

        CustomerGroupPrice::updateOrCreate(
            ['customer_group_id' => $id, 'product_id' => $v['product_id']],
            [
                'price' => ($price === null || $price === '') ? null : $price,
                'discount_percent' => ($discount === null || $discount === '') ? null : $discount,
            ],
        );
        ToastMagic::success(translate('price_saved'));

        return back();
    }

    public function removePrice(int $id, int $priceId): RedirectResponse
    {
        CustomerGroupPrice::where(['id' => $priceId, 'customer_group_id' => $id])->delete();
        ToastMagic::success(translate('price_removed'));

        return back();
    }
}
