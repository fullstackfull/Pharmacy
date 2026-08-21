@extends('layouts.admin.app')

@section('title', translate('Theme_Builder'))

@push('css_or_js')
    <style>
        /* Full-viewport editor. It deliberately covers the admin chrome: a storefront editor needs the
           whole screen, and the top bar keeps the way back to the dashboard one click away. */
        .tb-app { position: fixed; inset: 0; z-index: 1040; display: flex; flex-direction: column;
            background: #0e1116; color: #e8eaed; font-size: .875rem; }
        .tb-app *, .tb-app *::before, .tb-app *::after { box-sizing: border-box; }

        .tb-bar { flex: 0 0 auto; display: flex; align-items: center; gap: 1rem; padding: 0 .9rem; height: 54px;
            background: linear-gradient(180deg,#171b22,#12161c); border-bottom: 1px solid #262c36; }
        .tb-bar__group { display: flex; align-items: center; gap: .5rem; }
        .tb-bar__group.is-grow { flex: 1; min-width: 0; }
        .tb-brand { display: flex; align-items: center; gap: .6rem; min-width: 0; }
        .tb-brand__name { font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 220px; }
        .tb-chip { font-size: .68rem; letter-spacing: .06em; text-transform: uppercase; padding: .2rem .5rem;
            border-radius: 999px; border: 1px solid transparent; white-space: nowrap; }
        .tb-chip--draft { color: #ffd08a; border-color: #6b4f1d; background: rgba(255,208,138,.1); }
        .tb-chip--live { color: #8ce0b0; border-color: #1f5c40; background: rgba(140,224,176,.1); }
        .tb-icon-btn { display: inline-flex; align-items: center; justify-content: center; gap: .4rem; height: 32px;
            min-width: 32px; padding: 0 .6rem; border-radius: .4rem; border: 1px solid #2b323d; background: #1a1f27;
            color: #cfd4dc; cursor: pointer; transition: .18s ease; text-decoration: none; font-size: .8rem; }
        .tb-icon-btn:hover { background: #232a34; color: #fff; text-decoration: none; }
        .tb-icon-btn.is-active { background: #2f6f62; border-color: #3d8b7a; color: #fff; }
        .tb-icon-btn--primary { background: #2f6f62; border-color: #3d8b7a; color: #fff; }
        .tb-icon-btn--primary:hover { background: #388575; color: #fff; }
        .tb-icon-btn--danger:hover { background: #6d2b2b; border-color: #8a3838; color: #fff; }
        .tb-seg { display: flex; padding: 2px; gap: 2px; border-radius: .45rem; background: #171c24; border: 1px solid #2b323d; }
        .tb-seg > * { height: 28px; padding: 0 .7rem; border: 0; border-radius: .35rem; background: transparent;
            color: #aab2be; font-size: .78rem; cursor: pointer; display: inline-flex; align-items: center; gap: .35rem; text-decoration: none; }
        .tb-seg > *.is-active { background: #2b323d; color: #fff; }
        .tb-status { font-size: .75rem; color: #8d95a1; white-space: nowrap; }
        .tb-status.is-saving { color: #ffd08a; } .tb-status.is-saved { color: #8ce0b0; } .tb-status.is-error { color: #ff9b9b; }

        .tb-body { flex: 1 1 auto; display: grid; grid-template-columns: 280px minmax(0,1fr) 340px; min-height: 0; }
        .tb-pane { display: flex; flex-direction: column; min-height: 0; background: #12161c; }
        .tb-pane--rail { border-right: 1px solid #262c36; }
        .tb-pane--inspector { border-left: 1px solid #262c36; }
        .tb-pane__head { flex: 0 0 auto; display: flex; align-items: center; justify-content: space-between; gap: .5rem;
            padding: .7rem .85rem; border-bottom: 1px solid #212832; font-size: .74rem; letter-spacing: .12em;
            text-transform: uppercase; color: #8d95a1; }
        .tb-pane__body { flex: 1 1 auto; overflow-y: auto; padding: .75rem; }
        .tb-pane__foot { flex: 0 0 auto; padding: .7rem .75rem; border-top: 1px solid #212832; display: flex; flex-wrap: wrap; gap: .4rem; }

        /* structure list */
        .tb-item { display: flex; align-items: center; gap: .5rem; padding: .55rem .6rem; margin-bottom: .35rem;
            border: 1px solid #232a34; border-radius: .45rem; background: #171c24; cursor: pointer; transition: .15s ease; }
        .tb-item:hover { border-color: #33404f; background: #1c222b; }
        .tb-item[aria-selected="true"] { border-color: #3d8b7a; background: #17322c; }
        .tb-item.is-dragging { opacity: .45; } .tb-item.is-drop { border-style: dashed; border-color: #3d8b7a; }
        .tb-item__grip { color: #5d6673; cursor: grab; display: flex; }
        .tb-item__label { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .tb-item__count { font-size: .68rem; color: #7d8794; }
        .tb-item__eye { border: 0; background: transparent; color: #7d8794; cursor: pointer; padding: 0 .15rem; display: flex; }
        .tb-item__eye:hover { color: #fff; }
        .tb-item__trash:hover { color: #ff9b9b; }
        .tb-item.is-hidden .tb-item__label { color: #6b7480; text-decoration: line-through; }
        .tb-empty { color: #7d8794; font-size: .8rem; text-align: center; padding: 1.5rem .5rem; }

        /* canvas */
        .tb-canvas { display: flex; flex-direction: column; min-height: 0; background:
            radial-gradient(90% 70% at 50% 0%, #1c222b 0%, #0e1116 70%); }
        .tb-canvas__inner { flex: 1; display: flex; align-items: stretch; justify-content: center; padding: 1rem; min-height: 0; }
        .tb-stage { position: relative; width: 100%; transition: max-width .3s cubic-bezier(.22,.61,.36,1);
            border-radius: .6rem; overflow: hidden; box-shadow: 0 30px 70px -30px rgba(0,0,0,.8); background: #fff; }
        .tb-stage[data-device="desktop"] { max-width: 100%; }
        .tb-stage[data-device="tablet"] { max-width: 834px; }
        .tb-stage[data-device="mobile"] { max-width: 414px; }
        .tb-frame { width: 100%; height: 100%; min-height: 480px; border: 0; display: block; background: #fff; }
        .tb-gap-note { display: flex; flex-wrap: wrap; align-items: center; gap: .6rem .9rem; margin: 0; padding: .65rem 1rem; background: #fff8e6; border-block-end: 1px solid #f1e3b8; color: #7a5b00; font-size: .82rem; }
        .tb-gap-note i { font-size: 1rem; }
        .tb-gap-note__body { display: flex; flex-direction: column; gap: .1rem; }
        .tb-gap-note__actions { display: flex; flex-wrap: wrap; gap: .4rem; margin-inline-start: auto; }
        .tb-gap-note__actions .k-btn { padding-block: .3rem; font-size: .78rem; }
        .tb-golive { display: flex; flex-wrap: wrap; align-items: center; gap: .6rem .9rem; padding: .65rem 1rem;
            background: #eaf3ff; border-block-end: 1px solid #c9dcf5; color: #14406e; font-size: .82rem; }
        .tb-golive i { font-size: 1rem; }
        .tb-golive__body { display: flex; flex-direction: column; gap: .1rem; }
        .tb-golive__actions { display: flex; flex-wrap: wrap; gap: .4rem; margin-inline-start: auto; }
        .tb-golive__actions .k-btn { padding-block: .3rem; font-size: .78rem; }
        .tb-loader { position: absolute; inset: 0; z-index: 2; display: flex; align-items: center; justify-content: center;
            gap: .5rem; background: rgba(14,17,22,.55); color: #e8eaed; font-size: .8rem; transition: opacity .25s; }
        .tb-loader.is-hidden { opacity: 0; pointer-events: none; }

        /* inspector */
        .tb-tabs { display: flex; gap: .25rem; padding: .5rem .75rem 0; }
        .tb-tabs button { flex: 1; height: 30px; border: 0; border-radius: .35rem .35rem 0 0; background: transparent;
            color: #8d95a1; font-size: .78rem; cursor: pointer; border-bottom: 2px solid transparent; }
        .tb-tabs button.is-active { color: #fff; border-bottom-color: #3d8b7a; }
        .tb-field { margin-bottom: .75rem; }
        .tb-field > label { display: block; margin-bottom: .3rem; font-size: .74rem; color: #9aa3af; text-transform: capitalize; }
        .tb-app .tb-input { width: 100%; height: 32px; padding: 0 .55rem; border-radius: .35rem; border: 1px solid #2b323d;
            background: #171c24; color: #e8eaed; font-size: .8rem; }
        .tb-app textarea.tb-input { height: auto; padding: .45rem .55rem; resize: vertical; }
        .tb-app .tb-input:focus { outline: 0; border-color: #3d8b7a; box-shadow: 0 0 0 2px rgba(61,139,122,.25); }
        .tb-app input[type="color"].tb-input { padding: 2px; height: 32px; cursor: pointer; }
        .tb-app input[type="checkbox"].tb-input { width: 34px; height: 18px; appearance: none; -webkit-appearance: none;
            border-radius: 999px; position: relative; cursor: pointer; }
        .tb-app input[type="checkbox"].tb-input::after { content: ""; position: absolute; top: 2px; inset-inline-start: 2px;
            width: 12px; height: 12px; border-radius: 50%; background: #7d8794; transition: .18s; }
        .tb-app input[type="checkbox"].tb-input:checked { background: #2f6f62; border-color: #3d8b7a; }
        .tb-app input[type="checkbox"].tb-input:checked::after { inset-inline-start: 18px; background: #fff; }
        .tb-sub { display: grid; grid-template-columns: 1fr 1fr; gap: .35rem; margin-top: .35rem; }
        .tb-sub label { font-size: .66rem; color: #6f7885; display: block; margin-bottom: .15rem; }
        .tb-group-title { font-size: .7rem; letter-spacing: .12em; text-transform: uppercase; color: #6f7885;
            margin: 1rem 0 .5rem; padding-top: .75rem; border-top: 1px solid #212832; }
        .tb-hint { font-size: .72rem; color: #7d8794; line-height: 1.5; }
        .tb-data-note { margin: 0 .75rem .5rem; padding: .5rem .6rem; border-radius: .4rem; font-size: .72rem;
            line-height: 1.5; background: #2a2413; border: 1px solid #4a3f1c; color: #e0c877; }

        /* image field */
        .tb-image { display: flex; gap: .5rem; align-items: center; }
        .tb-image__preview { flex: 0 0 auto; width: 54px; height: 54px; border-radius: .35rem; overflow: hidden;
            border: 1px dashed #333c48; background: #171c24; display: flex; align-items: center; justify-content: center; color: #566070; }
        .tb-image__preview img { width: 100%; height: 100%; object-fit: cover; }
        .tb-image__side { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: .3rem; }
        .tb-image__actions { display: flex; gap: .3rem; }
        .tb-image__actions button { flex: 1; height: 26px; font-size: .72rem; border-radius: .3rem; border: 1px solid #2b323d;
            background: #1a1f27; color: #cfd4dc; cursor: pointer; }
        .tb-image__actions button:hover { background: #232a34; color: #fff; }

        /* blocks */
        .tb-block { display: flex; align-items: center; gap: .5rem; padding: .4rem .5rem; margin-bottom: .35rem;
            border: 1px solid #232a34; border-radius: .4rem; background: #171c24; cursor: pointer; }
        .tb-block:hover { border-color: #33404f; }
        .tb-block__thumb { flex: 0 0 auto; width: 34px; height: 34px; border-radius: .3rem; overflow: hidden;
            background: #10141a; display: flex; align-items: center; justify-content: center; color: #566070; font-size: .7rem; }
        .tb-block__thumb img { width: 100%; height: 100%; object-fit: cover; }
        .tb-block__label { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: .8rem; }
        .tb-block.is-hidden .tb-block__label { color: #6b7480; text-decoration: line-through; }
        .tb-back { display: inline-flex; align-items: center; gap: .35rem; border: 0; background: transparent;
            color: #8ce0b0; font-size: .76rem; cursor: pointer; padding: 0; margin-bottom: .6rem; }

        /* overlays */
        .tb-overlay { position: fixed; inset: 0; z-index: 1060; display: none; align-items: center; justify-content: center;
            padding: 1.5rem; background: rgba(6,8,11,.72); backdrop-filter: blur(4px); }
        .tb-overlay.is-open { display: flex; }
        .tb-dialog { width: 100%; max-width: 820px; max-height: 84vh; display: flex; flex-direction: column;
            background: #12161c; border: 1px solid #262c36; border-radius: .7rem; overflow: hidden; color: #e8eaed; }
        .tb-dialog__head { display: flex; align-items: center; justify-content: space-between; gap: 1rem;
            padding: .85rem 1rem; border-bottom: 1px solid #212832; }
        .tb-dialog__title { font-weight: 600; }
        .tb-dialog__body { padding: 1rem; overflow-y: auto; }
        .tb-cards { display: grid; grid-template-columns: repeat(auto-fill,minmax(190px,1fr)); gap: .6rem; }
        .tb-card { text-align: start; padding: .8rem; border-radius: .5rem; border: 1px solid #262c36; background: #171c24;
            color: #e8eaed; cursor: pointer; transition: .15s ease; }
        .tb-card:hover { border-color: #3d8b7a; background: #1b2229; transform: translateY(-2px); }
        .tb-card strong { display: block; font-size: .85rem; margin-bottom: .2rem; text-transform: capitalize; }
        .tb-card span { font-size: .72rem; color: #8d95a1; line-height: 1.45; }
        .tb-cards { grid-template-columns: repeat(auto-fill,minmax(230px,1fr)); }

        /* Wireframe preview of the shape each section takes on the storefront. Pure CSS so the
           picker stays instant — no screenshots to keep in sync with the theme. */
        .tb-thumb { display: block; height: 62px; margin-bottom: .55rem; border-radius: .4rem;
            background: #10151b; border: 1px solid #262c36; overflow: hidden; position: relative; }
        .tb-thumb::before, .tb-thumb::after { content: ""; position: absolute; }
        .tb-thumb[data-shape="hero"]::before { inset: 8px; border-radius: 4px;
            background: linear-gradient(120deg,#5b46a8,#2f7fae); }
        .tb-thumb[data-shape="hero"]::after { left: 16px; bottom: 16px; width: 42%; height: 6px;
            border-radius: 3px; background: rgba(255,255,255,.75); }
        .tb-thumb[data-shape="rail"]::before { inset: 14px 8px 8px; border-radius: 3px;
            background: repeating-linear-gradient(90deg,#39434f 0 24%,transparent 24% 28%); }
        .tb-thumb[data-shape="rail"]::after { left: 8px; top: 6px; width: 34%; height: 5px; border-radius: 3px; background: #4a5563; }
        .tb-thumb[data-shape="tiles"]::before { inset: 10px; border-radius: 3px;
            background: repeating-linear-gradient(90deg,#39434f 0 46%,transparent 46% 54%); }
        .tb-thumb[data-shape="mosaic"]::before { inset: 8px; border-radius: 3px;
            background: linear-gradient(90deg,#39434f 0 54%,transparent 54%),
                        repeating-linear-gradient(90deg,transparent 0 56%,#39434f 56% 76%,transparent 76% 80%,#39434f 80% 100%); }
        .tb-thumb[data-shape="split"]::before { inset: 8px; border-radius: 3px;
            background: linear-gradient(90deg,#39434f 0 48%,#232a33 48% 100%); }
        .tb-thumb[data-shape="strip"]::before { inset: 14px 0; background: linear-gradient(120deg,#5b46a8,#2f7fae); }
        .tb-thumb[data-shape="strip"]::after { left: 50%; top: 50%; transform: translate(-50%,-50%);
            width: 40%; height: 6px; border-radius: 3px; background: rgba(255,255,255,.8); }
        .tb-thumb[data-shape="circles"]::before { inset: 18px 8px;
            background-image: radial-gradient(circle,#39434f 46%,transparent 48%);
            background-size: 20% 100%; background-repeat: repeat-x; }
        .tb-thumb[data-shape="usp"]::before { inset: 20px 8px; border-radius: 3px;
            background: repeating-linear-gradient(90deg,#39434f 0 21%,transparent 21% 26%); }
        .tb-thumb[data-shape="marquee"]::before { inset: 24px 6px; border-radius: 3px;
            background: repeating-linear-gradient(90deg,#39434f 0 14%,transparent 14% 20%); }
        .tb-thumb[data-shape="flash"]::before { inset: 12px 8px; border-radius: 4px;
            background: linear-gradient(120deg,#7b3fe4,#26abf2); }
        .tb-thumb[data-shape="flash"]::after { inset-inline-end: 14px; top: 50%; transform: translateY(-50%);
            width: 38%; height: 12px; border-radius: 2px;
            background: repeating-linear-gradient(90deg,#fff 0 20%,transparent 20% 26%); }
        .tb-thumb[data-shape="quotes"]::before { inset: 12px 8px; border-radius: 3px;
            background: repeating-linear-gradient(90deg,#39434f 0 30%,transparent 30% 35%); }
        .tb-thumb[data-shape="faq"]::before { inset: 10px 8px; border-radius: 3px;
            background: linear-gradient(90deg,#39434f 0 34%,transparent 34%),
                        repeating-linear-gradient(180deg,transparent 0 2px,#2c343f 2px 10px,transparent 10px 14px); }
        .tb-thumb[data-shape="text"]::before { inset: 14px 10px; border-radius: 2px;
            background: repeating-linear-gradient(180deg,#39434f 0 5px,transparent 5px 12px); }
        .tb-thumb[data-shape="form"]::before { inset: 22px 14px; border-radius: 100px; background: #39434f; }
        .tb-thumb[data-shape="bar"]::before { inset: 6px 8px auto; height: 10px; border-radius: 3px;
            background: linear-gradient(120deg,#5b46a8,#2f7fae); }
        .tb-thumb[data-shape="spacer"]::before { inset: 18px 10px; border: 1px dashed #39434f; border-radius: 3px; }
        .tb-thumb[data-shape="columns"]::before { inset: 10px 8px; border-radius: 3px;
            background: repeating-linear-gradient(90deg,#39434f 0 18%,transparent 18% 27%); }
        .tb-thumb[data-shape="block"]::before { inset: 12px; border-radius: 3px; background: #39434f; }

        .tb-chips { display: flex !important; flex-wrap: wrap; gap: .25rem; margin-top: .5rem; }
        .tb-chip { font-size: .62rem; line-height: 1; padding: .28rem .4rem; border-radius: .3rem;
            background: #1f262f; color: #97a1ad; text-transform: capitalize; }
        .tb-chip--block { background: #23342f; color: #7fc3ae; }
        .tb-media-grid { display: grid; grid-template-columns: repeat(auto-fill,minmax(120px,1fr)); gap: .55rem; }
        .tb-media-item { position: relative; border: 1px solid #262c36; border-radius: .45rem; overflow: hidden;
            background: #171c24; aspect-ratio: 1/1; }
        .tb-media-item:hover { border-color: #3d8b7a; }
        .tb-media-pick { display: block; width: 100%; height: 100%; padding: 0; border: 0; background: transparent; cursor: pointer; }
        .tb-media-item img { width: 100%; height: 100%; object-fit: cover; }
        .tb-media-trash { position: absolute; top: 4px; inset-inline-end: 4px; z-index: 2; width: 26px; height: 26px;
            display: grid; place-items: center; border: 0; border-radius: 6px; cursor: pointer;
            background: rgba(14,17,22,.72); color: #ff9b9b; opacity: 0; transition: opacity .15s; }
        .tb-media-item:hover .tb-media-trash, .tb-media-trash:focus-visible { opacity: 1; }

        @media (max-width: 1199px) {
            .tb-body { grid-template-columns: 220px minmax(0,1fr) 290px; }
        }
        @media (max-width: 991px) {
            .tb-body { grid-template-columns: 1fr; grid-template-rows: auto minmax(320px,1fr) auto; }
            .tb-pane--rail, .tb-pane--inspector { max-height: 34vh; }
        }
    </style>
@endpush

@section('content')
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
             data-editable="{{ $editable ? 1 : 0 }}"
             data-url-add="{{ route('admin.theme.builder.section.add') }}"
             data-url-update="{{ route('admin.theme.builder.section.update') }}"
             data-url-reorder="{{ route('admin.theme.builder.section.reorder') }}"
             data-url-toggle="{{ route('admin.theme.builder.section.toggle') }}"
             data-url-duplicate="{{ route('admin.theme.builder.section.duplicate') }}"
             data-url-delete="{{ route('admin.theme.builder.section.delete') }}"
             data-url-schema="{{ route('admin.theme.builder.section-schema') }}"
             data-url-block-schema="{{ route('admin.theme.builder.block-schema') }}"
             data-url-block-add="{{ route('admin.theme.builder.block.add') }}"
             data-url-block-update="{{ route('admin.theme.builder.block.update') }}"
             data-url-block-reorder="{{ route('admin.theme.builder.block.reorder') }}"
             data-url-block-toggle="{{ route('admin.theme.builder.block.toggle') }}"
             data-url-block-duplicate="{{ route('admin.theme.builder.block.duplicate') }}"
             data-url-block-delete="{{ route('admin.theme.builder.block.delete') }}"
             data-url-media-upload="{{ route('admin.theme.builder.media.upload') }}"
             data-url-media-library="{{ route('admin.theme.builder.media.library') }}"
             data-url-media-delete="{{ route('admin.theme.builder.media.delete') }}">

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
                    <nav class="tb-seg">
                        @foreach ($pages as $availablePage)
                            <a class="{{ $page === $availablePage ? 'is-active' : '' }}"
                               href="{{ route('admin.theme.builder.index', ['page' => $availablePage, 'version' => $version->id]) }}">
                                {{ translate($availablePage) }}
                            </a>
                        @endforeach
                    </nav>
                </div>

                <div class="tb-bar__group">
                    <span id="tb-status" class="tb-status" aria-live="polite"></span>
                    <div class="tb-seg" role="group" aria-label="{{ translate('device_preview') }}">
                        <button type="button" class="is-active" data-device="desktop" title="{{ translate('desktop') }}"><i class="fi fi-rr-computer"></i></button>
                        <button type="button" data-device="tablet" title="{{ translate('tablet') }}"><i class="fi fi-rr-tablet"></i></button>
                        <button type="button" data-device="mobile" title="{{ translate('mobile') }}"><i class="fi fi-rr-mobile-button"></i></button>
                    </div>
                    <button type="button" id="tb-refresh" class="tb-icon-btn" title="{{ translate('refresh_preview') }}"><i class="fi fi-rr-refresh"></i></button>
                    @if (!empty($previewUrl))
                        <a href="{{ $previewUrl }}" target="_blank" rel="noopener" class="tb-icon-btn" title="{{ translate('open_in_new_tab') }}">
                            <i class="fi fi-rr-arrow-up-right-from-square"></i>
                        </a>
                    @endif
                    @if (session(\App\Services\Theme\StorefrontThemeRenderer::PREVIEW_SESSION_KEY))
                        <a href="{{ route('admin.theme.builder.preview.stop') }}" class="tb-icon-btn">{{ translate('End_preview') }}</a>
                    @endif
                    <a href="{{ route('admin.theme.index') }}" class="tb-icon-btn tb-icon-btn--primary">{{ translate('publish') }}</a>
                </div>
            </header>

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
                                 aria-selected="false">
                                <span class="tb-item__grip"><i class="fi fi-rr-menu-burger"></i></span>
                                <span class="tb-item__label">{{ translate($section['label']) }}</span>
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
                        <div class="tb-cards">
                            @foreach ($sectionTypes as $key => $definition)
                                <button type="button" class="tb-card" data-type="{{ $key }}">
                                    <span class="tb-thumb" data-shape="{{ $definition['preview'] ?? 'block' }}" aria-hidden="true"></span>
                                    <strong>{{ translate($definition['label']) }}</strong>
                                    <span>{{ translate($definition['hint'] ?? $definition['label']) }}</span>
                                    <span class="tb-chips">
                                        @foreach ($definition['blocks'] ?? [] as $blockType)
                                            <span class="tb-chip tb-chip--block">+ {{ translate($blockLabels[$blockType] ?? $blockType) }}</span>
                                        @endforeach
                                        @foreach (array_slice($definition['schema'], 0, 5, true) as $optionKey => $option)
                                            <span class="tb-chip">{{ translate($option['label'] ?? $optionKey) }}</span>
                                        @endforeach
                                        @if (count($definition['schema']) > 5)
                                            <span class="tb-chip">+{{ count($definition['schema']) - 5 }}</span>
                                        @endif
                                    </span>
                                </button>
                            @endforeach
                        </div>
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
@endsection

@push('script')
    <script>
        "use strict";
        (function () {
            var root = document.querySelector('.tb-app');
            if (!root) return;

            var T = {
                saving: @json(translate('saving')),
                saved: @json(translate('saved')),
                unsaved: @json(translate('unsaved_changes')),
                failed: @json(translate('could_not_save_try_again')),
                inherit: @json(translate('inherit')),
                visible: @json(translate('visible')),
                hidden: @json(translate('hidden')),
                confirm: @json(translate('are_you_sure')),
                confirmDeleteSection: @json(translate('delete_this_section_and_everything_in_it')),
                confirmDeleteImage: @json(translate('delete_this_image_from_the_library')),
                deleteImage: @json(translate('delete_image')),
                blocks: @json(translate('items')),
                addBlock: @json(translate('add_item')),
                backToSection: @json(translate('back_to_section')),
                noBlocks: @json(translate('nothing_here_yet_add_the_first_item')),
                settings: @json(translate('settings')),
                upload: @json(translate('upload')),
                library: @json(translate('library')),
                clear: @json(translate('clear')),
                uploading: @json(translate('uploading')),
                emptyLibrary: @json(translate('no_images_uploaded_yet')),
                noLinkedBanner: @json(translate('no_linked_banner_use_the_image_below')),
                manageBanners: @json(translate('manage_banners_in_banner_setup')),
                readOnly: @json(translate('this_version_is_published_and_read_only_duplicate_it_to_a_draft_to_edit'))
            };

            var editable = root.dataset.editable === '1';
            var versionId = root.dataset.version;
            var page = root.dataset.page;
            var csrf = document.querySelector('meta[name="csrf-token"]');

            var state = {
                sectionId: null,
                sectionType: null,
                blockId: null,
                blockType: null,
                tab: 'content',
                schema: {},
                settings: {},
                contentKeys: [],
                styleKeys: [],
                accepts: [],
                blockLabels: {},
                blocks: [],
                dataNote: null,
                dirty: false
            };

            var structure = document.getElementById('tb-structure');
            var inspector = document.getElementById('tb-inspector');
            var inspectorTitle = document.getElementById('tb-inspector-title');
            var tabsBar = document.getElementById('tb-tabs');
            var actionsBar = document.getElementById('tb-actions');
            var statusEl = document.getElementById('tb-status');
            var frame = document.getElementById('tb-frame');
            var loader = document.getElementById('tb-loader');
            var stage = document.querySelector('.tb-stage');

            // The editor covers the whole viewport, so the page behind it must not scroll.
            document.body.style.overflow = 'hidden';

            // ---------- transport ----------------------------------------------------------
            function post(url, payload) {
                return fetch(url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': csrf ? csrf.getAttribute('content') : ''
                    },
                    body: JSON.stringify(payload)
                }).then(function (response) {
                    return response.json()
                        .catch(function () { return {}; })
                        .then(function (body) { return {ok: response.ok, body: body}; });
                });
            }

            function getJson(url) {
                return fetch(url, {headers: {'X-Requested-With': 'XMLHttpRequest'}}).then(function (r) { return r.json(); });
            }

            function notify(result) {
                if (!result.ok && result.body && result.body.message && window.toastMagic) {
                    toastMagic.error(result.body.message);
                }
                return result;
            }

            function setStatus(text, tone) {
                statusEl.textContent = text || '';
                statusEl.className = 'tb-status' + (tone ? ' is-' + tone : '');
            }

            // ---------- live preview -------------------------------------------------------
            var refreshTimer = null;

            function refreshPreview() {
                if (!frame) return;
                clearTimeout(refreshTimer);
                refreshTimer = setTimeout(function () {
                    if (loader) loader.classList.remove('is-hidden');
                    // Same-origin, so the scroll position can be carried across the reload — the
                    // difference between "the preview updated" and "the preview jumped to the top".
                    try { frame.dataset.scroll = String(frame.contentWindow.scrollY || 0); } catch (e) { /* ignore */ }
                    var base = frame.src.split('#')[0].replace(/([?&])tb=\d+/, '$1').replace(/[?&]$/, '');
                    frame.src = base + (base.indexOf('?') === -1 ? '?' : '&') + 'tb=' + Date.now();
                }, 120);
            }

            function decorateFrame() {
                if (loader) loader.classList.add('is-hidden');
                var doc;
                try { doc = frame.contentDocument; } catch (e) { return; }
                if (!doc) return;

                try {
                    var restore = parseInt(frame.dataset.scroll || '0', 10);
                    if (restore > 0) {
                        frame.contentWindow.scrollTo(0, restore);
                    } else if (page === 'footer') {
                        // Editing the footer: land the preview ON the footer instead of asking the
                        // merchant to scroll a whole home page to find their change.
                        frame.contentWindow.scrollTo(0, doc.body.scrollHeight);
                    }
                } catch (e) { /* ignore */ }

                var style = doc.createElement('style');
                style.textContent = '[data-tb-section]{position:relative;transition:box-shadow .18s}'
                    + '[data-tb-section]:hover{box-shadow:inset 0 0 0 2px rgba(61,139,122,.55)}'
                    + '[data-tb-section].tb-selected{box-shadow:inset 0 0 0 2px #3d8b7a}';
                doc.head && doc.head.appendChild(style);

                doc.querySelectorAll('[data-tb-section]').forEach(function (node) {
                    node.style.cursor = 'pointer';
                    // Capture phase: a click on a link inside the section selects the section
                    // instead of navigating the preview away from the page being edited.
                    node.addEventListener('click', function (event) {
                        event.preventDefault();
                        event.stopPropagation();
                        var item = structure.querySelector('.tb-item[data-id="' + node.getAttribute('data-tb-section') + '"]');
                        if (item) selectSection(item, false);
                    }, true);
                });

                markSelectedInFrame();
            }

            function markSelectedInFrame(scrollTo) {
                var doc;
                try { doc = frame && frame.contentDocument; } catch (e) { return; }
                if (!doc) return;
                doc.querySelectorAll('[data-tb-section]').forEach(function (node) { node.classList.remove('tb-selected'); });
                if (!state.sectionId) return;
                var target = doc.querySelector('[data-tb-section="' + state.sectionId + '"]');
                if (!target) return;
                target.classList.add('tb-selected');
                if (scrollTo) target.scrollIntoView({behavior: 'smooth', block: 'start'});
            }

            if (frame) {
                frame.addEventListener('load', decorateFrame);
                setTimeout(function () { if (loader) loader.classList.add('is-hidden'); }, 6000);
            }

            document.querySelectorAll('.tb-gap-add').forEach(function (button) {
                button.addEventListener('click', function () {
                    button.disabled = true;
                    post(root.dataset.urlAdd, {version_id: versionId, page: page, type: 'store_banner'})
                        .then(function (added) {
                            var list = (added.body && added.body.section) || [];
                            var created = list[list.length - 1];
                            if (!created) throw new Error('add failed');
                            return post(root.dataset.urlUpdate, {
                                section_id: created.id,
                                settings: Object.assign({}, created.settings, {
                                    banner_type: button.dataset.bannerType,
                                    layout: button.dataset.layout
                                })
                            }).then(function () {
                                // Main Banner belongs at the very top; other slots keep their appended spot.
                                if (button.dataset.bannerType !== 'Main Banner') return null;
                                var ids = [created.id].concat(list
                                    .filter(function (x) { return x.id !== created.id; })
                                    .map(function (x) { return x.id; }));
                                return post(root.dataset.urlReorder, {version_id: versionId, page: page, order: ids});
                            });
                        })
                        .then(function () { window.location.reload(); })
                        .catch(function () { button.disabled = false; });
                });
            });

            var refreshButton = document.getElementById('tb-refresh');
            if (refreshButton) refreshButton.addEventListener('click', refreshPreview);

            document.querySelectorAll('.tb-seg button[data-device]').forEach(function (button) {
                button.addEventListener('click', function () {
                    document.querySelectorAll('.tb-seg button[data-device]').forEach(function (other) { other.classList.remove('is-active'); });
                    button.classList.add('is-active');
                    if (stage) stage.dataset.device = button.dataset.device;
                });
            });

            // ---------- form controls ------------------------------------------------------
            function buildInput(field, key, value, optional) {
                var input;
                var resolved = (value === undefined || value === null) ? field.default : value;

                if (field.type === 'boolean' && optional) {
                    // A breakpoint override needs a third state — inherit — which a checkbox cannot express.
                    input = document.createElement('select');
                    [['', T.inherit], ['1', T.visible], ['0', T.hidden]].forEach(function (option) {
                        var node = document.createElement('option');
                        node.value = option[0];
                        node.textContent = option[1];
                        if (value !== undefined && value !== null && String(value) !== ''
                            && option[0] === ((value === true || value === 'true' || value === 1 || value === '1') ? '1' : '0')) {
                            node.selected = true;
                        }
                        input.appendChild(node);
                    });
                } else if (field.type === 'boolean') {
                    input = document.createElement('input');
                    input.type = 'checkbox';
                    input.checked = (resolved === true || resolved === 'true' || resolved === 1 || resolved === '1');
                } else if (field.type === 'select' || field.type === 'source') {
                    input = document.createElement('select');
                    (field.options || []).forEach(function (option) {
                        var node = document.createElement('option');
                        node.value = option;
                        node.textContent = option;
                        if (String(option) === String(resolved)) node.selected = true;
                        input.appendChild(node);
                    });
                } else if (field.type === 'banner') {
                    // Live picker over Promotion -> Banners rows: linking one makes the block render
                    // that banner's image/link/text, so edits in Banner Setup show up here too.
                    input = document.createElement('select');
                    var none = document.createElement('option');
                    none.value = '';
                    none.textContent = T.noLinkedBanner;
                    input.appendChild(none);
                    (field.choices || []).forEach(function (choice) {
                        var node = document.createElement('option');
                        node.value = choice.value;
                        node.textContent = choice.label;
                        if (String(choice.value) === String(resolved || '')) node.selected = true;
                        input.appendChild(node);
                    });
                } else if (field.type === 'textarea') {
                    input = document.createElement('textarea');
                    input.rows = 3;
                    input.value = (resolved === null || resolved === undefined) ? '' : resolved;
                } else {
                    input = document.createElement('input');
                    input.type = field.type === 'number' ? 'number' : (field.type === 'color' ? 'color' : 'text');
                    if (resolved !== null && resolved !== undefined) input.value = resolved;
                    // A colour input reports #000000 when empty, so an untouched empty colour would
                    // be saved as black. Mark it and drop it on collect unless it is actually picked.
                    if (field.type === 'color' && (resolved === null || resolved === undefined || resolved === '')) {
                        input.dataset.unset = '1';
                    }
                }

                input.className = 'tb-input';
                input.dataset.key = key;
                input.disabled = !editable;
                input.addEventListener('input', onFieldChanged);
                input.addEventListener('change', onFieldChanged);

                function onFieldChanged() {
                    delete input.dataset.unset;
                    scheduleAutosave();
                }

                return input;
            }

            /** Image field: preview + upload + library + clear, all writing to one hidden text input. */
            function buildImageField(field, key, value) {
                var wrapper = document.createElement('div');
                wrapper.className = 'tb-image';

                var preview = document.createElement('div');
                preview.className = 'tb-image__preview';

                var hidden = document.createElement('input');
                hidden.type = 'text';
                hidden.className = 'tb-input';
                hidden.dataset.key = key;
                hidden.placeholder = 'https://…';
                hidden.value = (value === null || value === undefined) ? (field.default || '') : value;
                hidden.disabled = !editable;
                hidden.addEventListener('input', function () { paint(); scheduleAutosave(); });

                function paint() {
                    preview.innerHTML = '';
                    if (hidden.value) {
                        var image = document.createElement('img');
                        image.src = hidden.value;
                        image.alt = '';
                        preview.appendChild(image);
                    } else {
                        preview.innerHTML = '<i class="fi fi-rr-picture"></i>';
                    }
                }

                var side = document.createElement('div');
                side.className = 'tb-image__side';
                side.appendChild(hidden);

                var actions = document.createElement('div');
                actions.className = 'tb-image__actions';

                [[T.upload, function () { pickFile(hidden, paint); }],
                 [T.library, function () { openLibrary(hidden, paint); }],
                 [T.clear, function () { hidden.value = ''; paint(); scheduleAutosave(); }]
                ].forEach(function (definition) {
                    var button = document.createElement('button');
                    button.type = 'button';
                    button.textContent = definition[0];
                    button.disabled = !editable;
                    button.addEventListener('click', definition[1]);
                    actions.appendChild(button);
                });

                side.appendChild(actions);
                wrapper.appendChild(preview);
                wrapper.appendChild(side);
                paint();

                return wrapper;
            }

            function renderFields(host, schema, settings, keys) {
                keys.forEach(function (key) {
                    var field = schema[key];
                    if (!field) return;

                    var wrapper = document.createElement('div');
                    wrapper.className = 'tb-field';

                    var label = document.createElement('label');
                    label.textContent = (field.label || key).replace(/_/g, ' ');
                    wrapper.appendChild(label);

                    wrapper.appendChild(field.type === 'image'
                        ? buildImageField(field, key, settings[key])
                        : buildInput(field, key, settings[key]));

                    if (field.type === 'banner' && field.manage_url) {
                        var manage = document.createElement('a');
                        manage.href = field.manage_url;
                        manage.target = '_blank';
                        manage.rel = 'noopener';
                        manage.className = 'tb-hint';
                        manage.textContent = T.manageBanners;
                        wrapper.appendChild(manage);
                    }

                    if (field.responsive) {
                        var group = document.createElement('div');
                        group.className = 'tb-sub';
                        [['tablet', @json(translate('tablet'))], ['mobile', @json(translate('mobile'))]].forEach(function (breakpoint) {
                            var column = document.createElement('div');
                            var subLabel = document.createElement('label');
                            subLabel.textContent = breakpoint[1];
                            column.appendChild(subLabel);

                            var responsiveKey = key + '_' + breakpoint[0];
                            // No default on an override: blank means "inherit the base value".
                            var responsiveField = Object.assign({}, field, {default: null});
                            var input = buildInput(responsiveField, responsiveKey, settings[responsiveKey], true);
                            input.dataset.optional = '1';
                            column.appendChild(input);
                            group.appendChild(column);
                        });
                        wrapper.appendChild(group);
                    }

                    host.appendChild(wrapper);
                });
            }

            function collectSettings() {
                var out = {};
                inspector.querySelectorAll('[data-key]').forEach(function (element) {
                    if (element.dataset.unset === '1') return;
                    var value = element.type === 'checkbox' ? element.checked : element.value;
                    if (element.dataset.optional === '1' && value === '') return;
                    out[element.dataset.key] = value;
                });
                return out;
            }

            // ---------- inspector ----------------------------------------------------------
            function renderInspector() {
                inspector.innerHTML = '';

                if (state.blockId) {
                    renderBlockEditor();
                    return;
                }

                tabsBar.hidden = false;
                actionsBar.hidden = !state.sectionId;
                inspectorTitle.textContent = state.sectionLabel || T.settings;

                // A data-driven section with nothing to draw renders nothing at all; say so here
                // rather than letting it look like a broken option.
                if (state.dataNote) {
                    var dataNote = document.createElement('p');
                    dataNote.className = 'tb-data-note';
                    dataNote.textContent = state.dataNote;
                    inspector.appendChild(dataNote);
                }

                var keys = state.tab === 'style' ? state.styleKeys : state.contentKeys;
                renderFields(inspector, state.schema, state.settings, keys);

                if (state.tab === 'content' && state.accepts.length) {
                    renderBlockList();
                }

                if (!editable) {
                    var note = document.createElement('p');
                    note.className = 'tb-hint';
                    note.textContent = T.readOnly;
                    inspector.appendChild(note);
                }
            }

            function renderBlockList() {
                var title = document.createElement('div');
                title.className = 'tb-group-title';
                title.textContent = T.blocks;
                inspector.appendChild(title);

                var list = document.createElement('div');
                list.id = 'tb-block-list';
                inspector.appendChild(list);

                if (!state.blocks.length) {
                    var empty = document.createElement('p');
                    empty.className = 'tb-hint';
                    empty.textContent = T.noBlocks;
                    list.appendChild(empty);
                }

                state.blocks.forEach(function (block) {
                    var row = document.createElement('div');
                    row.className = 'tb-block' + (block.is_visible ? '' : ' is-hidden');
                    row.draggable = editable;
                    row.dataset.id = block.id;

                    var thumb = document.createElement('div');
                    thumb.className = 'tb-block__thumb';
                    if (block.image) {
                        var image = document.createElement('img');
                        image.src = block.image;
                        image.alt = '';
                        thumb.appendChild(image);
                    } else {
                        thumb.innerHTML = '<i class="fi fi-rr-picture"></i>';
                    }

                    var label = document.createElement('span');
                    label.className = 'tb-block__label';
                    label.textContent = block.label;

                    var eye = document.createElement('button');
                    eye.type = 'button';
                    eye.className = 'tb-item__eye';
                    eye.disabled = !editable;
                    eye.innerHTML = '<i class="fi ' + (block.is_visible ? 'fi-rr-eye' : 'fi-rr-eye-crossed') + '"></i>';
                    eye.addEventListener('click', function (event) {
                        event.stopPropagation();
                        post(root.dataset.urlBlockToggle, {block_id: block.id, visible: !block.is_visible})
                            .then(notify).then(function (result) {
                                if (!result.ok) return;
                                state.blocks = result.body.blocks || [];
                                renderInspector();
                                refreshPreview();
                            });
                    });

                    var remove = document.createElement('button');
                    remove.type = 'button';
                    remove.className = 'tb-item__eye';
                    remove.disabled = !editable;
                    remove.innerHTML = '<i class="fi fi-rr-trash"></i>';
                    remove.addEventListener('click', function (event) {
                        event.stopPropagation();
                        if (!confirm(T.confirm)) return;
                        post(root.dataset.urlBlockDelete, {block_id: block.id}).then(notify).then(function (result) {
                            if (!result.ok) return;
                            state.blocks = result.body.blocks || [];
                            renderInspector();
                            refreshPreview();
                        });
                    });

                    row.appendChild(thumb);
                    row.appendChild(label);
                    row.appendChild(eye);
                    row.appendChild(remove);
                    row.addEventListener('click', function () { openBlock(block.id); });
                    list.appendChild(row);
                });

                if (editable) {
                    enableBlockDragging(list);

                    var adders = document.createElement('div');
                    adders.className = 'tb-image__actions mt-2';
                    state.accepts.forEach(function (type) {
                        var button = document.createElement('button');
                        button.type = 'button';
                        button.textContent = '+ ' + (state.blockLabels[type] || type);
                        button.addEventListener('click', function () { addBlock(type); });
                        adders.appendChild(button);
                    });
                    inspector.appendChild(adders);
                }
            }

            function renderBlockEditor() {
                tabsBar.hidden = true;
                actionsBar.hidden = true;
                inspectorTitle.textContent = state.blockLabels[state.blockType] || state.blockType;

                var back = document.createElement('button');
                back.type = 'button';
                back.className = 'tb-back';
                back.innerHTML = '<i class="fi fi-rr-angle-left"></i> ' + T.backToSection;
                back.addEventListener('click', function () {
                    flushAutosave();
                    state.blockId = null;
                    state.blockType = null;
                    loadSection(state.sectionId, state.sectionType, false);
                });
                inspector.appendChild(back);

                renderFields(inspector, state.blockSchema || {}, state.blockSettings || {}, Object.keys(state.blockSchema || {}));

                if (editable) {
                    var actions = document.createElement('div');
                    actions.className = 'tb-image__actions mt-2';

                    var duplicate = document.createElement('button');
                    duplicate.type = 'button';
                    duplicate.textContent = @json(translate('duplicate'));
                    duplicate.addEventListener('click', function () {
                        post(root.dataset.urlBlockDuplicate, {block_id: state.blockId}).then(notify).then(function (result) {
                            if (!result.ok) return;
                            state.blocks = result.body.blocks || [];
                            state.blockId = null;
                            renderInspector();
                            refreshPreview();
                        });
                    });

                    var remove = document.createElement('button');
                    remove.type = 'button';
                    remove.textContent = @json(translate('delete'));
                    remove.addEventListener('click', function () {
                        if (!confirm(T.confirm)) return;
                        post(root.dataset.urlBlockDelete, {block_id: state.blockId}).then(notify).then(function (result) {
                            if (!result.ok) return;
                            state.blocks = result.body.blocks || [];
                            state.blockId = null;
                            renderInspector();
                            refreshPreview();
                        });
                    });

                    actions.appendChild(duplicate);
                    actions.appendChild(remove);
                    inspector.appendChild(actions);
                }
            }

            function enableBlockDragging(list) {
                var dragged = null;
                list.addEventListener('dragstart', function (event) {
                    dragged = event.target.closest('.tb-block');
                    if (dragged) dragged.style.opacity = '.45';
                });
                list.addEventListener('dragend', function () {
                    if (dragged) dragged.style.opacity = '';
                    dragged = null;
                });
                list.addEventListener('dragover', function (event) { event.preventDefault(); });
                list.addEventListener('drop', function (event) {
                    event.preventDefault();
                    var target = event.target.closest('.tb-block');
                    if (!dragged || !target || target === dragged) return;

                    var rows = Array.prototype.slice.call(list.querySelectorAll('.tb-block'));
                    if (rows.indexOf(dragged) < rows.indexOf(target)) { target.after(dragged); } else { target.before(dragged); }

                    var order = Array.prototype.map.call(list.querySelectorAll('.tb-block'), function (row) { return row.dataset.id; });
                    post(root.dataset.urlBlockReorder, {section_id: state.sectionId, order: order})
                        .then(notify).then(function (result) {
                            if (!result.ok) return;
                            state.blocks = result.body.blocks || [];
                            refreshPreview();
                        });
                });
            }

            function addBlock(type) {
                post(root.dataset.urlBlockAdd, {section_id: state.sectionId, type: type})
                    .then(notify).then(function (result) {
                        if (!result.ok) return;
                        state.blocks = result.body.blocks || [];
                        renderInspector();
                        refreshPreview();
                        if (result.body.id) openBlock(result.body.id);
                    });
            }

            function openBlock(blockId) {
                flushAutosave();
                getJson(root.dataset.urlBlockSchema + '?block_id=' + encodeURIComponent(blockId)).then(function (data) {
                    if (!data || data.status !== 'success') return;
                    state.blockId = blockId;
                    state.blockType = data.type;
                    state.blockSchema = data.schema || {};
                    state.blockSettings = data.settings || {};
                    renderInspector();
                });
            }

            // ---------- sections ------------------------------------------------------------
            function selectSection(item, scrollPreview) {
                flushAutosave();
                document.querySelectorAll('.tb-item').forEach(function (other) { other.setAttribute('aria-selected', 'false'); });
                item.setAttribute('aria-selected', 'true');
                state.blockId = null;
                state.sectionLabel = item.querySelector('.tb-item__label').textContent.trim();
                loadSection(item.dataset.id, item.dataset.type, scrollPreview !== false);
            }

            function loadSection(sectionId, sectionType, scrollPreview) {
                var url = root.dataset.urlSchema
                    + '?type=' + encodeURIComponent(sectionType)
                    + '&section_id=' + encodeURIComponent(sectionId);

                getJson(url).then(function (data) {
                    if (!data || data.status !== 'success') return;
                    state.sectionId = sectionId;
                    state.sectionType = sectionType;
                    state.schema = data.schema || {};
                    state.settings = data.settings || {};
                    state.contentKeys = data.contentKeys || [];
                    state.styleKeys = data.styleKeys || [];
                    state.accepts = data.accepts || [];
                    state.blockLabels = data.blockLabels || {};
                    state.blocks = data.blocks || [];
                    state.dataNote = data.dataNote || null;
                    renderInspector();
                    markSelectedInFrame(scrollPreview);
                });
            }

            structure.addEventListener('click', function (event) {
                var toggle = event.target.closest('[data-action="toggle"]');
                var remove = event.target.closest('[data-action="remove"]');
                var item = event.target.closest('.tb-item');
                if (!item) return;

                if (remove) {
                    event.stopPropagation();
                    if (!confirm(T.confirmDeleteSection)) return;
                    post(root.dataset.urlDelete, {section_id: item.dataset.id})
                        .then(notify).then(function (result) { if (result.ok) location.reload(); });
                    return;
                }

                if (toggle) {
                    event.stopPropagation();
                    var makeVisible = item.dataset.visible !== '1';
                    post(root.dataset.urlToggle, {section_id: item.dataset.id, visible: makeVisible})
                        .then(notify).then(function (result) {
                            if (!result.ok) return;
                            item.dataset.visible = makeVisible ? '1' : '0';
                            item.classList.toggle('is-hidden', !makeVisible);
                            toggle.innerHTML = '<i class="fi ' + (makeVisible ? 'fi-rr-eye' : 'fi-rr-eye-crossed') + '"></i>';
                            refreshPreview();
                        });
                    return;
                }

                selectSection(item);
            });

            // ---------- autosave -------------------------------------------------------------
            var autosaveTimer = null;
            var AUTOSAVE_DELAY = 700;

            function scheduleAutosave() {
                if (!editable || (!state.sectionId && !state.blockId)) return;
                state.dirty = true;
                clearTimeout(autosaveTimer);
                setStatus(T.unsaved, 'saving');
                autosaveTimer = setTimeout(runAutosave, AUTOSAVE_DELAY);
            }

            function flushAutosave() {
                if (autosaveTimer) {
                    clearTimeout(autosaveTimer);
                    autosaveTimer = null;
                    runAutosave();
                }
            }

            function runAutosave() {
                if (!editable || !state.dirty) return;

                var payload = state.blockId
                    ? {url: root.dataset.urlBlockUpdate, body: {block_id: state.blockId, settings: collectSettings()}}
                    : {url: root.dataset.urlUpdate, body: {section_id: state.sectionId, settings: collectSettings()}};

                setStatus(T.saving, 'saving');
                post(payload.url, payload.body).then(function (result) {
                    if (!result.ok) {
                        setStatus(T.failed, 'error');   // keep `dirty` so the unload warning still fires
                        return;
                    }
                    state.dirty = false;
                    if (result.body.blocks) state.blocks = result.body.blocks;
                    setStatus(T.saved, 'saved');
                    refreshPreview();
                }).catch(function () { setStatus(T.failed, 'error'); });
            }

            document.getElementById('tb-save') && document.getElementById('tb-save').addEventListener('click', function () {
                state.dirty = true;
                clearTimeout(autosaveTimer);
                runAutosave();
            });

            tabsBar.addEventListener('click', function (event) {
                var button = event.target.closest('button[data-tab]');
                if (!button) return;
                flushAutosave();
                state.tab = button.dataset.tab;
                tabsBar.querySelectorAll('button').forEach(function (other) { other.classList.toggle('is-active', other === button); });
                renderInspector();
            });

            // ---------- media ----------------------------------------------------------------
            var fileInput = document.getElementById('tb-file');
            var mediaOverlay = document.getElementById('tb-media');
            var mediaGrid = document.getElementById('tb-media-grid');
            var mediaTarget = null;
            var mediaPaint = null;

            function pickFile(target, paint) {
                mediaTarget = target;
                mediaPaint = paint;
                fileInput.value = '';
                fileInput.click();
            }

            fileInput && fileInput.addEventListener('change', function () {
                var file = fileInput.files && fileInput.files[0];
                if (!file || !mediaTarget) return;

                var form = new FormData();
                form.append('image', file);
                form.append('version_id', versionId);
                setStatus(T.uploading, 'saving');

                fetch(root.dataset.urlMediaUpload, {
                    method: 'POST',
                    headers: {'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf ? csrf.getAttribute('content') : ''},
                    body: form
                }).then(function (response) { return response.json(); }).then(function (body) {
                    if (body.status !== 'success' || !body.url) {
                        setStatus(body.message || T.failed, 'error');
                        return;
                    }
                    mediaTarget.value = body.url;
                    if (mediaPaint) mediaPaint();
                    scheduleAutosave();
                }).catch(function () { setStatus(T.failed, 'error'); });
            });

            function openLibrary(target, paint) {
                mediaTarget = target;
                mediaPaint = paint;
                mediaGrid.innerHTML = '';
                mediaOverlay.classList.add('is-open');

                getJson(root.dataset.urlMediaLibrary + '?version_id=' + encodeURIComponent(versionId)).then(function (data) {
                    var items = (data && data.items) || [];
                    if (!items.length) {
                        mediaGrid.innerHTML = '<p class="tb-hint">' + T.emptyLibrary + '</p>';
                        return;
                    }
                    items.forEach(function (item) {
                        var cell = document.createElement('div');
                        cell.className = 'tb-media-item';
                        cell.title = item.label || '';

                        var pick = document.createElement('button');
                        pick.type = 'button';
                        pick.className = 'tb-media-pick';
                        pick.innerHTML = '<img src="' + item.url + '" alt="">';
                        pick.addEventListener('click', function () {
                            mediaTarget.value = item.url;
                            if (mediaPaint) mediaPaint();
                            mediaOverlay.classList.remove('is-open');
                            scheduleAutosave();
                        });
                        cell.appendChild(pick);

                        if (editable) {
                            var trash = document.createElement('button');
                            trash.type = 'button';
                            trash.className = 'tb-media-trash';
                            trash.title = T.deleteImage;
                            trash.innerHTML = '<i class="fi fi-rr-trash"></i>';
                            trash.addEventListener('click', function (event) {
                                event.stopPropagation();
                                if (!confirm(T.confirmDeleteImage)) return;
                                post(root.dataset.urlMediaDelete, {id: item.id})
                                    .then(notify).then(function (result) { if (result.ok) cell.remove(); });
                            });
                            cell.appendChild(trash);
                        }

                        mediaGrid.appendChild(cell);
                    });
                });
            }

            // ---------- overlays --------------------------------------------------------------
            document.querySelectorAll('.tb-overlay').forEach(function (overlay) {
                overlay.addEventListener('click', function (event) {
                    if (event.target === overlay || event.target.closest('[data-close]')) {
                        overlay.classList.remove('is-open');
                    }
                });
            });

            document.addEventListener('keydown', function (event) {
                if (event.key !== 'Escape') return;
                document.querySelectorAll('.tb-overlay.is-open').forEach(function (overlay) { overlay.classList.remove('is-open'); });
            });

            // Open on the first section so the inspector is never an empty panel.
            var firstSection = structure.querySelector('.tb-item');
            if (firstSection) selectSection(firstSection, false);

            if (!editable) return;   // read-only: browsing and preview only

            var picker = document.getElementById('tb-picker');
            document.getElementById('tb-open-picker').addEventListener('click', function () { picker.classList.add('is-open'); });
            picker.querySelectorAll('.tb-card[data-type]').forEach(function (card) {
                card.addEventListener('click', function () {
                    post(root.dataset.urlAdd, {version_id: versionId, page: page, type: card.dataset.type})
                        .then(notify).then(function (result) { if (result.ok) location.reload(); });
                });
            });

            // ---------- section reordering ------------------------------------------------------
            var dragged = null;
            structure.addEventListener('dragstart', function (event) {
                dragged = event.target.closest('.tb-item');
                if (dragged) dragged.classList.add('is-dragging');
            });
            structure.addEventListener('dragend', function () {
                if (dragged) dragged.classList.remove('is-dragging');
                document.querySelectorAll('.tb-item').forEach(function (item) { item.classList.remove('is-drop'); });
                dragged = null;
            });
            structure.addEventListener('dragover', function (event) {
                event.preventDefault();
                var over = event.target.closest('.tb-item');
                if (!over || over === dragged) return;
                document.querySelectorAll('.tb-item').forEach(function (item) { item.classList.remove('is-drop'); });
                over.classList.add('is-drop');
            });
            structure.addEventListener('drop', function (event) {
                event.preventDefault();
                var target = event.target.closest('.tb-item');
                if (!dragged || !target || target === dragged) return;

                var items = Array.prototype.slice.call(structure.querySelectorAll('.tb-item'));
                if (items.indexOf(dragged) < items.indexOf(target)) { target.after(dragged); } else { target.before(dragged); }

                var order = Array.prototype.map.call(structure.querySelectorAll('.tb-item'), function (item) { return item.dataset.id; });
                post(root.dataset.urlReorder, {version_id: versionId, page: page, order: order})
                    .then(notify).then(function (result) { if (result.ok) refreshPreview(); });
            });

            document.getElementById('tb-duplicate').addEventListener('click', function () {
                if (!state.sectionId) return;
                post(root.dataset.urlDuplicate, {section_id: state.sectionId})
                    .then(notify).then(function (result) { if (result.ok) location.reload(); });
            });

            document.getElementById('tb-delete').addEventListener('click', function () {
                if (!state.sectionId || !confirm(T.confirm)) return;
                post(root.dataset.urlDelete, {section_id: state.sectionId})
                    .then(notify).then(function (result) { if (result.ok) location.reload(); });
            });

            window.addEventListener('beforeunload', function (event) {
                if (!state.dirty) return;
                event.preventDefault();
                event.returnValue = '';
            });
        })();
    </script>
@endpush
