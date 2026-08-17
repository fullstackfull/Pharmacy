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
    public const GLOBAL_SETTINGS_CACHE_KEY = 'storefront_theme_global_settings';
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
    /**
     * Session key holding the theme version an admin is previewing.
     *
     * Preview is deliberately session-scoped and admin-gated rather than a query parameter: a
     * ?preview_version=N URL could be shared or crawled, which would expose an unpublished design
     * to customers and to search engines.
     */
    public const PREVIEW_SESSION_KEY = 'theme_preview_version_id';

    public function sectionsFor(string $page): ?array
    {
        if (!$this->tablesReady()) {
            return null;
        }

        // Draft preview: only for an authenticated admin, and never cached, so a preview can never
        // leak into what customers are served.
        $previewVersionId = $this->activePreviewVersionId();
        if ($previewVersionId !== null) {
            try {
                return $this->buildSections($previewVersionId, $page);
            } catch (\Throwable) {
                return null;
            }
        }

        try {
            return Cache::remember(self::CACHE_KEY_PREFIX . $page, self::CACHE_TTL, function () use ($page) {
                $version = $this->publishedVersion();
                if (!$version) {
                    return null;
                }

                return $this->buildSections($version->id, $page);
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
     * Resolved global settings ONLY when an active theme has a published version; null otherwise.
     *
     * This is what the storefront `<head>` consults to override its design tokens: returning null when
     * nothing is published preserves the contract that the storefront is unchanged until a merchant
     * publishes a theme (globalSettings() can't be used for this because it returns defaults either way).
     */
    public function publishedGlobalSettings(ThemeManager $manager): ?array
    {
        if (!$this->tablesReady()) {
            return null;
        }

        try {
            // Draft preview: for an authenticated admin previewing a draft, resolve THAT version's
            // settings (never cached) so the visual builder's live preview shows draft colours/typography
            // together with its draft sections. Guests and normal requests are unaffected.
            $previewVersionId = $this->activePreviewVersionId();
            if ($previewVersionId !== null) {
                $version = ThemeVersion::find($previewVersionId);
                return $version ? $manager->resolveSettings($version) : null;
            }

            // Cached like sectionsFor(): this runs in the storefront <head> on EVERY request, so an
            // uncached publishedVersion() lookup would hit the DB per page even with nothing published.
            // Wrapped in an array so the common "no published theme" (null) result is cached too —
            // Cache::remember treats a bare null as a miss and would re-query every request.
            $cached = Cache::remember(self::GLOBAL_SETTINGS_CACHE_KEY, self::CACHE_TTL, function () use ($manager) {
                $version = $this->publishedVersion();

                return ['settings' => $version ? $manager->resolveSettings($version) : null];
            });

            return $cached['settings'] ?? null;
        } catch (\Throwable) {
            return null;
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
        Cache::forget(self::GLOBAL_SETTINGS_CACHE_KEY);
    }

    /** Shared section builder used by both the published path and draft preview. */
    private function buildSections(int $versionId, string $page): ?array
    {
        $sections = ThemeSection::with('blocks')
            ->where('theme_version_id', $versionId)
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
    }

    /** The version being previewed, but ONLY for an authenticated admin. */
    private function activePreviewVersionId(): ?int
    {
        try {
            if (!auth('admin')->check()) {
                return null;
            }
            $id = session(self::PREVIEW_SESSION_KEY);
            return $id ? (int) $id : null;
        } catch (\Throwable) {
            return null;
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
