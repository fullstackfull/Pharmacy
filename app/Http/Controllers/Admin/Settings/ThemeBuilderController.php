<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\BaseController;
use App\Models\ThemeSection;
use App\Models\ThemeVersion;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Http\RedirectResponse;
use App\Services\Theme\SectionRegistry;
use App\Services\Theme\StorefrontThemeRenderer;
use App\Services\Theme\ThemeBuilderService;
use App\Services\Theme\ThemeManager;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Visual Theme Builder (Phase 1.2).
 *
 * The editor UI is a thin client over ThemeBuilderService: it renders the structure panel from
 * getPageStructure(), the settings panel from the SectionRegistry schema, and posts mutations back
 * as JSON. Everything is scoped to a DRAFT version — the service refuses published ones, so the
 * live storefront cannot be edited by accident.
 */
class ThemeBuilderController extends BaseController
{
    public function __construct(
        private readonly ThemeBuilderService $builder,
        private readonly SectionRegistry     $registry,
        private readonly ThemeManager        $themeManager,
    )
    {
    }

    public function index(Request|null $request, ?string $type = null): View
    {
        $versionId = $request?->get('version');
        $version = $versionId
            ? ThemeVersion::find($versionId)
            : $this->resolveEditableDraft();

        $page = $request?->get('page', 'home') ?: 'home';

        return view('admin-views.theme.builder', [
            'version'       => $version,
            'page'          => $page,
            'structure'     => $version ? $this->builder->getPageStructure($version, $page) : [],
            'sectionTypes'  => $version ? $this->registry->forPage($page) : [],
            'themeSettings' => $this->themeManager->resolveSettings($version),
            'pages'         => ['home', 'header', 'footer'],
            'editable'      => $version ? $this->builder->isEditable($version) : false,
        ]);
    }

    public function addSection(Request $request): JsonResponse
    {
        $version = ThemeVersion::find($request['version_id']);
        if (!$version) {
            return $this->fail(translate('theme_version_not_found'));
        }

        $section = $this->builder->addSection($version, (string) $request['page'], (string) $request['type']);

        return $section
            ? $this->ok(['section' => $this->builder->getPageStructure($version, (string) $request['page'])])
            : $this->fail(translate('this_section_could_not_be_added_the_version_may_be_published'));
    }

    public function updateSection(Request $request): JsonResponse
    {
        $section = ThemeSection::find($request['section_id']);
        if (!$section) {
            return $this->fail(translate('section_not_found'));
        }

        $settings = $request->get('settings', []);
        $saved = $this->builder->updateSection($section, is_array($settings) ? $settings : []);

        return $saved
            ? $this->ok(['settings' => $section->fresh()->settings])
            : $this->fail(translate('published_versions_cannot_be_edited_duplicate_it_to_a_draft_first'));
    }

    public function reorderSections(Request $request): JsonResponse
    {
        $version = ThemeVersion::find($request['version_id']);
        if (!$version) {
            return $this->fail(translate('theme_version_not_found'));
        }

        $ids = $request->get('order', []);
        $done = $this->builder->reorderSections($version, (string) $request['page'], is_array($ids) ? $ids : []);

        return $done ? $this->ok() : $this->fail(translate('published_versions_cannot_be_edited_duplicate_it_to_a_draft_first'));
    }

    public function toggleSection(Request $request): JsonResponse
    {
        $section = ThemeSection::find($request['section_id']);
        if (!$section) {
            return $this->fail(translate('section_not_found'));
        }

        $done = $this->builder->setSectionVisibility($section, $request->boolean('visible'));

        return $done ? $this->ok(['is_visible' => $section->fresh()->is_visible]) : $this->fail(translate('published_versions_cannot_be_edited_duplicate_it_to_a_draft_first'));
    }

    public function duplicateSection(Request $request): JsonResponse
    {
        $section = ThemeSection::find($request['section_id']);
        if (!$section) {
            return $this->fail(translate('section_not_found'));
        }

        $copy = $this->builder->duplicateSection($section);

        return $copy ? $this->ok(['id' => $copy->id]) : $this->fail(translate('published_versions_cannot_be_edited_duplicate_it_to_a_draft_first'));
    }

    public function deleteSection(Request $request): JsonResponse
    {
        $section = ThemeSection::find($request['section_id']);
        if (!$section) {
            return $this->fail(translate('section_not_found'));
        }

        $done = $this->builder->deleteSection($section);

        return $done ? $this->ok() : $this->fail(translate('published_versions_cannot_be_edited_duplicate_it_to_a_draft_first'));
    }

    /**
     * Settings schema for a section type, together with the SAVED settings of the section being
     * edited — the right-hand panel renders its form from both.
     *
     * The saved settings are not optional extra data. Without them the form fell back to schema
     * defaults for every field, so opening a configured section showed defaults, and because the
     * autosave posts the whole form, editing one field overwrote every other setting on that
     * section with its default. That is silent data loss on a merchant's theme.
     */
    public function sectionSchema(Request $request): JsonResponse
    {
        $type = (string) $request['type'];

        if (!$this->registry->has($type)) {
            return $this->fail(translate('unknown_section_type'));
        }

        $settings = [];
        if ($request->filled('section_id')) {
            $section = ThemeSection::find($request['section_id']);
            // Normalising here means the form receives coerced values and drops stale keys, exactly
            // as the storefront sees them — so what is edited is what renders.
            if ($section && $section->type === $type) {
                $settings = $this->registry->normalizeSettings($type, $section->settings ?? []);
            }
        }

        return $this->ok([
            'schema'   => $this->registry->schemaFor($type),
            'settings' => $settings,
        ]);
    }

    /** The active theme's draft, creating one from the published version when needed. */
    private function resolveEditableDraft(): ?ThemeVersion
    {
        $published = $this->themeManager->activeThemePublishedVersion();
        if ($published) {
            $draft = ThemeVersion::where('theme_id', $published->theme_id)
                ->where('status', ThemeVersion::STATUS_DRAFT)
                ->latest('id')->first();

            return $draft ?: $this->themeManager->createDraftFrom($published);
        }

        return ThemeVersion::where('status', ThemeVersion::STATUS_DRAFT)->latest('id')->first();
    }

    private function ok(array $data = []): JsonResponse
    {
        return response()->json(['status' => 'success'] + $data);
    }

    private function fail(string $message): JsonResponse
    {
        return response()->json(['status' => 'error', 'message' => $message], 422);
    }
    /**
     * Preview a DRAFT on the real storefront (Phase 1.2 draft -> preview -> publish).
     *
     * The version id is stored in the ADMIN SESSION, not the URL: a ?preview_version=N link could be
     * shared or crawled, exposing an unpublished design to customers and to search engines. The
     * renderer additionally requires an authenticated admin, so the preview cannot leak even if the
     * session cookie were replayed by a guest.
     */
    public function startPreview(Request $request): RedirectResponse
    {
        $version = ThemeVersion::find($request['version_id']);
        if (!$version) {
            ToastMagic::error(translate('theme_version_not_found') . '!');
            return back();
        }

        session([StorefrontThemeRenderer::PREVIEW_SESSION_KEY => $version->id]);
        ToastMagic::success(translate('previewing_draft_on_the_storefront') . ' #' . $version->id);

        return redirect('/');
    }

    public function stopPreview(): RedirectResponse
    {
        session()->forget(StorefrontThemeRenderer::PREVIEW_SESSION_KEY);
        ToastMagic::success(translate('preview_ended'));

        return redirect()->route('admin.theme.builder.index');
    }

}
