<?php

namespace App\Http\Controllers\Admin\Telemetry;

use App\Http\Controllers\BaseController;
use App\Services\Telemetry\AnalyticsService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class AnalyticsController extends BaseController
{
    public function __construct(private readonly AnalyticsService $analytics)
    {
    }

    public function index(?Request $request, ?string $type = null): View
    {
        $days = in_array((int) $request['range'], [7, 30, 90], true) ? (int) $request['range'] : 7;
        return view('admin-views.telemetry.analytics', [
            'data' => $this->analytics->overview($days),
            'range' => $days,
        ]);
    }
}
