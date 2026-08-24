<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\BaseController;
use App\Models\ExperiencePage;
use App\Models\Theme;
use App\Models\ThemeVersion;
use App\Services\Theme\BuilderReadiness;
use App\Services\Theme\Channel;
use App\Services\Theme\ExperiencePageService;
use App\Services\Theme\SectionRegistry;
use App\Services\Theme\ThemeAssetService;
use App\Services\Theme\ThemePermissionService;
use App\Services\Theme\ThemePortabilityService;
use Devrabiul\ToastMagic\Facades\ToastMagic;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * The App Builder: the same engine, entered as the app rather than as the theme.
 *
 * The builder was reached through Theme Management, which is where a merchant goes to change the
 * website's colours — so composing the phone app meant opening the website's settings and knowing
 * that the page you wanted was one of three links in a toolbar. The work is the same work; where it
 * lives is what was wrong.
 *
 * This adds the missing half rather than a second builder: pages of a channel, which nothing could
 * manage before, and a catalogue of what can be put on them. Everything already built — the
 * composer, the publish gate, the version history, the global styles — is linked, not rebuilt. A
 * second implementation of any of it would be the duplication this whole architecture is designed
 * to avoid.
 */
class AppBuilderController extends BaseController
{
    public function __construct(
        private readonly ExperiencePageService $pages,
        private readonly SectionRegistry $registry,
        private readonly ThemePermissionService $permissions,
        private readonly BuilderReadiness $readiness,
        private readonly ThemePortabilityService $portability,
    ) {
    }

    /** Straight into composing: the App Builder's front door is the app's home page. */
    public function index(?Request $request, ?string $type = null): RedirectResponse
    {
        return redirect()->route('admin.theme.builder.index', [
            'page' => 'home',
            'channel' => $request ? $this->channel($request) : Channel::CUSTOMER_APP,
        ]);
    }

    /** Every page this channel has, and the controls that change them. */
    public function pages(Request $request): View
    {
        $channel = $this->channel($request);
        $theme = $this->activeTheme();
        $health = $this->readiness->checks();

        return view('admin-views.app-builder.pages', [
            'channel' => $channel,
            'theme'   => $theme,
            'pages'   => $theme ? $this->pages->forChannel($theme->id, $channel) : [],
            'ready'   => $this->pages->isReady(),
            'draft'   => $theme ? $this->latestDraft($theme) : null,
            'editable' => $this->permissions->canEdit(),
            // What this server can and cannot do right now — the checks features run for
            // themselves, gathered where the merchant will actually see them.
            'health'   => $health,
            'allGood'  => !in_array(false, array_column($health, 'ok'), true),
        ]);
    }

    /** What can be put on a page, as a catalogue rather than as a dropdown inside the builder. */
    public function sections(Request $request): View
    {
        $channel = $this->channel($request);

        return view('admin-views.app-builder.sections', [
            'channel'   => $channel,
            'catalogue' => $this->registry->catalogue(null),
            'channels'  => Channel::RENDERABLE,
        ]);
    }

    /**
     * The images a merchant composes with, scoped to the experience being composed.
     *
     * Uploading and deleting stay the theme's own actions — this screen renders the active
     * theme's library where the composing happens, instead of asking a merchant to know that
     * their app's images live under the website's Theme Management.
     */
    public function media(Request $request): View
    {
        $theme = $this->activeTheme();

        return view('admin-views.app-builder.media', [
            'channel'      => $this->channel($request),
            'theme'        => $theme?->load('assets'),
            'assetsReady'  => Schema::hasTable('theme_assets'),
            'maxAssetSize' => ThemeAssetService::maxBytes(),
            'editable'     => $this->permissions->canEdit(),
        ]);
    }

