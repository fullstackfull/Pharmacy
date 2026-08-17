<?php

namespace App\Services\Theme;

use App\Models\Theme;
use App\Models\ThemeBlock;
use App\Models\ThemeSection;
use App\Models\ThemeVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Theme import / export / presets (Phase 1.1).
 *
 * Import is the security-sensitive half: the payload is untrusted (an uploaded file or a pasted
 * blob), so nothing from it is written verbatim. Section and block types must exist in the
 * SectionRegistry, settings go through the same normalizeSettings() the builder uses (unknown keys
 * dropped, values coerced), and ownership/status fields are set by us — never taken from the file.
 *
 * Export produces a plain array so the caller can present it as JSON, a download, or a stored
 * preset without this service knowing about HTTP or storage.
 */
class ThemePortabilityService
{
    /** Bumped when the payload shape changes, so old files can be recognised. */
    public const FORMAT_VERSION = 1;

    /** Hard caps so a malicious payload can't exhaust memory or create thousands of rows. */
    private const MAX_SECTIONS = 200;
    private const MAX_BLOCKS_PER_SECTION = 100;

    public function __construct(
        private readonly SectionRegistry $registry,
        private readonly ThemeManager    $manager,
    )
    {
    }

    /** Serialize a version (settings + sections + blocks) into a portable array. */
    public function export(ThemeVersion $version): array
    {
        $sections = ThemeSection::with('blocks')
            ->where('theme_version_id', $version->id)
            ->orderBy('page')->orderBy('sort_order')
            ->get();

        return [
            'format_version' => self::FORMAT_VERSION,
            'exported_at'    => now()->toIso8601String(),
            'theme'          => [
                'name'        => $version->theme?->name,
                'description' => $version->theme?->description,
            ],
            'settings' => $version->settings ?? [],
            'sections' => $sections->map(fn (ThemeSection $s) => [
                'page'       => $s->page,
                'type'       => $s->type,
                'sort_order' => $s->sort_order,
                'is_visible' => (bool) $s->is_visible,
                'settings'   => $s->settings ?? [],
                'blocks'     => $s->blocks->map(fn (ThemeBlock $b) => [
                    'type'       => $b->type,
                    'sort_order' => $b->sort_order,
                    'is_visible' => (bool) $b->is_visible,
                    'settings'   => $b->settings ?? [],
                ])->values()->all(),
            ])->values()->all(),
        ];
    }

    /**
     * Validate an untrusted payload without writing anything.
     *
     * @return array{valid:bool, errors:array<int,string>, warnings:array<int,string>, section_count:int}
     */
    public function validate(mixed $payload): array
    {
        $errors = [];
        $warnings = [];

        if (!is_array($payload)) {
            return ['valid' => false, 'errors' => ['payload_is_not_an_object'], 'warnings' => [], 'section_count' => 0];
        }

        $format = $payload['format_version'] ?? null;
        if ($format === null) {
            $errors[] = 'missing_format_version';
        } elseif ((int) $format > self::FORMAT_VERSION) {
            $errors[] = 'format_version_is_newer_than_this_installation_supports';
        }

        $sections = $payload['sections'] ?? [];
        if (!is_array($sections)) {
            $errors[] = 'sections_must_be_a_list';
            $sections = [];
        }

        if (count($sections) > self::MAX_SECTIONS) {
            $errors[] = 'too_many_sections';
        }

        $accepted = 0;
        foreach ($sections as $section) {
            if (!is_array($section) || empty($section['type'])) {
                $warnings[] = 'a_section_without_a_type_was_skipped';
                continue;
            }
            if (!$this->registry->has((string) $section['type'])) {
                // Unknown types are skipped, not fatal: a theme exported from an installation with
                // extra section types should still import the parts this one understands.
                $warnings[] = 'unknown_section_type_skipped: ' . (string) $section['type'];
                continue;
            }
            if (isset($section['blocks']) && is_array($section['blocks'])
                && count($section['blocks']) > self::MAX_BLOCKS_PER_SECTION) {
                $warnings[] = 'block_list_truncated_for: ' . (string) $section['type'];
            }
            $accepted++;
        }

        if ($accepted === 0 && $errors === []) {
            $errors[] = 'no_importable_sections_found';
        }

        return [
            'valid'         => $errors === [],
            'errors'        => $errors,
            'warnings'      => $warnings,
            'section_count' => $accepted,
        ];
    }

