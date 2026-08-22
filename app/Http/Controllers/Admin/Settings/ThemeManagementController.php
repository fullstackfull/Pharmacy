<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Contracts\Repositories\ThemeRepositoryInterface;
use App\Http\Controllers\BaseController;
use App\Models\Theme;
use App\Models\ThemeVersion;
use App\Services\Theme\ThemeManager;
use App\Services\Theme\ThemePermissionService;
use App\Services\Theme\ThemePortabilityService;
use App\Services\Theme\ThemeAssetService;
use App\Models\ThemeAsset;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Admin management for the Theme System (Phase 1.1 UI).
 *
 * Covers the non-visual half of the theme workflow — list, create, duplicate a version into a new
 * draft, publish a draft, and activate a theme. The visual editor (Phase 1.2) builds on the same
 * services. All actions are non-destructive: publishing archives the previous version rather than
 * overwriting it, so rollback is always possible.
 */
class ThemeManagementController extends BaseController
{
    public function __construct(
        private readonly ThemeRepositoryInterface $themeRepo,
        private readonly ThemeManager             $themeManager,
        private readonly ThemePermissionService   $permissions,
        private readonly ThemePortabilityService  $portability,
        private readonly ThemeAssetService         $assets,
    )
    {
    }

    public function index(Request|null $request, ?string $type = null): View
    {
        // Assets arrived in a later migration than themes, so an installation that has run some but
        // not all migrations must still render this page rather than 500 on a missing table.
        $relations = Schema::hasTable('theme_assets') ? ['versions', 'assets'] : ['versions'];

        $themes = $this->themeRepo->getListWhere(
            orderBy: ['id' => 'desc'],
            searchValue: $request?->get('searchValue'),
            relations: $relations,
            dataLimit: 20,
        );

        return view('admin-views.theme.index', [
            'themes'       => $themes,
            'search'       => $request?->get('searchValue'),
            'presets'      => $this->portability->presets(),
            'assetsReady'  => Schema::hasTable('theme_assets'),
            'maxAssetSize' => ThemeAssetService::maxBytes(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        if ($this->blockedOnDemo()) {
            return $this->backToIndex();
        }

        $validated = $request->validate([
            'name'        => ['required', 'string', 'max:191'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        $slug = Str::slug($validated['name']);
        if ($slug === '' || $this->themeRepo->slugExists($slug)) {
            ToastMagic::error(translate('a_theme_with_this_name_already_exists') . '!');
            return back()->withInput();
        }

        $this->themeManager->createTheme([
            'name'            => $validated['name'],
            'slug'            => $slug,
            'description'     => $validated['description'] ?? null,
            'is_active'       => false,
            'is_system'       => false,
            'created_by_type' => 'admin',
            'created_by_id'   => auth('admin')->id(),
        ]);

        ToastMagic::success(translate('theme_created_successfully'));
        return $this->backToIndex();
    }

    /** Activate a theme (exclusive — all others are deactivated atomically). */
    public function activate(Request $request): RedirectResponse
    {
        if ($this->blockedOnDemo()) {
            return $this->backToIndex();
        }

        if (!$this->permissions->canPublish()) {
            ToastMagic::error(translate('you_do_not_have_permission_to_publish_a_theme') . '!');
            return $this->backToIndex();
        }

        $theme = $this->themeRepo->getFirstWhere(params: ['id' => $request['id']]);
        if (!$theme instanceof Theme) {
            ToastMagic::error(translate('theme_not_found') . '!');
            return $this->backToIndex();
        }

        $this->themeManager->activate($theme);
        ToastMagic::success(translate('theme_activated_successfully'));

        return $this->backToIndex();
    }

    /** Delete a theme with all its versions and stored asset files. Active and system themes are protected. */
    public function delete(Request $request): RedirectResponse
    {
        if ($this->blockedOnDemo()) {
            return $this->backToIndex();
        }

        if (!$this->permissions->canPublish()) {
            ToastMagic::error(translate('you_do_not_have_permission_to_publish_a_theme') . '!');
            return $this->backToIndex();
        }

        $theme = $this->themeRepo->getFirstWhere(params: ['id' => $request['id']]);
        if (!$theme instanceof Theme) {
            ToastMagic::error(translate('theme_not_found') . '!');
            return $this->backToIndex();
        }

        if ($theme->is_active || $theme->is_system) {
            ToastMagic::error(translate('active_and_system_themes_cannot_be_deleted') . '!');
            return $this->backToIndex();
        }

        try {
            \Illuminate\Support\Facades\Storage::disk('public')->deleteDirectory('theme-assets/' . $theme->id);
        } catch (\Throwable) {
            // missing files must not block removing the theme; versions/sections/assets rows cascade
        }
        $theme->delete();
        ToastMagic::success(translate('theme_deleted_successfully'));

        return $this->backToIndex();
    }

    /** Publish a draft version (previous published version becomes archived). */
    public function publishVersion(Request $request): RedirectResponse
    {
        if ($this->blockedOnDemo()) {
            return $this->backToIndex();
        }

        if (!$this->permissions->canPublish()) {
            ToastMagic::error(translate('you_do_not_have_permission_to_publish_a_theme') . '!');
            return $this->backToIndex();
        }

        $version = ThemeVersion::find($request['version_id']);
        if (!$version) {
            ToastMagic::error(translate('theme_version_not_found') . '!');
            return $this->backToIndex();
        }

        $this->themeManager->publish($version);
        ToastMagic::success(translate('theme_version_published_successfully'));

        return $this->backToIndex();
    }

    /** Duplicate a version into a fresh draft (revision / edit-a-copy workflow). */
    public function duplicateVersion(Request $request): RedirectResponse
    {
        if ($this->blockedOnDemo()) {
            return $this->backToIndex();
        }

        $version = ThemeVersion::find($request['version_id']);
        if (!$version) {
            ToastMagic::error(translate('theme_version_not_found') . '!');
            return $this->backToIndex();
        }

        $this->themeManager->createDraftFrom($version);
        ToastMagic::success(translate('draft_created_successfully'));

        return $this->backToIndex();
    }

    /** Restore an archived/older version into a new draft (non-destructive). */
    public function restoreVersion(Request $request): RedirectResponse
    {
        if ($this->blockedOnDemo()) {
            return $this->backToIndex();
        }
        if (!$this->permissions->canEdit()) {
            ToastMagic::error(translate('you_do_not_have_permission_to_edit_a_theme') . '!');
            return $this->backToIndex();
        }

        $version = ThemeVersion::find($request['version_id']);
        if (!$version) {
            ToastMagic::error(translate('theme_version_not_found') . '!');
            return $this->backToIndex();
        }

        $draft = $this->themeManager->restoreVersion($version);
        ToastMagic::success(translate('version_restored_into_a_new_draft') . ' #' . $draft->id);

        return $this->backToIndex();
    }

    /** Download a version as a portable JSON file. */
    public function exportVersion(Request $request): StreamedResponse|RedirectResponse
    {
        if (!$this->permissions->canView()) {
            ToastMagic::error(translate('you_do_not_have_permission_to_view_a_theme') . '!');
            return $this->backToIndex();
        }

        $version = ThemeVersion::with('theme')->find($request['version_id']);
        if (!$version) {
            ToastMagic::error(translate('theme_version_not_found') . '!');
            return $this->backToIndex();
        }

        $payload = $this->portability->export($version);
        $filename = 'theme-' . ($version->theme?->slug ?? 'export') . '-v' . $version->id . '.json';

        return response()->streamDownload(function () use ($payload) {
            echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }, $filename, ['Content-Type' => 'application/json']);
    }

    /**
     * Download the example theme file.
     *
     * Same payload the Minimal Luxury preset imports, handed over as a .json so a merchant can see
     * exactly what a theme file looks like, edit it, and import it back.
     */
    public function downloadExample(): StreamedResponse
    {
        $payload = $this->portability->exampleTheme();

        return response()->streamDownload(function () use ($payload) {
            echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }, 'example-theme-minimal-luxury.json', ['Content-Type' => 'application/json']);
    }

    /** Import an uploaded theme file into a NEW inactive theme (never overwrites, never publishes). */
    public function importTheme(Request $request): RedirectResponse
    {
        if ($this->blockedOnDemo()) {
            return $this->backToIndex();
        }
        if (!$this->permissions->canEdit()) {
            ToastMagic::error(translate('you_do_not_have_permission_to_edit_a_theme') . '!');
            return $this->backToIndex();
        }

        $request->validate([
            'theme_file' => ['required', 'file', 'mimetypes:application/json,text/plain', 'max:2048'],
            'name'       => ['nullable', 'string', 'max:191'],
        ]);

        $decoded = json_decode(file_get_contents($request->file('theme_file')->getRealPath()), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            ToastMagic::error(translate('the_file_is_not_valid_json') . '!');
            return $this->backToIndex();
        }

        $imported = $this->portability->import($decoded, $request->get('name'), auth('admin')->id());

        if (!$imported['theme']) {
            ToastMagic::error(translate('import_failed') . ': ' . implode(', ', $imported['result']['errors']));
            return $this->backToIndex();
        }

        foreach ($imported['result']['warnings'] as $warning) {
            ToastMagic::warning(translate($warning));
        }
        ToastMagic::success(translate('theme_imported_successfully'));

        return $this->backToIndex();
    }

    /** Create a theme from a built-in preset. */
    public function importPreset(Request $request): RedirectResponse
    {
        if ($this->blockedOnDemo()) {
            return $this->backToIndex();
        }
        if (!$this->permissions->canEdit()) {
            ToastMagic::error(translate('you_do_not_have_permission_to_edit_a_theme') . '!');
            return $this->backToIndex();
        }

        $presets = $this->portability->presets();
        $key = (string) $request['preset'];
        if (!array_key_exists($key, $presets)) {
            ToastMagic::error(translate('unknown_preset') . '!');
            return $this->backToIndex();
        }

        $imported = $this->portability->import($presets[$key]['payload'], $request->get('name'), auth('admin')->id());
        $imported['theme']
            ? ToastMagic::success(translate('theme_created_from_preset_successfully'))
            : ToastMagic::error(translate('import_failed') . '!');

        return $this->backToIndex();
    }

    /** Upload an image asset for a theme (logo, favicon, background). */
    public function uploadAsset(Request $request): RedirectResponse
    {
        if ($this->blockedOnDemo()) {
            return $this->backToIndex();
        }
        if (!$this->permissions->canEdit()) {
            ToastMagic::error(translate('you_do_not_have_permission_to_edit_a_theme') . '!');
            return $this->backToIndex();
        }

        // The `max` here only produces a friendlier error early; the service re-checks the size and
        // sniffs the real MIME type, because request validation trusts the client-supplied type.
        $request->validate([
            'asset'    => ['required', 'file', 'max:' . (int) (ThemeAssetService::maxBytes() / 1024)],
            'theme_id' => ['required', 'integer'],
            'label'    => ['nullable', 'string', 'max:120'],
        ]);

        $theme = $this->themeRepo->getFirstWhere(params: ['id' => $request['theme_id']]);
        if (!$theme instanceof Theme) {
            ToastMagic::error(translate('theme_not_found') . '!');
            return $this->backToIndex();
        }

        $result = $this->assets->upload($theme, $request->file('asset'), $request->get('label'), auth('admin')->id());
        $result['asset']
            ? ToastMagic::success(translate('asset_uploaded_successfully'))
            : ToastMagic::error(translate($result['error'] ?? 'the_upload_failed'));

        return $this->backToIndex();
    }

    public function deleteAsset(Request $request): RedirectResponse
    {
        if ($this->blockedOnDemo()) {
            return $this->backToIndex();
        }
        if (!$this->permissions->canEdit()) {
            ToastMagic::error(translate('you_do_not_have_permission_to_edit_a_theme') . '!');
            return $this->backToIndex();
        }

        $asset = ThemeAsset::find($request['id']);
        if ($asset) {
            $this->assets->delete($asset);
            ToastMagic::success(translate('asset_deleted_successfully'));
        }

        return $this->backToIndex();
    }

    private function blockedOnDemo(): bool
    {
        if (config('app.mode') == 'demo') {
            ToastMagic::error(translate('you_can_not_update_this_on_demo_mode'));
            return true;
        }
        return false;
    }

    /**
     * Back to Theme Management, or to the builder page the action was fired from.
     *
     * The builder's go-live bar activates and publishes without leaving the canvas, so a merchant
     * who clicks "publish" there stays on the page they were composing.
     */
    private function backToIndex(): RedirectResponse
    {
        $builderPage = request('return_to_builder');
        if ($builderPage && in_array($builderPage, ['home', 'header', 'footer'], true)) {
            return redirect()->route('admin.theme.builder.index', ['page' => $builderPage]);
        }

        return redirect()->route('admin.theme.index');
    }
}
