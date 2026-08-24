

)




    @if (!$version)
        <div class="content container-fluid">
            <div class="alert alert-info">
                {{ translate('no_theme_version_is_available_create_a_theme_first') }}
                <a href="{{ route('admin.theme.index') }}">{{ translate('Theme_Management') }}</a>
            </div>
        </div>
    @else
        <div class="tb-app"
             data-version="{{ $version->id }}"
             data-page="{{ $page }}"
             data-channel="{{ $channel }}"
             data-locale-overrides="{{ json_encode($localeOverrides ?? []) }}"
             data-editable="{{ $editable ? 1 : 0 }}"
             data-url-add="{{ route('admin.theme.builder.section.add') }}"
             data-url-update="{{ route('admin.theme.builder.section.update') }}"
             data-url-reorder="{{ route('admin.theme.builder.section.reorder') }}"
             data-url-toggle="{{ route('admin.theme.builder.section.toggle') }}"
             data-url-duplicate="{{ route('admin.theme.builder.section.duplicate') }}"
             data-url-delete="{{ route('admin.theme.builder.section.delete') }}"
             data-url-schema="{{ route('admin.theme.builder.section-schema') }}"
             data-url-resources="{{ route('admin.theme.builder.resources') }}"
             data-url-preview-link="{{ route('admin.theme.builder.preview.link') }}"
             data-url-resource-labels="{{ route('admin.theme.builder.resource-labels') }}"
             data-url-link-compose="{{ route('admin.theme.builder.link.compose') }}"
             data-url-block-schema="{{ route('admin.theme.builder.block-schema') }}"
             data-url-block-add="{{ route('admin.theme.builder.block.add') }}"
             data-url-block-update="{{ route('admin.theme.builder.block.update') }}"
             data-url-block-reorder="{{ route('admin.theme.builder.block.reorder') }}"
             data-url-block-toggle="{{ route('admin.theme.builder.block.toggle') }}"
             data-url-block-duplicate="{{ route('admin.theme.builder.block.duplicate') }}"
             data-url-block-delete="{{ route('admin.theme.builder.block.delete') }}"
             data-url-media-upload="{{ route('admin.theme.builder.media.upload') }}"
             data-url-media-library="{{ route('admin.theme.builder.media.library') }}"
             data-url-media-delete="{{ route('admin.theme.builder.media.delete') }}"
             data-url-delivery="{{ route('admin.theme.builder.section.delivery-rules') }}">

            <header class="tb-bar">
                <div class="tb-bar__group">
                    <a href="{{ route('admin.theme.index') }}" class="tb-icon-btn" title="{{ translate('back_to_theme_management') }}">
                        <i class="fi fi-rr-angle-left"></i>
                    </a>
                    <span class="tb-brand">
                        <span class="tb-brand__name">{{ $theme->name ?? translate('Theme_Builder') }}</span>
                        <span class="tb-chip {{ $editable ? 'tb-chip--draft' : 'tb-chip--live' }}">
                            {{ $editable ? translate('draft') : translate('published') }} #{{ $version->id }}
                        </span>
                    </span>
                </div>

                <div class="tb-bar__group is-grow justify-content-center">
                    {{-- The pages this theme has, read from the page table rather than written
                         into the template: home is one page among them, which is the whole point
                         of the page abstraction. A disabled page is still listed for its owner —
                         it is a page they turned off, not a page that stopped existing. --}}
                    <nav class="tb-seg">
                        @foreach ($pages as $availablePage)
                            <a class="{{ $page === $availablePage['slug'] ? 'is-active' : '' }} {{ $availablePage['enabled'] ? '' : 'is-off' }}"
                               title="{{ $availablePage['enabled'] ? '' : translate('this_page_is_turned_off') }}"
                               href="{{ route('admin.theme.builder.index', ['page' => $availablePage['slug'], 'version' => $version->id, 'channel' => $channel]) }}">
                                {{ translate($availablePage['title']) }}
                            </a>
                        @endforeach
                        @if ($editable)
                            <a href="{{ route('admin.app-builder.pages', ['channel' => $channel]) }}"
                               title="{{ translate('manage_pages') }}"><i class="fi fi-rr-settings-sliders"></i></a>
                        @endif
                    </nav>
                </div>

                <div class="tb-bar__group">
                    <span id="tb-status" class="tb-status" aria-live="polite"></span>
                    {{-- Which client's eyes to look through. The device buttons change the frame's
                         width; this changes what the page actually consists of, which is a
                         different question and the one a merchant asks before publishing. --}}
                    <div class="tb-seg" role="group" aria-label="{{ translate('channel') }}">
                        {{-- Links rather than buttons: the channel decides which pages exist and
                             what each one may contain, and those are the server's answers. --}}
                        <a class="{{ $channel === 'customer_app' ? '' : 'is-active' }}" data-channel="web"
                           title="{{ translate('website') }}"
                           href="{{ route('admin.theme.builder.index', ['page' => $page, 'version' => $version->id, 'channel' => 'web']) }}"><i class="fi fi-rr-globe"></i></a>
                        <a class="{{ $channel === 'customer_app' ? 'is-active' : '' }}" data-channel="customer_app"
                           title="{{ translate('customer_app') }}"
                           href="{{ route('admin.theme.builder.index', ['page' => $page, 'version' => $version->id, 'channel' => 'customer_app']) }}"><i class="fi fi-rr-mobile-notch"></i></a>
                    </div>
                    <div class="tb-seg" role="group" aria-label="{{ translate('device_preview') }}">
                        <button type="button" class="is-active" data-device="desktop" title="{{ translate('desktop') }}"><i class="fi fi-rr-computer"></i></button>
                        <button type="button" data-device="tablet" title="{{ translate('tablet') }}"><i class="fi fi-rr-tablet"></i></button>
                        <button type="button" data-device="mobile" title="{{ translate('mobile') }}"><i class="fi fi-rr-mobile-button"></i></button>
                    </div>
                    <button type="button" id="tb-refresh" class="tb-icon-btn" title="{{ translate('refresh_preview') }}"><i class="fi fi-rr-refresh"></i></button>
                    {{-- The frame beside this is a browser drawing an approximation of a phone. --}}
                    <button type="button" id="tb-onphone" class="tb-icon-btn" title="{{ translate('open_this_draft_on_your_phone') }}">
                        <i class="fi fi-rr-qrcode"></i>
                    </button>
                    @if (!empty($previewUrl))
                        <a href="{{ $previewUrl }}" target="_blank" rel="noopener" class="tb-icon-btn" title="{{ translate('open_in_new_tab') }}">
                            <i class="fi fi-rr-arrow-up-right-from-square"></i>
                        </a>
                    @endif
                    @if (session(\App\Services\Theme\StorefrontThemeRenderer::PREVIEW_SESSION_KEY))
                        <a href="{{ route('admin.theme.builder.preview.stop') }}" class="tb-icon-btn">{{ translate('End_preview') }}</a>
                    @endif
                    @if ($editable)
                        <button type="button" id="tb-publish" class="tb-icon-btn tb-icon-btn--primary">{{ translate('publish') }}</button>
                    @else
                        <a href="{{ route('admin.theme.index') }}" class="tb-icon-btn tb-icon-btn--primary">{{ translate('versions') }}</a>
                    @endif
                </div>
            </header>

            {{-- Publishing, where the work happens. It used to be a link out to the version list,
                 which meant leaving the page to do the one thing the page is for — and finding out
                 there that a section was unfinished, with the panel that fixes it two clicks away. --}}
            @if ($editable)
                <div class="tb-publish" id="tb-publish-panel" hidden>
                    <button type="button" class="tb-onphone__close" id="tb-publish-close" aria-label="{{ translate('close') }}">&times;</button>
                    <strong>{{ translate('publish_this_version') }}</strong>

                    @if (!empty($publishCheck['blocking']))
                        <p class="tb-publish__stop">
                            {{ count($publishCheck['blocking']) }} {{ translate('sections_are_not_ready_to_publish') }}.
                            {{ translate('fix_them_above_and_reload') }}
                        </p>
                    @else
                        @if (!empty($publishCheck['warnings']))
                            <p class="tb-publish__warn">
                                {{ count($publishCheck['warnings']) }} {{ translate('things_worth_knowing_before_you_publish') }}
                            </p>
                        @endif

                        <form method="post" action="{{ route('admin.theme.version.publish') }}">
                            @csrf
                            <input type="hidden" name="version_id" value="{{ $version->id }}">
                            <input type="hidden" name="return_to_builder" value="{{ $page }}">
                            <input type="text" name="change_note" maxlength="300" class="tb-input"
                                   placeholder="{{ translate('what_changed_optional') }}">
                            <button type="submit" class="k-btn k-btn--primary">{{ translate('publish_now') }}</button>
                        </form>

                        <form method="post" action="{{ route('admin.theme.version.schedule') }}">
                            @csrf
                            <input type="hidden" name="version_id" value="{{ $version->id }}">
                            <input type="hidden" name="return_to_builder" value="{{ $page }}">
                            <input type="datetime-local" name="publish_at" class="tb-input">
                            <button type="submit" class="k-btn k-btn--secondary">{{ translate('publish_later') }}</button>
                        </form>
                    @endif
                </div>
            @endif

            {{-- Scanned off the screen with the phone in the merchant's hand. The token inside the
                 link expires on its own, so the panel says when — a link that looks permanent and
                 quietly stops working is worse than one that tells you it will. --}}
            <div class="tb-onphone" id="tb-onphone-panel" hidden>
                <button type="button" class="tb-onphone__close" id="tb-onphone-close" aria-label="{{ translate('close') }}">&times;</button>
                <strong>{{ translate('open_this_draft_on_your_phone') }}</strong>
                <div class="tb-onphone__qr" id="tb-onphone-qr"></div>
                <input type="text" class="tb-onphone__url" id="tb-onphone-url" readonly dir="ltr">
                <span class="tb-onphone__note" id="tb-onphone-note"></span>
            </div>

            {{-- Go-live checklist: composing sections changes nothing for customers until the theme
                 is active AND a version is published. Both fixes are one click, right here. --}}
            @if ($goLive && !$goLive['live'])
                <div class="tb-golive">
                    <i class="fi fi-rr-rocket-lunch"></i>
                    <div class="tb-golive__body">
                        <strong>{{ translate('this_theme_is_not_live_yet_the_storefront_still_shows_the_built_in_design') }}</strong>
                        <span>{{ translate('activate_the_theme_and_publish_this_version_to_apply_its_sections_and_colors_to_the_store') }}</span>
                    </div>
                    <div class="tb-golive__actions">
                        @if (!$goLive['active'])
                            <form method="post" action="{{ route('admin.theme.activate') }}">
                                @csrf
                                <input type="hidden" name="id" value="{{ $theme->id }}">
                                <input type="hidden" name="return_to_builder" value="{{ $page }}">
                                <button type="submit" class="k-btn k-btn--secondary">{{ translate('activate_theme') }}</button>
                            </form>
                        @endif
                        @if ($editable)
                            <form method="post" action="{{ route('admin.theme.version.publish') }}">
                                @csrf
                                <input type="hidden" name="version_id" value="{{ $version->id }}">
                                <input type="hidden" name="return_to_builder" value="{{ $page }}">
                                <button type="submit" class="k-btn k-btn--primary">{{ translate('publish_this_version') }}</button>
                            </form>
                        @endif
                    </div>
                </div>
            @endif

            {{-- What would stop this version going live. Publishing is refused while any of these
                 stands, so it is said here — next to the sections it names — rather than as a
                 rejection on the way out. --}}
            @if (!empty($publishCheck['blocking']))
                <div class="tb-compat tb-compat--stop">
                    <i class="fi fi-rr-triangle-warning"></i>
                    <div>
                        <strong>{{ translate('this_version_cannot_be_published_yet') }}</strong>
                        <ul>
                            @foreach ($publishCheck['blocking'] as $finding)
                                <li>
                                    @if ($finding['section_id'] && $finding['page'] === $page)
                                        <button type="button" class="tb-jump" data-tb-jump="{{ $finding['section_id'] }}">{{ translate($finding['label']) }}</button>
                                    @else
                                        {{ translate($finding['label']) }}
                                        @if ($finding['page'] !== $page)<span class="tb-compat__where">({{ translate($finding['page']) }})</span>@endif
                                    @endif
                                    — {{ translate($finding['reason_key']) }}. {{ translate($finding['fix_key']) }}.
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            @endif

            {{-- App coverage: which of this version's sections the customer app cannot draw. Shown
                 while the merchant can still act on it (swap the section, or accept the web-only
                 gap knowingly) rather than discovered on a shopper's phone. --}}
            @if (!empty($compatibility['withheld']))
                <div class="tb-compat">
                    <i class="fi fi-rr-mobile-notch"></i>
                    <div>
                        <strong>
                            {{ translate('the_mobile_app_will_show') }}
                            {{ $compatibility['app_supported'] }} / {{ $compatibility['sections'] }}
                            {{ translate('of_this_versions_sections') }}
                        </strong>
                        {{ translate('these_render_on_the_website_only') }}:
                        <ul>
                            @foreach ($compatibility['withheld'] as $gap)
                                <li>
                                    {{ translate($gap['label']) }}@if($gap['count'] > 1) ×{{ $gap['count'] }}@endif
                                    — {{ translate($gap['reason']) }}
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            @endif

            @if (!empty($bannerGaps) && $editable)
                <div class="tb-gap-note" id="tb-gap-note">
                    <i class="fi fi-rr-triangle-warning"></i>
                    <div class="tb-gap-note__body">
                        <strong>{{ translate('some_of_your_published_banners_will_not_show_on_this_composed_home_page') }}</strong>
                        <span>{{ translate('a_composed_home_replaces_the_built_in_page_add_a_banners_from_dashboard_section_for_each_type_you_still_want') }}</span>
                    </div>
                    <div class="tb-gap-note__actions">
                        @foreach ($bannerGaps as $gap)
                            <button type="button" class="k-btn k-btn--secondary tb-gap-add"
                                    data-banner-type="{{ $gap['type'] }}"
                                    data-layout="{{ $gap['type'] === 'Main Banner' ? 'carousel' : 'grid' }}">
                                <i class="fi fi-rr-plus"></i>
                                {{ $gap['label'] }} ({{ $gap['count'] }})
                            </button>
                        @endforeach
                    </div>
                </div>
            @endif

            <div class="tb-body">
                {{-- LEFT: page structure --}}
                <aside class="tb-pane tb-pane--rail">
                    <div class="tb-pane__head">
                        <span>{{ translate('sections') }}</span>
                        <span class="tb-item__count">{{ count($structure) }}</span>
                    </div>
                    <div class="tb-pane__body" id="tb-structure">
                        @forelse ($structure as $section)
                            <div class="tb-item {{ $section['is_visible'] ? '' : 'is-hidden' }}"
                                 draggable="{{ $editable ? 'true' : 'false' }}"
                                 data-id="{{ $section['id'] }}"
                                 data-type="{{ $section['type'] }}"
                                 data-visible="{{ $section['is_visible'] ? 1 : 0 }}"
                                 data-app-safe="{{ empty($section['app_safe']) ? 0 : 1 }}"
                                 data-channels="{{ implode(',', $section['channels'] ?? []) }}"
                                 aria-selected="false">
                                <span class="tb-item__grip"><i class="fi fi-rr-menu-burger"></i></span>
                                <span class="tb-item__label">{{ translate($section['label']) }}</span>
                                {{-- A section can be added, visible, and still show nothing: a coupon
                                     strip with no live coupon, a showcase with no category chosen.
                                     The storefront is right to skip those, but until this badge the
                                     builder gave no hint, so the merchant either thought the theme
                                     was broken or thought the section was there. --}}
                                @if (empty($section['app_safe']))
                                    <span class="tb-item__flag tb-item__flag--webonly" title="{{ translate('this_section_renders_on_the_website_only_the_mobile_app_has_no_renderer_for_it') }}"><i class="fi fi-rr-browser"></i></span>
                                @endif
                                @if (!empty($section['delivery']['scheduled']))
                                    <span class="tb-item__flag tb-item__flag--rules" title="{{ translate('this_section_runs_on_a_schedule') }}"><i class="fi fi-rr-clock"></i></span>
                                @endif
                                @if (!empty($section['delivery']['targeted']))
                                    <span class="tb-item__flag tb-item__flag--rules" title="{{ translate('this_section_is_limited_to_certain_platforms_or_visitors') }}"><i class="fi fi-rr-filter"></i></span>
                                @endif
                                @if (($section['readiness']['state'] ?? 'ready') !== 'ready')
                                    <span class="tb-item__flag tb-item__flag--{{ $section['readiness']['state'] }}"
                                          title="{{ translate($section['readiness']['reason_key']) }}">
                                        <i class="fi {{ $section['readiness']['state'] === 'needs_choice' ? 'fi-rr-pencil' : 'fi-rr-eye-crossed' }}"></i>
                                    </span>
                                @endif
                                {{-- What this row is worth: how many shoppers got far enough down
                                     the page to see it. A section nobody reaches looks exactly like
                                     a section nobody wants, and only one of those is fixed by
                                     dragging it up. Absent until analytics has measured something,
                                     because a zero would say the opposite of "not counted yet". --}}
                                @if (isset($reach[$section['id']]))
                                    <span class="tb-item__reach" title="{{ translate('shoppers_who_saw_this_section_in_the_last_30_days') }}">
                                        <i class="fi fi-rr-eye"></i>{{ $reach[$section['id']] }}
                                    </span>
                                @endif
                                @if (count($section['blocks']))
                                    <span class="tb-item__count">{{ count($section['blocks']) }}</span>
                                @endif
                                <button type="button" class="tb-item__eye" data-action="toggle"
                                        title="{{ translate('show_hide') }}" {{ $editable ? '' : 'disabled' }}>
                                    <i class="fi {{ $section['is_visible'] ? 'fi-rr-eye' : 'fi-rr-eye-crossed' }}"></i>
                                </button>
                                {{-- Delete lives on the row itself: hiding and removing are different
                                     intentions, and the merchant should not have to hunt for removal. --}}
                                <button type="button" class="tb-item__eye tb-item__trash" data-action="remove"
                                        title="{{ translate('delete') }}" {{ $editable ? '' : 'disabled' }}>
                                    <i class="fi fi-rr-trash"></i>
                                </button>
                            </div>
                        @empty
                            <p class="tb-empty" id="tb-empty">{{ translate('no_sections_yet_add_your_first_one') }}</p>
                        @endforelse
                    </div>
                    @if ($editable)
                        <div class="tb-pane__foot">
                            <button type="button" id="tb-open-picker" class="tb-icon-btn tb-icon-btn--primary w-100 justify-content-center">
                                <i class="fi fi-rr-plus"></i> {{ translate('add_section') }}
                            </button>
                        </div>
                    @endif
                </aside>

                {{-- CENTER: live storefront --}}
                <main class="tb-canvas">
                    <div class="tb-canvas__inner">
                        @if (!empty($previewUrl))
                            <div class="tb-stage" data-device="desktop">
                                <div class="tb-loader" id="tb-loader"><span class="spinner-border spinner-border-sm"></span> {{ translate('loading_preview') }}</div>
                                <iframe id="tb-frame" class="tb-frame" src="{{ $previewUrl }}" title="{{ translate('storefront_preview') }}"></iframe>
                            </div>
                        @else
                            <div class="tb-empty">{{ translate('storefront_preview_is_unavailable_here') }}</div>
                        @endif
                    </div>
                </main>

                {{-- RIGHT: inspector --}}
                <aside class="tb-pane tb-pane--inspector">
                    <div class="tb-pane__head">
                        <span id="tb-inspector-title">{{ translate('settings') }}</span>
                    </div>
                    <div class="tb-tabs" id="tb-tabs" hidden>
                        <button type="button" data-tab="content" class="is-active">{{ translate('content') }}</button>
                        <button type="button" data-tab="style">{{ translate('design') }}</button>
                        <button type="button" data-tab="delivery">{{ translate('visibility') }}</button>
                    </div>
                    <div class="tb-pane__body">
                        <div id="tb-inspector">
                            <p class="tb-hint">{{ translate('select_a_section_on_the_left_or_click_it_directly_in_the_preview') }}</p>
                        </div>
                    </div>
                    <div class="tb-pane__foot" id="tb-actions" hidden>
                        <button type="button" id="tb-save" class="tb-icon-btn tb-icon-btn--primary">{{ translate('save') }}</button>
                        <button type="button" id="tb-duplicate" class="tb-icon-btn">{{ translate('duplicate') }}</button>
                        <button type="button" id="tb-delete" class="tb-icon-btn tb-icon-btn--danger">{{ translate('delete') }}</button>
                    </div>
                </aside>
            </div>

            {{-- add-section picker --}}
            <div class="tb-overlay" id="tb-picker">
                <div class="tb-dialog">
                    <div class="tb-dialog__head">
                        <span class="tb-dialog__title">{{ translate('add_a_section') }}</span>
                        <button type="button" class="tb-icon-btn" data-close><i class="fi fi-rr-cross-small"></i></button>
                    </div>
                    <div class="tb-dialog__body">
                        {{-- Every card shows the shape the section takes on the page and the options it
                             brings, so a merchant knows what they are adding before they add it. --}}
                        {{-- Grouped by family, because a flat list of 39 is a list nobody reads.
                             The groups come from the registry, so a new type lands in its family
                             without this markup changing. --}}
                        @foreach ($sectionCatalogue as $categoryKey => $group)
                            <div class="tb-cards__group">
                                <h6 class="tb-cards__group-title">{{ translate($group['label']) }}</h6>
                            </div>
                        <div class="tb-cards">
                            @foreach ($group['types'] as $key => $definition)
                                <button type="button" class="tb-card" data-type="{{ $key }}" data-category="{{ $categoryKey }}">
                                    <span class="tb-thumb" data-shape="{{ $definition['preview'] ?? 'block' }}" aria-hidden="true"></span>
                                    <strong>{{ translate($definition['label']) }}</strong>
                                    <span>{{ translate($definition['hint'] ?? $definition['label']) }}</span>
                                    <span class="tb-chips">
                                        @if (!in_array('customer_app', $definition['channels'] ?? [], true))
                                            {{-- Said before it is added, not discovered after publishing. --}}
                                            <span class="tb-chip tb-chip--web">{{ translate('web_only') }}</span>
                                        @endif
                                        @foreach ($definition['variants'] ?? [] as $variant)
                                            <span class="tb-chip tb-chip--variant">{{ translate($variant) }}</span>
                                        @endforeach
                                        @foreach ($definition['blocks'] ?? [] as $blockType)
                                            <span class="tb-chip tb-chip--block">+ {{ translate($blockLabels[$blockType] ?? $blockType) }}</span>
                                        @endforeach
                                    </span>
                                </button>
                            @endforeach
                        </div>
                        @endforeach
                    </div>
                </div>
            </div>

            {{-- media library --}}
            <div class="tb-overlay" id="tb-media">
                <div class="tb-dialog">
                    <div class="tb-dialog__head">
                        <span class="tb-dialog__title">{{ translate('choose_an_image') }}</span>
                        <button type="button" class="tb-icon-btn" data-close><i class="fi fi-rr-cross-small"></i></button>
                    </div>
                    <div class="tb-dialog__body">
                        <div class="tb-media-grid" id="tb-media-grid"></div>
                        <p class="tb-hint mt-3 mb-0">{{ translate('images_uploaded_from_any_image_field_appear_here') }}</p>
                    </div>
                </div>
            </div>

            <input type="file" id="tb-file" accept="{{ $uploadAccept }}" hidden>
        </div>
    @endif