    /**
     * Import a validated payload into a NEW theme (never overwrites an existing one) with a single
     * draft version. The caller publishes it explicitly, so importing can't change the live store.
     *
     * @return array{theme:?Theme, version:?ThemeVersion, result:array}
     */
    public function import(mixed $payload, ?string $nameOverride = null, ?int $adminId = null): array
    {
        $result = $this->validate($payload);
        if (!$result['valid']) {
            return ['theme' => null, 'version' => null, 'result' => $result];
        }

        $name = $nameOverride
            ?: (string) ($payload['theme']['name'] ?? 'Imported theme');
        $name = trim($name) !== '' ? trim($name) : 'Imported theme';

        return DB::transaction(function () use ($payload, $name, $adminId, $result) {
            $theme = Theme::create([
                'name'            => $name,
                'slug'            => $this->uniqueSlug($name),
                'description'     => is_string($payload['theme']['description'] ?? null)
                    ? $payload['theme']['description'] : null,
                'is_active'       => false,   // never auto-activate an imported theme
                'is_system'       => false,   // never let a payload claim system status
                'created_by_type' => 'admin',
                'created_by_id'   => $adminId,
            ]);

            $version = ThemeVersion::create([
                'theme_id' => $theme->id,
                'label'    => 'Imported',
                'status'   => ThemeVersion::STATUS_DRAFT,   // always a draft
                'settings' => $this->sanitizeGlobalSettings($payload['settings'] ?? []),
            ]);

            $order = [];
            foreach (($payload['sections'] ?? []) as $raw) {
                if (!is_array($raw) || empty($raw['type']) || !$this->registry->has((string) $raw['type'])) {
                    continue;
                }
                $page = is_string($raw['page'] ?? null) ? $raw['page'] : 'home';
                $order[$page] = ($order[$page] ?? 0) + 1;

                $section = ThemeSection::create([
                    'theme_version_id' => $version->id,
                    'page'             => $page,
                    'type'             => (string) $raw['type'],
                    'sort_order'       => $order[$page],   // re-sequenced; the file's order is not trusted
                    'is_visible'       => (bool) ($raw['is_visible'] ?? true),
                    'settings'         => $this->registry->normalizeSettings(
                        (string) $raw['type'],
                        is_array($raw['settings'] ?? null) ? $raw['settings'] : []
                    ),
                ]);

                $blocks = is_array($raw['blocks'] ?? null) ? array_slice($raw['blocks'], 0, self::MAX_BLOCKS_PER_SECTION) : [];
                $blockOrder = 0;
                foreach ($blocks as $block) {
                    if (!is_array($block) || empty($block['type'])) {
                        continue;
                    }
                    ThemeBlock::create([
                        'theme_section_id' => $section->id,
                        'type'             => (string) $block['type'],
                        'sort_order'       => ++$blockOrder,
                        'is_visible'       => (bool) ($block['is_visible'] ?? true),
                        'settings'         => is_array($block['settings'] ?? null) ? $block['settings'] : [],
                    ]);
                }
            }

            return ['theme' => $theme->refresh(), 'version' => $version->refresh(), 'result' => $result];
        });
    }