    /**
     * Ready-made starting points and the file form of a composed experience.
     *
     * All four actions already exist on Theme Management — presets, import, export, the annotated
     * example — and stay there; this is the same catalogue shown where a merchant starting an app
     * from nothing will actually look for it.
     */
    public function templates(Request $request): View
    {
        $theme = $this->activeTheme();
        $exportable = null;

        if ($theme !== null) {
            $exportable = ThemeVersion::query()
                ->where('theme_id', $theme->id)
                ->where('status', ThemeVersion::STATUS_PUBLISHED)
                ->orderByDesc('id')
                ->first() ?? $this->latestDraft($theme);
        }

        return view('admin-views.app-builder.templates', [
            'channel'    => $this->channel($request),
            'theme'      => $theme,
            'presets'    => $this->portability->presets(),
            'exportable' => $exportable,
            'editable'   => $this->permissions->canEdit(),
        ]);
    }

    public function storePage(Request $request): RedirectResponse
    {
        if (!$this->guard()) {
            return back();
        }

        $theme = $this->activeTheme();

        if ($theme === null) {
            ToastMagic::error(translate('activate_a_theme_first') . '!');
            return back();
        }

        $page = $this->pages->create(
            theme: $theme,
            title: (string) ($request['title'] ?? ''),
            slug: $request['slug'] ?? null,
            // A page made here belongs to the channel being composed, unless the merchant asks for
            // one both clients read.
            channel: $request->boolean('shared')
                ? ExperiencePage::CHANNEL_SHARED
                : $this->channel($request),
        );

        if ($page === null) {
            ToastMagic::error(translate('that_page_name_is_already_taken_or_cannot_be_used') . '!');
            return back();
        }

        ToastMagic::success(translate('page_created_successfully'));

        return back();
    }

    public function updatePage(Request $request): RedirectResponse
    {
        if (!$this->guard()) {
            return back();
        }

        $page = ExperiencePage::find($request['page_id']);

        if ($page === null) {
            ToastMagic::error(translate('page_not_found') . '!');
            return back();
        }

        $updated = $this->pages->update(
            page: $page,
            title: $request['title'] ?? null,
            enabled: $request->has('enabled') ? $request->boolean('enabled') : null,
        );

        $updated
            ? ToastMagic::success(translate('page_updated_successfully'))
            : ToastMagic::error(translate('a_built_in_page_cannot_be_turned_off') . '!');

        return back();
    }

    public function deletePage(Request $request): RedirectResponse
    {
        if (!$this->guard()) {
            return back();
        }

        $page = ExperiencePage::find($request['page_id']);

        if ($page === null) {
            ToastMagic::error(translate('page_not_found') . '!');
            return back();
        }

        $this->pages->delete($page)
            ? ToastMagic::success(translate('page_deleted_successfully'))
            : ToastMagic::error(translate('a_built_in_page_cannot_be_deleted') . '!');

        return back();
    }

    // ---------------------------------------------------------------------------------------

    /**
     * The channel being composed.
     *
     * The App Builder is the customer app's front door, so that is what it opens on; the same
     * screens serve the website when asked, which is what keeps this one builder rather than two.
     */
    private function channel(Request $request): string
    {
        return Channel::normalize($request->get('channel')) ?? Channel::CUSTOMER_APP;
    }

    private function activeTheme(): ?Theme
    {
        return Theme::query()->where('is_active', true)->first()
            ?? Theme::query()->orderByDesc('id')->first();
    }

    private function latestDraft(Theme $theme): ?ThemeVersion
    {
        return ThemeVersion::query()
            ->where('theme_id', $theme->id)
            ->draft()
            ->orderByDesc('id')
            ->first();
    }

    /** Editing pages is editing the experience, and is gated on the same permission. */
    private function guard(): bool
    {
        if (config('app.mode') === 'demo') {
            ToastMagic::error(translate('you_can_not_update_this_on_demo_mode'));
            return false;
        }

        if (!$this->permissions->canEdit()) {
            ToastMagic::error(translate('you_do_not_have_permission_to_edit_a_theme') . '!');
            return false;
        }

        return true;
    }
}
