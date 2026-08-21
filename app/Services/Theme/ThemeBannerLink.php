<?php

namespace App\Services\Theme;

use App\Models\Banner;
use App\Models\Theme;
use App\Models\ThemeBlock;
use App\Models\ThemeSection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The bridge between the Theme Builder's banner-shaped blocks and Promotion -> Banners.
 *
 * One source of truth: the banners table. A block that carries a `banner_id` renders that row's
 * image/link/text live, so editing the banner in Banner Setup changes the storefront without
 * touching the theme. And a block whose image was uploaded straight in the builder is registered
 * as a "Theme Banner" row automatically, so it shows up in Banner Setup for later editing —
 * closing the "I added a banner in the theme but Banner Setup doesn't know about it" gap.
 */
class ThemeBannerLink
{
    /** The banner_type auto-registered rows get. No built-in storefront slot renders this type. */
    public const THEME_BANNER_TYPE = 'Theme Banner';

    /** Picker choices for a `banner` schema field: every banner of the storefront theme. */
    public function choices(): array
    {
        try {
            return Banner::query()
                ->where('theme', theme_root_path())
                ->orderByDesc('id')
                ->take(100)
                ->get(['id', 'banner_type', 'title', 'published'])
                ->map(fn (Banner $banner) => [
                    'value' => $banner->id,
                    'label' => '#' . $banner->id . ' — ' . translate(str_replace('_', ' ', $banner->banner_type))
                        . ($banner->title ? ' — ' . Str::limit($banner->title, 30) : '')
                        . ($banner->published ? '' : ' (' . translate('unpublished') . ')'),
                ])->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /** Add live picker choices (and the Banner Setup url) to every `banner` field of a schema. */
    public function hydrateSchema(array $schema): array
    {
        $choices = null;
        foreach ($schema as $key => $field) {
            if (($field['type'] ?? null) === 'banner') {
                $choices ??= $this->choices();
                $schema[$key]['choices'] = $choices;
                $schema[$key]['manage_url'] = route('admin.banner.list');
            }
        }
        return $schema;
    }

    /**
     * Register a builder-uploaded banner image in Promotion -> Banners.
     *
     * Runs after a banner-backed block is saved: when it has an image but no linked banner, and the
     * image lives on our own public disk, the file is copied into the banners directory and a
     * "Theme Banner" row is created, then linked back into the block. From that moment Banner Setup
     * lists it and edits there render on the storefront. Never throws — a sync problem must not
     * break the builder's autosave.
     */
    public function syncBlock(ThemeBlock $block): void
    {
        try {
            if (!in_array($block->type, SectionRegistry::BANNER_BACKED_BLOCK_TYPES, true)) {
                return;
            }

            $settings = $block->settings ?? [];
            if (!empty($settings['banner_id']) || empty($settings['image'])) {
                return;
            }

            $sourcePath = $this->publicDiskPathFromUrl((string) $settings['image']);
            if ($sourcePath === null || !Storage::disk('public')->exists($sourcePath)) {
                return;
            }

            $extension = pathinfo($sourcePath, PATHINFO_EXTENSION) ?: 'webp';
            $fileName = now()->format('Y-m-d-His') . '-' . Str::random(8) . '.' . $extension;
            if (!Storage::disk('public')->copy($sourcePath, 'banner/' . $fileName)) {
                return;
            }

            $banner = Banner::create([
                'photo'         => $fileName,
                'banner_type'   => self::THEME_BANNER_TYPE,
                'theme'         => theme_root_path(),
                'published'     => 1,
                'url'           => $settings['link'] ?? null,
                'resource_type' => 'custom',
                'title'         => $settings['title'] ?? null,
                'sub_title'     => $settings['subtitle'] ?? ($settings['body'] ?? null),
                'button_text'   => $settings['button_text'] ?? null,
            ]);

            $settings['banner_id'] = $banner->id;
            $block->settings = $settings;
            $block->save();
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    /**
     * Live card data for linked banners, keyed by banner id.
     * `published` travels along so the renderer can hide a card whose banner was unpublished.
     *
     * @return array<int, array>
     */
    public function cardOverrides(array $bannerIds): array
    {
        $bannerIds = array_values(array_unique(array_filter(array_map('intval', $bannerIds))));
        if ($bannerIds === []) {
            return [];
        }

        try {
            return Banner::with('storage')
                ->whereIn('id', $bannerIds)
                ->get()
                ->keyBy('id')
                ->map(fn (Banner $banner) => [
                    'published'    => (int) $banner->published === 1,
                    'image'        => getStorageImages(path: $banner->photo_full_url, type: 'banner'),
                    'image_mobile' => $banner->mobile_photo
                        ? getStorageImages(path: $banner->mobile_photo_full_url, type: 'banner')
                        : null,
                    'link'         => $banner->url,
                    'title'        => $banner->title,
                    'subtitle'     => $banner->sub_title,
                    'button_text'  => $banner->button_text,
                    'background'   => $banner->background_color,
                ])->all();
        } catch (\Throwable $exception) {
            report($exception);
            return [];
        }
    }

    /**
     * Where each banner appears in the active theme, for the Banner Setup list's badges:
     * - by id: blocks that link the banner directly (`banner_id`)
     * - by type: store_banner sections that render a whole banner type
     *
     * @return array{ids: array<int, array<int, string>>, types: array<string, array<int, string>>}
     */
    public function usage(): array
    {
        $usage = ['ids' => [], 'types' => []];

        try {
            $theme = Theme::query()->where('is_active', true)->first();
            if (!$theme) {
                return $usage;
            }

            $sections = ThemeSection::with(['blocks', 'version'])
                ->whereHas('version', fn ($query) => $query->where('theme_id', $theme->id)
                    ->whereIn('status', ['draft', 'published']))
                ->get();

            foreach ($sections as $section) {
                $where = translate($section->page) . ' — ' . ($section->version->status === 'published'
                    ? translate('published')
                    : translate('draft'));

                if ($section->type === 'store_banner') {
                    $type = (string) ($section->settings['banner_type'] ?? '');
                    if ($type !== '') {
                        $usage['types'][$type][] = $where;
                    }
                }

                foreach ($section->blocks as $block) {
                    $bannerId = (int) ($block->settings['banner_id'] ?? 0);
                    if ($bannerId > 0) {
                        $usage['ids'][$bannerId][] = $where;
                    }
                }
            }
        } catch (\Throwable $exception) {
            report($exception);
        }

        return $usage;
    }

    /** Resolve a builder image URL to its path on the public disk, or null for foreign URLs. */
    private function publicDiskPathFromUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        $base = rtrim(Storage::disk('public')->url(''), '/') . '/';
        $candidates = [$base];

        // The same base as a root-relative path (the builder stores whichever form it was given).
        $parsedBase = parse_url($base, PHP_URL_PATH);
        if ($parsedBase) {
            $candidates[] = $parsedBase;
        }

        foreach ($candidates as $prefix) {
            if (str_starts_with($url, $prefix)) {
                return rawurldecode(substr($url, strlen($prefix)));
            }
        }

        return null;
    }
}
