<?php

namespace App\Http\Controllers\Seller;

use App\Models\SellerBulkJob;
use App\Services\Marketplace\Bulk\SellerBulkJobService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * The receipt for every bulk change this shop has run.
 *
 * A bulk operation that reports "done" and quietly refused four hundred rows is worse than one that
 * fails outright, so a job is a record rather than an action: what was asked for, what happened to
 * each row, and the reason for every refusal. The phone app has read these since Wave A.3; the
 * browser could not, because the two navigation entries pointing here named a route nobody wrote.
 */
class BulkJobController extends SellerCenterController
{
    public function __construct(private readonly SellerBulkJobService $bulkJobs)
    {
    }

    public function index(Request $request): View
    {
        $jobs = SellerBulkJob::forSeller($this->sellerId($request))
            ->when($this->status($request), fn ($query, $status) => $query->where('status', $status))
            ->orderByDesc('id')
            ->paginate($this->pageSize($request))
            ->withQueryString();

        return view('seller-views.bulk-jobs.index', [
            'jobs' => $jobs,
            'types' => $this->bulkJobs->availableTypes(),
            'status' => $this->status($request),
            'statuses' => [
                SellerBulkJob::STATUS_QUEUED,
                SellerBulkJob::STATUS_PROCESSING,
                SellerBulkJob::STATUS_COMPLETED,
                SellerBulkJob::STATUS_PARTIAL,
                SellerBulkJob::STATUS_FAILED,
            ],
            'state' => $this->listState($jobs->total(), $this->status($request) !== null),
        ]);
    }

    public function show(Request $request, int $id): View
    {
        // Scoped on the seller: another shop's job is not found rather than readable.
        $job = SellerBulkJob::forSeller($this->sellerId($request))->findOrFail($id);

        return view('seller-views.bulk-jobs.show', ['job' => $job]);
    }

    private function status(Request $request): ?string
    {
        $status = (string) $request->query('status', '');

        return in_array($status, [
            SellerBulkJob::STATUS_QUEUED,
            SellerBulkJob::STATUS_PROCESSING,
            SellerBulkJob::STATUS_COMPLETED,
            SellerBulkJob::STATUS_PARTIAL,
            SellerBulkJob::STATUS_FAILED,
        ], true) ? $status : null;
    }

    private function pageSize(Request $request): int
    {
        $size = (int) $request->query('size', 25);

        return in_array($size, [25, 50, 100], true) ? $size : 25;
    }
}
