<?php

namespace App\Http\Controllers\Seller;

use App\Models\BrandClaim;
use App\Models\SellerSlaBreach;
use App\Services\Marketplace\BrandRegistryService;
use App\Services\Marketplace\SellerVerificationService;
use App\Services\Marketplace\SlaService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * Everything the marketplace could act on, on one page.
 *
 * The navigation has badged this destination with a `compliance_action` count since Wave 1 and the
 * page behind it did not exist — so the platform rendered a number on a menu item pointing at
 * nothing. A badge that leads nowhere is worse than no badge: it tells a seller something is wrong
 * and gives them no way to find out what.
 *
 * Three things can cost a shop its listings, and they are gathered here because they are read
 * together and never were: brand authorisation that has lapsed or was never granted, identity
 * verification that has expired, and SLA lines crossed over time. Each is rendered with its own
 * deadline, because "you are non-compliant" without a date is an accusation rather than a task.
 */
class ComplianceController extends SellerCenterController
{
    /** How far back the breach trend looks. A quarter is long enough to show a direction. */
    private const TREND_DAYS = 90;

    public function __construct(
        private readonly BrandRegistryService $brands,
        private readonly SellerVerificationService $verification,
        private readonly SlaService $sla,
    ) {
    }

    public function index(Request $request): View
    {
        $sellerId = $this->sellerId($request);

        return view('seller-views.compliance.index', [
            'verification' => $this->verification->overallStatus($sellerId),
            'documents' => $this->verification->documentsFor($sellerId),
            'claims' => $this->claims($sellerId),
            'exposure' => $this->brands->brandExposure($sellerId),
            'enforcing' => $this->brands->isEnforcing(),
            'openBreaches' => $this->openBreaches($sellerId),
            // Not a headline figure: a count of breaches over a quarter says whether things are
            // getting better or worse, which is the only question a trend can answer honestly.
            'breachTrend' => $this->breachTrend($sellerId),
        ]);
    }

    /** @return \Illuminate\Support\Collection<int, BrandClaim> */
    private function claims(int $sellerId)
    {
        return Schema::hasTable('brand_claims') ? $this->brands->claimsFor($sellerId) : collect();
    }

    /** @return \Illuminate\Support\Collection<int, SellerSlaBreach> */
    private function openBreaches(int $sellerId)
    {
        if (!Schema::hasTable('seller_sla_breaches')) {
            return collect();
        }

        return SellerSlaBreach::where('seller_id', $sellerId)->open()->orderByDesc('id')->get();
    }

    /**
     * Breaches opened per month over the window.
     *
     * @return array<string, int>
     */
    private function breachTrend(int $sellerId): array
    {
        if (!Schema::hasTable('seller_sla_breaches')) {
            return [];
        }

        $rows = SellerSlaBreach::where('seller_id', $sellerId)
            ->where('created_at', '>=', Carbon::now()->subDays(self::TREND_DAYS))
            ->orderBy('created_at')
            ->get(['created_at']);

        $trend = [];
        foreach ($rows as $row) {
            $month = Carbon::parse($row->created_at)->format('Y-m');
            $trend[$month] = ($trend[$month] ?? 0) + 1;
        }

        return $trend;
    }
}
