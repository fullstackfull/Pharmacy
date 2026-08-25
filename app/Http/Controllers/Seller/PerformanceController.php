<?php

namespace App\Http\Controllers\Seller;

use App\Models\SellerSlaBreach;
use App\Services\Marketplace\SellerScorecardService;
use App\Services\Marketplace\SellerVerificationService;
use App\Services\Marketplace\SlaService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * The standing a seller is being judged against.
 *
 * The platform evaluates every approved seller against SLA policy daily and writes audited breaches,
 * and no client rendered any of it: a seller saw a scorecard number and never the standing, the
 * breach, or the deadline behind it. A marketplace that suspends shops for crossing a line it never
 * showed them is not enforcing a policy, it is springing a trap.
 *
 * Three views of one evaluation. Performance is the metrics; health is what the marketplace concludes
 * from them; SLA is the ledger of every line crossed and cleared. The tier is derived by the same
 * pure function the admin scorecard uses, so a seller and the marketplace cannot be looking at two
 * different verdicts.
 */
class PerformanceController extends SellerCenterController
{
    public function __construct(
        private readonly SellerScorecardService $scorecards,
        private readonly SlaService $sla,
        private readonly SellerVerificationService $verification,
    ) {
    }

    public function index(Request $request): View
    {
        $sellerId = $this->sellerId($request);

        return view('seller-views.performance.index', [
            'scorecard' => $this->scorecards->scorecard($sellerId),
            'thresholds' => $this->sla->thresholds(),
            'openBreaches' => $this->breaches($sellerId, open: true),
        ]);
    }

    /**
     * What the marketplace concludes, and what it would take to change it.
     *
     * A tier with no explanation is a grade with no marking scheme. Every metric is rendered beside
     * the line it is being measured against, including the ones that are comfortably inside it.
     */
    public function health(Request $request): View
    {
        $sellerId = $this->sellerId($request);
        $scorecard = $this->scorecards->scorecard($sellerId);

        return view('seller-views.performance.health', [
            'scorecard' => $scorecard,
            'thresholds' => $this->sla->thresholds(),
            // Computed from the same metrics rather than read from the breach table: this answers
            // "where do I stand right now", and a breach row answers "what happened".
            'breaches' => collect($this->sla->breachesFor($scorecard))->keyBy('metric'),
            'verification' => $this->verification->overallStatus($sellerId),
            'processingWindowHours' => $this->sla->processingWindowHours(),
        ]);
    }

    /** Every line crossed and every line cleared, which is the record a suspension has to rest on. */
    public function sla(Request $request): View
    {
        $sellerId = $this->sellerId($request);
        $available = Schema::hasTable('seller_sla_breaches');

        $breaches = $available
            ? SellerSlaBreach::where('seller_id', $sellerId)->orderByDesc('id')
                ->paginate($this->pageSize($request))->withQueryString()
            : new \Illuminate\Pagination\LengthAwarePaginator([], 0, 25);

        return view('seller-views.performance.sla', [
            'breaches' => $breaches,
            'thresholds' => $this->sla->thresholds(),
            'open' => $available ? SellerSlaBreach::where('seller_id', $sellerId)->open()->count() : 0,
            'available' => $available,
            'state' => $this->listState($breaches->total(), false),
        ]);
    }

    /** @return \Illuminate\Support\Collection<int, SellerSlaBreach> */
    private function breaches(int $sellerId, bool $open)
    {
        if (!Schema::hasTable('seller_sla_breaches')) {
            return collect();
        }

        return SellerSlaBreach::where('seller_id', $sellerId)
            ->when($open, fn ($query) => $query->open())
            ->orderByDesc('id')
            ->get();
    }

    private function pageSize(Request $request): int
    {
        $size = (int) $request->query('size', 25);

        return in_array($size, [25, 50, 100], true) ? $size : 25;
    }
}
