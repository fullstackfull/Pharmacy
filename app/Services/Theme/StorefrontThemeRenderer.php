<?php

namespace App\Services\Theme;

use App\Models\Theme;
use App\Models\ThemeSection;
use App\Models\ThemeVersion;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Resolves what the storefront should render for a page from the ACTIVE theme's PUBLISHED version
 * (Phase 1.1 → storefront).
 *
 * This is the compatibility shim that makes the whole theme system safe to ship: it only returns
 * sections when an active theme has a published version with visible sections for that page.
 * Otherwise it returns null, and the caller keeps rendering the existing hardcoded blades exactly
 * as today. Nothing about the current storefront changes until a merchant publishes a theme.
 *
 * Responsive settings are resolved per breakpoint (`key`, `key_tablet`, `key_mobile`) so a section
 * can differ across devices without the storefront knowing the naming convention.
 */
class StorefrontThemeRenderer
{
    public const CACHE_KEY_PREFIX = 'storefront_theme_page_';
    private const CACHE_TTL = 600; // seconds

    public function __construct(private readonly SectionRegistry $registry)
    {
    }

    /**
     * Sections to render for a page, or NULL when the storefront should fall back to its existing
     * templates (no active theme / nothing published / no visible sections).
     *
     * @return array<int, array{type:string, settings:array, blocks:array}>|null
     */
    public function sectionsFor(string $page): ?array
    {
        if (!$this->tablesReady()) {
            return null;
        }

        try {
            return Cache::remember(self::CACHE_KEY_PREFIX . $page, self::CACHE_TTL, function () use ($page) {
                $version = $this->publishedVersion();
                if (!$version) {
                    return null;
                }

                $sections = ThemeSection::with('blocks')
                    ->where('theme_version_id', $version->id)
                    ->where('page', $page)
                    ->where('is_visible', true)
                    ->orderBy('sort_order')
                    ->get();

                if ($sections->isEmpty()) {
                    return null; // nothing configured -> keep the existing storefront
                }

                return $sections->map(fn (ThemeSection $section) => [
                    'type'     => $section->type,
                    'settings' => $this->registry->normalizeSettings($section->type, $section->settings ?? []),
                    'blocks'   => $section->blocks
                        ->where('is_visible', true)
                        ->map(fn ($block) => ['type' => $block->type, 'settings' => $block->settings ?? []])
                        ->values()->all(),
                ])->all();
            });
        } catch (\Throwable) {
            // A theme problem must never take the storefront down.
            return null;
        }
    }

    /** Effective global settings (branding/colors/typography/layout) of the published version. */
    public function globalSettings(ThemeManager $manager): array
    {
        if (!$this->tablesReady()) {
            return $manager->defaultSettings();
        }

        try {
            return $manager->resolveSettings($this->publishedVersion());
        } catch (\Throwable) {
            return $manager->defaultSettings();
        }
    }

    /**
     * Resolve a setting for a breakpoint, falling back to the base value.
     * $breakpoint: desktop | tablet | mobile
     */
    public function responsiveValue(array $settings, string $key, string $breakpoint = 'desktop'): mixed
    {
        if ($breakpoint !== 'desktop') {
            $scoped = $key . '_' . $breakpoint;
            if (array_key_exists($scoped, $settings) && $settings[$scoped] !== null && $settings[$scoped] !== '') {
                return $settings[$scoped];
            }
        }
        return $settings[$key] ?? null;
    }

    /** Drop the cached structure for every page — call after publishing. */
    public function flush(array $pages = ['home', 'header', 'footer']): void
    {
        foreach ($pages as $page) {
            Cache::forget(self::CACHE_KEY_PREFIX . $page);
        }
    }

    private function publishedVersion(): ?ThemeVersion
    {
        $theme = Theme::query()->where('is_active', true)->first();
        if (!$theme) {
            return null;
        }
        return ThemeVersion::query()
            ->where('theme_id', $theme->id)
            ->where('status', ThemeVersion::STATUS_PUBLISHED)
            ->first();
    }

    private function tablesReady(): bool
    {
        return Schema::hasTable('themes')
            && Schema::hasTable('theme_versions')
            && Schema::hasTable('theme_sections');
    }
}
