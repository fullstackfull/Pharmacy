<?php

namespace App\Services\Theme;

use App\Models\ThemeBlock;
use App\Models\ThemeSection;
use App\Models\ThemeVersion;
use Illuminate\Support\Facades\DB;

/**
 * Mutations the visual Theme Builder performs on a DRAFT version (Phase 1.2).
 *
 * Guard rail: every mutation refuses to touch a published version. Editing is always done on a
 * draft, and going live is an explicit publish — that is what makes the
 * draft -> preview -> publish workflow safe (the live storefront can never be edited by accident).
 */
class ThemeBuilderService
{
    public function __construct(private readonly SectionRegistry $registry)
    {
    }

    /** Thrown-free guard: is this version editable? */
    public function isEditable(ThemeVersion $version): bool
    {
        return $version->status === ThemeVersion::STATUS_DRAFT;
    }

    /** Append a section to a page, at the end of the current order. */
    public function addSection(ThemeVersion $version, string $page, string $type, array $settings = []): ?ThemeSection
    {
        if (!$this->isEditable($version) || !$this->registry->has($type)) {
            return null;
        }

        $nextOrder = (int) ThemeSection::where('theme_version_id', $version->id)
            ->where('page', $page)->max('sort_order');

        return ThemeSection::create([
            'theme_version_id' => $version->id,
            'page'             => $page,
            'type'             => $type,
            'sort_order'       => $nextOrder + 1,
            'is_visible'       => true,
            'settings'         => $this->registry->normalizeSettings($type, $settings),
        ]);
    }

    /** Update a section's settings (normalized against its schema — unknown keys are dropped). */
    public function updateSection(ThemeSection $section, array $settings): bool
    {
        if (!$this->isEditable($section->version)) {
            return false;
        }

        $section->settings = $this->registry->normalizeSettings($section->type, $settings);
        return $section->save();
    }

    public function setSectionVisibility(ThemeSection $section, bool $visible): bool
    {
        if (!$this->isEditable($section->version)) {
            return false;
        }
        $section->is_visible = $visible;
        return $section->save();
    }

    /**
     * Reorder a page's sections to match $orderedIds. Ids not belonging to this version/page are
     * ignored, and any section omitted from the list keeps a stable position after the listed ones,
     * so a partial payload can never silently drop a section.
     */
    public function reorderSections(ThemeVersion $version, string $page, array $orderedIds): bool
    {
        if (!$this->isEditable($version)) {
            return false;
        }

        return DB::transaction(function () use ($version, $page, $orderedIds) {
            $sections = ThemeSection::where('theme_version_id', $version->id)
                ->where('page', $page)->orderBy('sort_order')->get();

            $byId = $sections->keyBy('id');
            $order = 1;

            foreach ($orderedIds as $id) {
                $section = $byId->get((int) $id);
                if ($section) {
                    $section->sort_order = $order++;
                    $section->save();
                    $byId->forget((int) $id);
                }
            }
            // anything not mentioned keeps its relative order, appended after the listed ones
            foreach ($byId as $section) {
                $section->sort_order = $order++;
                $section->save();
            }

            return true;
        });
    }

    /** Duplicate a section (with its blocks) directly after the original. */
    public function duplicateSection(ThemeSection $section): ?ThemeSection
    {
        if (!$this->isEditable($section->version)) {
            return null;
        }

        return DB::transaction(function () use ($section) {
            // push following sections down to make room
            ThemeSection::where('theme_version_id', $section->theme_version_id)
                ->where('page', $section->page)
                ->where('sort_order', '>', $section->sort_order)
                ->increment('sort_order');

            $copy = ThemeSection::create([
                'theme_version_id' => $section->theme_version_id,
                'page'             => $section->page,
                'type'             => $section->type,
                'sort_order'       => $section->sort_order + 1,
                'is_visible'       => $section->is_visible,
                'settings'         => $section->settings,
            ]);

            foreach ($section->blocks as $block) {
                ThemeBlock::create([
                    'theme_section_id' => $copy->id,
                    'type'             => $block->type,
                    'sort_order'       => $block->sort_order,
                    'is_visible'       => $block->is_visible,
                    'settings'         => $block->settings,
                ]);
            }

            return $copy;
        });
    }

    /** Delete a section and close the gap in the ordering. */
    public function deleteSection(ThemeSection $section): bool
    {
        if (!$this->isEditable($section->version)) {
            return false;
        }

        return DB::transaction(function () use ($section) {
            $versionId = $section->theme_version_id;
            $page = $section->page;
            $order = $section->sort_order;

            $section->delete();

            ThemeSection::where('theme_version_id', $versionId)
                ->where('page', $page)
                ->where('sort_order', '>', $order)
                ->decrement('sort_order');

            return true;
        });
    }

    /** The full ordered structure of a page — what the builder's left panel renders. */
    public function getPageStructure(ThemeVersion $version, string $page): array
    {
        return ThemeSection::with('blocks')
            ->where('theme_version_id', $version->id)
            ->where('page', $page)
            ->orderBy('sort_order')
            ->get()
            ->map(fn (ThemeSection $s) => [
                'id'         => $s->id,
                'type'       => $s->type,
                'label'      => $this->registry->types()[$s->type]['label'] ?? $s->type,
                'sort_order' => $s->sort_order,
                'is_visible' => $s->is_visible,
                'settings'   => $s->settings,
                'blocks'     => $s->blocks->map(fn (ThemeBlock $b) => [
                    'id' => $b->id, 'type' => $b->type,
                    'sort_order' => $b->sort_order, 'is_visible' => $b->is_visible,
                    'settings' => $b->settings,
                ])->all(),
            ])->all();
    }
}
