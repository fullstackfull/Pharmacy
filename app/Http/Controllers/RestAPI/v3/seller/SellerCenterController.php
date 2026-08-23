<?php

namespace App\Http\Controllers\RestAPI\v3\seller;

use App\Http\Controllers\Controller;
use App\Services\Marketplace\SellerCenterService;
use App\Services\Marketplace\SellerScorecardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
    )
    {
    }

    public function overview(Request $request): JsonResponse
    {
        return response()->json($this->center->overview($request->seller->id), 200);
    }

    public function scorecard(Request $request): JsonResponse
    {
        return response()->json($this->scorecards->scorecard($request->seller->id), 200);
    }
}
