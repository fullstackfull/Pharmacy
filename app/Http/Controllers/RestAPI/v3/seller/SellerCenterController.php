<?php

namespace App\Http\Controllers\RestAPI\v3\seller;

use App\Http\Controllers\Controller;
use App\Services\DeveloperPortal\ApiDoc;
use App\Models\SellerSlaBreach;
use App\Services\Marketplace\SellerCenterService;
use App\Services\Marketplace\SellerScorecardService;
use App\Services\Marketplace\SlaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * Seller Center for the mobile app — the same verification / performance /
 * finance / SLA composition the web hub shows, served over the v3 seller API.
 * Thin by design: every number comes from the marketplace services the web
 * controllers already use, so both surfaces always agree.
 */
class SellerCenterController extends Controller
{
    public function __construct(
        private readonly SellerCenterService    $center,
        private readonly SellerScorecardService $scorecards,
        private readonly SlaService             $sla,
    )
    {
    }

    #[ApiDoc(
        summary: "One seller's verification, performance, finance and SLA standing",
        description: 'Composed from the same marketplace services the vendor web hub uses, so both surfaces always agree. '
            . 'Identity comes from the seller token; there is no id parameter to change.',
        audience: ApiDoc::VENDOR_APP,
        stability: ApiDoc::STABLE,
        since: 'v3',
        idempotent: true,
        group: 'vendors',
    )]
    public function overview(Request $request): JsonResponse
    {
        return response()->json($this->center->overview($request->seller->id), 200);
    }

    #[ApiDoc(
        summary: 'The performance scorecard and derived health tier for the signed-in seller',
        description: 'Fulfilment, cancellation, return and refund rates, rating, moderation strikes and the '
            . 'derived tier (new / good / watch / at_risk) — the same numbers the admin scorecard shows.',
        audience: ApiDoc::VENDOR_APP,
        stability: ApiDoc::STABLE,
        since: 'v3',
        idempotent: true,
        group: 'vendors',
    )]
    public function scorecard(Request $request): JsonResponse
    {
        $sellerId = $request->seller->id;
        $scorecard = $this->scorecards->scorecard($sellerId);

        return response()->json(array_merge($scorecard, [
            // A rate with nothing to compare it to is a statistic; a rate beside its ceiling is a
            // position. The web screen has printed the marketplace's own limit next to each figure
            // since Wave 6 and this endpoint returned the figures alone, so the phone showed a
            // seller numbers that could not be read.
            'thresholds' => $this->sla->thresholds(),
            'processing_window_hours' => $this->sla->processingWindowHours(),
            // Where the seller stands right now, computed from these very metrics. Distinct from
            // the breach rows below, which say what already happened and was recorded.
            'over_the_line' => $this->sla->breachesFor($scorecard),
            'open_breaches' => $this->openBreaches($sellerId),
        ]), 200);
    }

    #[ApiDoc(
        summary: 'Every line this shop has crossed, and every one it has cleared',
        description: 'The audited SLA breach ledger, newest first, with the thresholds each was measured '
            . 'against. Cleared breaches are included deliberately: a record that shows only current '
            . 'problems cannot show improvement, and this is the record a suspension would rest on.',
        audience: ApiDoc::VENDOR_APP,
        stability: ApiDoc::STABLE,
        since: 'v3',
        idempotent: true,
        group: 'vendors',
    )]
    public function slaBreaches(Request $request): JsonResponse
    {
        if (!Schema::hasTable('seller_sla_breaches')) {
            // Absent rather than empty, and the client is told which. A seller cannot tell a broken
            // screen from a clean record, and saying so is the difference between a bug report and
            // a fact.
            return response()->json([
                'available' => false,
                'thresholds' => $this->sla->thresholds(),
                'total_size' => 0,
                'open' => 0,
                'breaches' => [],
            ], 200);
        }

        $sellerId = $request->seller->id;
        $limit = min(100, max(1, (int) $request->query('limit', 25)));

        $breaches = SellerSlaBreach::where('seller_id', $sellerId)
            ->orderByDesc('id')
            ->paginate($limit, ['*'], 'page', max(1, (int) $request->query('offset', 1)));

        return response()->json([
            'available' => true,
            'thresholds' => $this->sla->thresholds(),
            'total_size' => $breaches->total(),
            'limit' => $breaches->perPage(),
            'offset' => $breaches->currentPage(),
            'open' => SellerSlaBreach::where('seller_id', $sellerId)->open()->count(),
            'breaches' => collect($breaches->items())->map(fn (SellerSlaBreach $breach) => [
                'id' => $breach->id,
                'metric' => $breach->metric,
                'actual_value' => (float) $breach->actual_value,
                'threshold' => (float) $breach->threshold,
                'status' => $breach->status,
                'opened_at' => $breach->created_at,
                'cleared_at' => $breach->cleared_at,
            ])->values(),
        ], 200);
    }

    /** @return array<int, array<string, mixed>> */
    private function openBreaches(int|string $sellerId): array
    {
        if (!Schema::hasTable('seller_sla_breaches')) {
            return [];
        }

        return SellerSlaBreach::where('seller_id', $sellerId)->open()->orderByDesc('id')->get()
            ->map(fn (SellerSlaBreach $breach) => [
                'id' => $breach->id,
                'metric' => $breach->metric,
                'actual_value' => (float) $breach->actual_value,
                'threshold' => (float) $breach->threshold,
                'opened_at' => $breach->created_at,
            ])->all();
    }
}
