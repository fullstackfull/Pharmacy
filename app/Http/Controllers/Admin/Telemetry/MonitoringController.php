<?php

namespace App\Http\Controllers\Admin\Telemetry;

use App\Http\Controllers\BaseController;
use App\Services\Telemetry\SystemHealthService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MonitoringController extends BaseController
{
    public function __construct(private readonly SystemHealthService $health)
    {
    }

    public function index(?Request $request, ?string $type = null): View
    {
        return view('admin-views.telemetry.monitoring', [
            'snapshot' => $this->health->snapshot(),
        ]);
    }

    /** The auto-refresh feed; excluded from telemetry so it cannot watch itself. */
    public function pulse(): JsonResponse
    {
        return response()->json($this->health->snapshot());
    }
}
