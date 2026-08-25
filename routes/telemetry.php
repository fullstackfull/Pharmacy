<?php

use App\Http\Controllers\Telemetry\MetricsExportController;
use Illuminate\Support\Facades\Route;

/*
| Machine-readable telemetry, served to collectors rather than to people.
|
| Registered with no middleware group at all. A scrape has no session, no CSRF token and no locale,
| and running it through `web` would write a session file every fifteen seconds and record the
| scraper as a visitor in the analytics rollups. It is authenticated by its own bearer token and
| answers 404 when that token is absent or the exporter is switched off.
*/
Route::get('monitoring/metrics', [MetricsExportController::class, 'prometheus'])->name('monitoring.metrics');
