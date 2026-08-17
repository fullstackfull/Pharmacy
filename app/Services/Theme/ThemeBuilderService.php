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

    // -----------------------------------------------------------------------------------------
    // Blocks — the repeatable children of a section (hero slides, promo tiles, footer columns).
    //
    // Same guard rails as sections: draft-only, schema-normalized settings, and a type must be one
    // the parent section actually declares, so a crafted payload cannot graft a footer column into
    // a hero carousel.
    // -----------------------------------------------------------------------------------------

    /** Append a block to a section. Returns null when the section is not editable or rejects the type. */
    public function addBlock(ThemeSection $section, string $type, array $settings = []): ?ThemeBlock
    {
        if (!$this->isEditable($section->version) || !$this->registry->hasBlockType($section->type, $type)) {
            return null;
        }

        if ($section->blocks()->count() >= SectionRegistry::MAX_BLOCKS_PER_SECTION) {
            return null;
        }

        $nextOrder = (int) ThemeBlock::where('theme_section_id', $section->id)->max('sort_order');

        return ThemeBlock::create([
            'theme_section_id' => $section->id,
            'type'             => $type,
            'sort_order'       => $nextOrder + 1,
            'is_visible'       => true,
            'settings'         => $this->registry->normalizeBlockSettings($type, $settings),
        ]);
    }

    public function updateBlock(ThemeBlock $block, array $settings): bool
    {
        if (!$block->section || !$this->isEditable($block->section->version)) {
            return false;
        }

        $block->settings = $this->registry->normalizeBlockSettings($block->type, $settings);
        return $block->save();
    }

    public function setBlockVisibility(ThemeBlock $block, bool $visible): bool
    {
        if (!$block->section || !$this->isEditable($block->section->version)) {
            return false;
        }

        $block->is_visible = $visible;
        return $block->save();
    }

    /** Reorder a section's blocks; ids not belonging to it are ignored, omitted ones keep their tail order. */
    public function reorderBlocks(ThemeSection $section, array $orderedIds): bool
    {
        if (!$this->isEditable($section->version)) {
            return false;
        }

        return DB::transaction(function () use ($section, $orderedIds) {
            $byId = ThemeBlock::where('theme_section_id', $section->id)
                ->orderBy('sort_order')->get()->keyBy('id');
            $order = 1;

            foreach ($orderedIds as $id) {
                $block = $byId->get((int) $id);
                if ($block) {
                    $block->sort_order = $order++;
                    $block->save();
                    $byId->forget((int) $id);
                }
            }
            foreach ($byId as $block) {
                $block->sort_order = $order++;
                $block->save();
            }

            return true;
        });
    }

    public function duplicateBlock(ThemeBlock $block): ?ThemeBlock
    {
        $section = $block->section;
        if (!$section || !$this->isEditable($section->version)) {
            return null;
        }

        if ($section->blocks()->count() >= SectionRegistry::MAX_BLOCKS_PER_SECTION) {
            return null;
        }

        return DB::transaction(function () use ($block, $section) {
            ThemeBlock::where('theme_section_id', $section->id)
                ->where('sort_order', '>', $block->sort_order)
                ->increment('sort_order');

            return ThemeBlock::create([
                'theme_section_id' => $section->id,
                'type'             => $block->type,
                'sort_order'       => $block->sort_order + 1,
                'is_visible'       => $block->is_visible,
                'settings'         => $block->settings,
            ]);
        });
    }

    public function deleteBlock(ThemeBlock $block): bool
    {
        $section = $block->section;
        if (!$section || !$this->isEditable($section->version)) {
            return false;
        }

        return DB::transaction(function () use ($block, $section) {
            $order = $block->sort_order;
            $block->delete();

            ThemeBlock::where('theme_section_id', $section->id)
                ->where('sort_order', '>', $order)
                ->decrement('sort_order');

            return true;
        });
    }

    /** The block list of one section, shaped for the builder panel. */
    public function getBlocks(ThemeSection $section): array
    {
        return $section->blocks()->orderBy('sort_order')->get()
            ->map(fn (ThemeBlock $block) => $this->presentBlock($block))
            ->all();
    }

    private function presentBlock(ThemeBlock $block): array
    {
        $settings = $block->settings ?? [];
        $imageKey = $this->registry->blockImageKey($block->type);

        return [
            'id'         => $block->id,
            'type'       => $block->type,
            'label'      => $this->registry->blockLabel($block->type, $settings),
            'image'      => $imageKey ? ($settings[$imageKey] ?? null) : null,
            'sort_order' => $block->sort_order,
            'is_visible' => $block->is_visible,
            'settings'   => $settings,
        ];
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
                'accepts'    => $this->registry->blockTypesFor($s->type),
                'blocks'     => $s->blocks->map(fn (ThemeBlock $b) => $this->presentBlock($b))->all(),
            ])->all();
    }
}
