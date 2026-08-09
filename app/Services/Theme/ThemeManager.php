<?php

namespace App\Services\Theme;

use App\Models\Theme;
use App\Models\ThemeBlock;
use App\Models\ThemeSection;
use App\Models\ThemeVersion;
use Illuminate\Support\Facades\DB;

/**
 * Orchestrates the theme lifecycle (Phase 1.1): resolve effective settings, publish a version
 * atomically, and duplicate a version into a fresh draft (for editing / revisions).
 *
 * Publish and duplicate are transactional and non-destructive; settings resolution is a pure
 * deep-merge of baseline defaults with a version's overrides, so it is unit-testable without a DB.
 */
class ThemeManager
{
    /** Baseline theme configuration. A version's `settings` override any subset of these. */
    public function defaultSettings(): array
    {
        return [
            'branding' => [
                'logo' => null, 'mobile_logo' => null, 'favicon' => null,
                'logo_width' => 140, 'logo_height' => 40,
            ],
            'colors' => [
                'primary' => '#0f766e', 'secondary' => '#334155', 'accent' => '#f59e0b',
                'background' => '#ffffff', 'surface' => '#f8fafc', 'text' => '#0f172a',
                'muted_text' => '#64748b', 'border' => '#e2e8f0',
                'success' => '#16a34a', 'warning' => '#d97706', 'error' => '#dc2626',
            ],
            'typography' => [
                'heading_font' => 'inherit', 'body_font' => 'inherit',
                'heading_font_ar' => 'inherit', 'body_font_ar' => 'inherit',
                'base_font_size' => 16, 'line_height' => 1.6,
            ],
            'layout' => [
                'container_width' => 1200, 'page_spacing' => 24, 'border_radius' => 8,
                'button_style' => 'rounded', 'input_style' => 'outline', 'card_style' => 'shadow',
            ],
        ];
    }

    /** Effective settings for a version (defaults deep-merged with the version's overrides). */
    public function resolveSettings(?ThemeVersion $version): array
    {
        $overrides = $version?->settings ?? [];
        return $this->deepMerge($this->defaultSettings(), is_array($overrides) ? $overrides : []);
    }

    /** Create a theme together with an initial empty draft version. */
    public function createTheme(array $attributes, array $settings = []): Theme
    {
        return DB::transaction(function () use ($attributes, $settings) {
            $theme = Theme::create($attributes);
            ThemeVersion::create([
                'theme_id' => $theme->id,
                'label'    => 'Initial draft',
                'status'   => ThemeVersion::STATUS_DRAFT,
                'settings' => $settings ?: null,
            ]);
            return $theme->refresh();
        });
    }

    /** Make a theme the single active one (deactivates all others). Atomic. */
    public function activate(Theme $theme): Theme
    {
        return DB::transaction(function () use ($theme) {
            Theme::query()
                ->where('id', '!=', $theme->id)
                ->where('is_active', true)
                ->update(['is_active' => false]);

            $theme->is_active = true;
            $theme->save();

            app(StorefrontThemeRenderer::class)->flush();

            return $theme->refresh();
        });
    }

    /** Publish a version: previous published version of the same theme is archived. Atomic. */
    public function publish(ThemeVersion $version): ThemeVersion
    {
        return DB::transaction(function () use ($version) {
            ThemeVersion::query()
                ->where('theme_id', $version->theme_id)
                ->where('status', ThemeVersion::STATUS_PUBLISHED)
                ->where('id', '!=', $version->id)
                ->update(['status' => ThemeVersion::STATUS_ARCHIVED]);

            $version->status = ThemeVersion::STATUS_PUBLISHED;
            $version->published_at = now();
            $version->save();

            // The storefront caches the published structure; drop it so the change is live at once.
            app(StorefrontThemeRenderer::class)->flush();

            return $version->refresh();
        });
    }

    /** Duplicate a version (with its sections + blocks) into a new draft. */
    public function createDraftFrom(ThemeVersion $source, ?string $label = null): ThemeVersion
    {
        return DB::transaction(function () use ($source, $label) {
            $draft = ThemeVersion::create([
                'theme_id'            => $source->theme_id,
                'label'               => $label ?? ('Draft of #' . $source->id),
                'status'              => ThemeVersion::STATUS_DRAFT,
                'settings'            => $source->settings,
                'based_on_version_id' => $source->id,
            ]);

            foreach ($source->sections()->with('blocks')->get() as $section) {
                $newSection = ThemeSection::create([
                    'theme_version_id' => $draft->id,
                    'page'             => $section->page,
                    'type'             => $section->type,
                    'sort_order'       => $section->sort_order,
                    'is_visible'       => $section->is_visible,
                    'settings'         => $section->settings,
                ]);

                foreach ($section->blocks as $block) {
                    ThemeBlock::create([
                        'theme_section_id' => $newSection->id,
                        'type'             => $block->type,
                        'sort_order'       => $block->sort_order,
                        'is_visible'       => $block->is_visible,
                        'settings'         => $block->settings,
                    ]);
                }
            }

            return $draft;
        });
    }

    /** The published version of the currently active theme, if any. */
    public function activeThemePublishedVersion(): ?ThemeVersion
    {
        $theme = Theme::query()->where('is_active', true)->first();
        return $theme?->publishedVersion()->first();
    }

    /** Deep-merge $override into $base; associative sub-arrays merge, everything else overrides. */
    private function deepMerge(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key]) && $this->isAssoc($base[$key])) {
                $base[$key] = $this->deepMerge($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }
        return $base;
    }

    private function isAssoc(array $arr): bool
    {
        if ($arr === []) {
            return true;
        }
        return array_keys($arr) !== range(0, count($arr) - 1);
    }
}
