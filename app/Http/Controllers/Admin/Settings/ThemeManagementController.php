<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Contracts\Repositories\ThemeRepositoryInterface;
use App\Http\Controllers\BaseController;
use App\Models\Theme;
use App\Models\ThemeVersion;
use App\Services\Theme\ThemeManager;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
    )
    {
    }

    public function index(Request|null $request, ?string $type = null): View
    {
        $themes = $this->themeRepo->getListWhere(
            orderBy: ['id' => 'desc'],
            searchValue: $request?->get('searchValue'),
            relations: ['versions'],
            dataLimit: 20,
        );

        return view('admin-views.theme.index', [
            'themes' => $themes,
            'search' => $request?->get('searchValue'),
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

        $theme = $this->themeRepo->getFirstWhere(params: ['id' => $request['id']]);
        if (!$theme instanceof Theme) {
            ToastMagic::error(translate('theme_not_found') . '!');
            return $this->backToIndex();
        }

        $this->themeManager->activate($theme);
        ToastMagic::success(translate('theme_activated_successfully'));

        return $this->backToIndex();
    }

    /** Publish a draft version (previous published version becomes archived). */
    public function publishVersion(Request $request): RedirectResponse
    {
        if ($this->blockedOnDemo()) {
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

    private function blockedOnDemo(): bool
    {
        if (env('APP_MODE') == 'demo') {
            ToastMagic::error(translate('you_can_not_update_this_on_demo_mode'));
            return true;
        }
        return false;
    }

    private function backToIndex(): RedirectResponse
    {
        return redirect()->route('admin.theme.index');
    }
}