    /** Built-in starting points a merchant can import in one click. */
    public function presets(): array
    {
        return [
            'starter' => [
                'label'   => 'starter_storefront',
                'payload' => [
                    'format_version' => self::FORMAT_VERSION,
                    'theme'    => ['name' => 'Starter storefront'],
                    'settings' => ['colors' => ['primary' => '#0f766e']],
                    'sections' => [
                        ['page' => 'home', 'type' => 'hero_banner',     'sort_order' => 1, 'is_visible' => true, 'settings' => []],
                        ['page' => 'home', 'type' => 'category_grid',   'sort_order' => 2, 'is_visible' => true, 'settings' => ['limit' => 12]],
                        ['page' => 'home', 'type' => 'product_slider',  'sort_order' => 3, 'is_visible' => true, 'settings' => ['source' => 'featured', 'limit' => 10]],
                        ['page' => 'home', 'type' => 'brand_slider',    'sort_order' => 4, 'is_visible' => true, 'settings' => []],
                        ['page' => 'footer', 'type' => 'footer_columns','sort_order' => 1, 'is_visible' => true, 'settings' => ['columns' => 4]],
                    ],
                ],
            ],
            'promotional' => [
                'label'   => 'promotional_storefront',
                'payload' => [
                    'format_version' => self::FORMAT_VERSION,
                    'theme'    => ['name' => 'Promotional storefront'],
                    'settings' => ['colors' => ['primary' => '#c2410c', 'accent' => '#f59e0b']],
                    'sections' => [
                        ['page' => 'header', 'type' => 'announcement_bar', 'sort_order' => 1, 'is_visible' => true, 'settings' => []],
                        ['page' => 'home', 'type' => 'hero_banner',        'sort_order' => 1, 'is_visible' => true, 'settings' => []],
                        ['page' => 'home', 'type' => 'flash_deal',         'sort_order' => 2, 'is_visible' => true, 'settings' => ['countdown' => true]],
                        ['page' => 'home', 'type' => 'product_slider',     'sort_order' => 3, 'is_visible' => true, 'settings' => ['source' => 'best_selling']],
                        ['page' => 'home', 'type' => 'promotional_banner', 'sort_order' => 4, 'is_visible' => true, 'settings' => []],
                        ['page' => 'home', 'type' => 'newsletter',         'sort_order' => 5, 'is_visible' => true, 'settings' => []],
                    ],
                ],
            ],
            // A professional cosmetics/beauty storefront: a full home composed for the Minimal Luxury
            // renderer (refined neutrals, gold accent, generous spacing). One-click starting point.
            'minimal_luxury' => [
                'label'   => 'minimal_luxury_beauty',
                'payload' => [
                    'format_version' => self::FORMAT_VERSION,
                    'theme'    => ['name' => 'Minimal Luxury', 'description' => 'An elegant, editorial storefront for beauty & cosmetics.'],
                    'settings' => [
                        'colors' => [
                            'primary' => '#1c1917', 'secondary' => '#8a8079', 'accent' => '#b08d57',
                            'background' => '#ffffff', 'surface' => '#f7f3ee', 'text' => '#1c1917',
                            'muted_text' => '#8a8079', 'border' => '#e7e1d8',
                        ],
                        'typography' => ['base_font_size' => 16, 'line_height' => 1.7],
                        'layout' => ['container_width' => 1240, 'page_spacing' => 32, 'border_radius' => 2, 'button_style' => 'square', 'card_style' => 'flat'],
                    ],
                    'sections' => [
                        ['page' => 'home', 'type' => 'hero_banner',        'sort_order' => 1, 'is_visible' => true, 'settings' => ['height' => 560, 'interval' => 6000]],
                        ['page' => 'home', 'type' => 'category_grid',      'sort_order' => 2, 'is_visible' => true, 'settings' => ['title' => 'Shop by Category', 'limit' => 6, 'columns' => 6, 'padding_top' => 72, 'padding_bottom' => 40]],
                        ['page' => 'home', 'type' => 'product_slider',     'sort_order' => 3, 'is_visible' => true, 'settings' => ['title' => 'New Arrivals', 'source' => 'new_arrival', 'limit' => 8, 'columns' => 4, 'padding_top' => 40, 'padding_bottom' => 40]],
                        ['page' => 'home', 'type' => 'promotional_banner', 'sort_order' => 4, 'is_visible' => true, 'settings' => ['columns' => 2, 'padding_top' => 32, 'padding_bottom' => 32]],
                        ['page' => 'home', 'type' => 'product_slider',     'sort_order' => 5, 'is_visible' => true, 'settings' => ['title' => 'Bestsellers', 'source' => 'best_selling', 'limit' => 8, 'columns' => 4, 'padding_top' => 40, 'padding_bottom' => 40]],
                        ['page' => 'home', 'type' => 'brand_slider',       'sort_order' => 6, 'is_visible' => true, 'settings' => ['title' => 'Our Brands', 'padding_top' => 40, 'padding_bottom' => 40, 'background' => '#f7f3ee']],
                        ['page' => 'home', 'type' => 'newsletter',         'sort_order' => 7, 'is_visible' => true, 'settings' => ['title' => 'Join the List', 'subtitle' => 'Be first to know about new arrivals and exclusive offers.', 'padding_top' => 0, 'padding_bottom' => 0]],
                    ],
                ],
            ],
        ];
    }

    /** Keep only known global-settings groups/keys, coerced to the default's type. */
    private function sanitizeGlobalSettings(mixed $settings): array
    {
        if (!is_array($settings)) {
            return [];
        }

        $schema = $this->manager->defaultSettings();
        $clean = [];

        foreach ($schema as $group => $fields) {
            if (!is_array($fields) || !isset($settings[$group]) || !is_array($settings[$group])) {
                continue;
            }
            foreach ($fields as $key => $default) {
                if (!array_key_exists($key, $settings[$group])) {
                    continue;
                }
                $value = $settings[$group][$key];
                if (is_int($default)) {
                    $clean[$group][$key] = is_numeric($value) ? (int) $value : $default;
                } elseif (is_float($default)) {
                    $clean[$group][$key] = is_numeric($value) ? (float) $value : $default;
                } else {
                    $clean[$group][$key] = is_scalar($value) ? trim((string) $value) : $default;
                }
            }
        }

        return $clean;
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'theme';
        $slug = $base;
        $i = 2;
        while (Theme::where('slug', $slug)->exists()) {
            $slug = $base . '-' . $i++;
        }
        return $slug;
    }
}
