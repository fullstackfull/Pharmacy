<?php

if (!function_exists('theme_asset')) {
    function theme_asset($path = null): string
    {
        return dynamicAsset(path: $path);
    }
}

if (!function_exists('theme_root_path')) {
    /**
     * The storefront runs a single Blade theme: resources/themes/default.
     * Look, colors and home-page sections are managed in Admin -> Theme Management,
     * not by swapping theme folders — so this is a constant, not an env lookup.
     */
    function theme_root_path(): string
    {
        return 'default';
    }
}

if (!function_exists('getHexToRGBColorCode')) {
    function getHexToRGBColorCode($hex): ?string
    {
        $result = preg_match('/^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i', $hex, $matches);
        return $result ? hexdec($matches[1]) . ', ' . hexdec($matches[2]) . ', ' . hexdec($matches[3]) : null;
    }
}

if (!function_exists('getSystemDynamicPartials')) {
    function getSystemDynamicPartials($type = null): mixed
    {
        if ($type == 'analytics_script') {
            return view("system-partials._analytics_script");
        }
        return null;
    }
}

if (!function_exists('formatCompactNumber')) {
    function formatCompactNumber(int|float|null $value = 0): string
    {
        if (!is_numeric($value)) {
            return (string) $value;
        }

        if ($value >= 1000000000) {
            return round($value / 1000000000, 2) . 'B+';
        } elseif ($value >= 1000000) {
            return round($value / 1000000, 2) . 'M+';
        } elseif ($value >= 1000) {
            return round($value / 1000, 2) . 'K+';
        }

        return (string) $value;
    }
}
