<?php

use Illuminate\Support\Facades\Cache;

if (!function_exists('getAppVersion')) {
    /**
     * The project's own release version (version.json in the repo root),
     * shown in the admin shell, the Monitoring header and the developer
     * portal. Separate from the vendor 6valley version on purpose: this
     * number tracks THIS store's releases.
     */
    function getAppVersion(): array
    {
        return Cache::remember('app_version_info', 300, function () {
            $path = base_path('version.json');
            $fallback = ['version' => '1.0.0', 'released_at' => null, 'channel' => 'stable'];
            if (!is_readable($path)) {
                return $fallback;
            }
            $data = json_decode((string) file_get_contents($path), true);
            return is_array($data) ? array_merge($fallback, $data) : $fallback;
        });
    }
}
