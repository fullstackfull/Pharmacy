<?php

namespace App\Services\SellerCenter\Lists;

use App\Models\Product;
use App\Models\ReturnShipment;
use App\Models\VendorLedgerEntry;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * The goods coming back, and what the refund did to the balance.
 *
 * A refund has always been visible to a seller as money. The units were not visible at all — so a
 * seller who refunded a customer lost the stock as well as the sale, and had no way to see that a
 * product was on its way back to them. This is the web half of what the phone app already shows.
 *
 * The two figures the screen leads with are chosen for what they change. "Awaiting arrival" is work
 * the seller is waiting on somebody else for; "received not restocked" is work waiting on them, and
 * it is the number that quietly costs money — every unit in it is stock the shop has paid for and
 * cannot sell.
 */
class ReturnList
{
    /** @var array<string, array<string, string>> */
    public const VIEWS = [
        'all' => ['label' => 'all', 'tone' => 'neutral'],
        'authorized' => ['label' => 'authorized', 'tone' => 'neutral'],
        'in_transit' => ['label' => 'in_transit', 'tone' => 'medium'],
        'received' => ['label' => 'received', 'tone' => 'high'],
        'restocked' => ['label' => 'restocked', 'tone' => 'ok'],
        'rejected' => ['label' => 'rejected', 'tone' => 'critical'],
    ];

    public function available(): bool
    {
        return Schema::hasTable('return_shipments');
    }

    public function view(Request $request): string
    {
        $view = (string) $request->query('view', 'all');

        return isset(self::VIEWS[$view]) ? $view : 'all';
    }

    public function paginate(int $sellerId, Request $request): LengthAwarePaginator
    {
        if (!$this->available()) {
            return new LengthAwarePaginator([], 0, $this->pageSize($request));
        }

        $query = ReturnShipment::where('seller_id', $sellerId);

        $view = $this->view($request);
        if ($view !== 'all') {
            $query->where('status', $view);
        }

        $search = trim((string) $request->query('q', ''));
        if ($search !== '') {
            $query->where(function ($where) use ($search) {
                $where->where('reference', 'like', $search . '%')
                    ->orWhere('tracking_number', 'like', $search . '%')
                    ->orWhere('order_id', $search);
            });
        }

        return $query->orderByDesc('id')->paginate($this->pageSize($request))->withQueryString();
    }

    /**
     * The stat strip. Every figure is a count of rows, never an estimate.
     *
     * @return array<string, int>
     */
    public function summary(int $sellerId): array
    {
        if (!$this->available()) {
            return ['open' => 0, 'in_transit' => 0, 'awaiting_decision' => 0, 'restocked' => 0, 'units_back' => 0];
        }

        $returns = ReturnShipment::where('seller_id', $sellerId);

        return [
            'open' => (clone $returns)->whereIn('status', ['authorized', 'in_transit', 'received'])->count(),
            'in_transit' => (clone $returns)->where('status', 'in_transit')->count(),
            // Arrived and nobody has said whether it can be sold again. This is the one that costs
            // money while it waits.
            'awaiting_decision' => (clone $returns)->where('status', 'received')->count(),
            'restocked' => (clone $returns)->where('status', 'restocked')->count(),
            'units_back' => (int) (clone $returns)->where('status', 'restocked')->sum('qty'),
        ];
    }

    /**
     * Product names for a page of returns, in one query.
     *
     * Scoped to the seller as well as the ids: a return row naming a product that is not theirs is
     * a bug, and resolving it anyway would print another shop's product name on this page.
     *
     * @param  array<int, int|string>  $productIds
     * @return array<int, string>
     */
    public function productNames(array $productIds, int $sellerId): array
    {
        $ids = array_values(array_filter(array_unique($productIds)));

        if ($ids === [] || !Schema::hasTable('products')) {
            return [];
        }

        return Product::withoutGlobalScope('translate')
            ->whereIn('id', $ids)
            ->where(['added_by' => 'seller', 'user_id' => $sellerId])
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * The ledger lines one refund produced.
     *
     * The credit giving back commission on the refunded portion is the line sellers never see and
     * most doubt exists, which is exactly why it is rendered beside the debit rather than summarised
     * into a net figure.
     *
     * @return Collection<int, VendorLedgerEntry>
     */
    public function ledgerFor(ReturnShipment $rma, int $sellerId): Collection
    {
        if (!Schema::hasTable('vendor_ledger_entries') || !$rma->order_details_id) {
            return collect();
        }

        // Keyed on the order line, which is what the reversal service posts against — the same
        // lookup the seller app makes, so the phone and the browser cannot disagree about what a
        // refund did to the balance.
        return VendorLedgerEntry::where('seller_id', $sellerId)
            ->where('reference_type', 'order_details')
            ->where('reference_id', $rma->order_details_id)
            ->whereIn('entry_type', [VendorLedgerEntry::TYPE_REFUND, VendorLedgerEntry::TYPE_COMMISSION_CHARGE])
            ->orderBy('id')
            ->get();
    }

    private function pageSize(Request $request): int
    {
        $size = (int) $request->query('size', 25);

        return in_array($size, [25, 50, 100], true) ? $size : 25;
    }
}
