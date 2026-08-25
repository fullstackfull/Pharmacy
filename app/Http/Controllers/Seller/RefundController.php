<?php

namespace App\Http\Controllers\Seller;

use App\Models\RefundRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * Money going back to customers, from this shop's side of it.
 *
 * The refund queue itself belongs to the marketplace — a seller cannot approve their own refund, and
 * this screen deliberately offers no button that pretends otherwise. What a seller needs is the
 * thing the classic panel never gave them: which of their orders are being refunded, for how much,
 * how far each one has got, and whether the goods are coming back.
 *
 * Read-only on purpose. A screen that lists a decision somebody else makes and shows no control is
 * honest; one that shows a disabled button is an argument with the reader.
 */
class RefundController extends SellerCenterController
{
    /** The statuses a refund request moves through, in the order it moves through them. */
    private const STATUSES = ['pending', 'approved', 'refunded', 'rejected'];

    public function index(Request $request): View
    {
        $sellerId = $this->sellerId($request);
        $available = Schema::hasTable('refund_requests');

        $refunds = $available
            ? $this->query($sellerId, $request)->paginate($this->pageSize($request))->withQueryString()
            : new \Illuminate\Pagination\LengthAwarePaginator([], 0, $this->pageSize($request));

        return view('seller-views.refunds.index', [
            'refunds' => $refunds,
            'summary' => $available ? $this->summary($sellerId) : array_fill_keys(['pending', 'approved', 'refunded', 'value'], 0),
            'statuses' => self::STATUSES,
            'status' => $this->status($request),
            'available' => $available,
            'state' => $this->listState($refunds->total(), $this->status($request) !== null || $request->query('q')),
        ]);
    }

    private function query(int $sellerId, Request $request)
    {
        $search = trim((string) $request->query('q', ''));

        return RefundRequest::with(['product', 'order:id,payment_method'])
            // Scoped through the order, which is where the seller lives: a refund row carries the
            // customer and the line, never the shop.
            ->whereHas('order', fn ($query) => $query->where(['seller_is' => 'seller', 'seller_id' => $sellerId]))
            ->when($this->status($request), fn ($query, $status) => $query->where('status', $status))
            ->when($search !== '', fn ($query) => $query->where('order_id', 'like', $search . '%'))
            ->orderByDesc('id');
    }

    /** @return array<string, float|int> */
    private function summary(int $sellerId): array
    {
        $refunds = RefundRequest::whereHas('order', fn ($query) => $query->where(['seller_is' => 'seller', 'seller_id' => $sellerId]));

        return [
            'pending' => (clone $refunds)->where('status', 'pending')->count(),
            'approved' => (clone $refunds)->where('status', 'approved')->count(),
            'refunded' => (clone $refunds)->where('status', 'refunded')->count(),
            // What has actually left the shop, not what has been asked for: a pending request is
            // not money the seller has lost yet, and counting it as such would misstate the books.
            'value' => round((float) (clone $refunds)->where('status', 'refunded')->sum('amount'), 2),
        ];
    }

    private function status(Request $request): ?string
    {
        $status = (string) $request->query('status', '');

        return in_array($status, self::STATUSES, true) ? $status : null;
    }

    private function pageSize(Request $request): int
    {
        $size = (int) $request->query('size', 25);

        return in_array($size, [25, 50, 100], true) ? $size : 25;
    }
}
