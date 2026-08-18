<?php

namespace App\Http\Controllers\Admin\Telemetry;

use App\Http\Controllers\BaseController;
use App\Services\Telemetry\DeveloperPortalService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class DeveloperPortalController extends BaseController
{
    public function __construct(private readonly DeveloperPortalService $portal)
    {
    }

    public function index(?Request $request, ?string $type = null): View
    {
        $baseUrl = rtrim(url('/'), '/');
        return view('admin-views.telemetry.developer', [
            'reference' => $this->portal->apiReference(),
            'guides' => $this->portal->guides($baseUrl),
            'baseUrl' => $baseUrl,
            'version' => getAppVersion(),
        ]);
    }
}
