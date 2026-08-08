@extends('layouts.admin.app')

@section('title', translate('Theme_Builder'))

@push('css_or_js')
    <style>
        .tb-wrap { display: grid; grid-template-columns: 280px 1fr 320px; gap: 1rem; align-items: start; }
        @media (max-width: 1199px) { .tb-wrap { grid-template-columns: 1fr; } }
        .tb-panel { background: var(--bs-body-bg, #fff); border: 1px solid rgba(0,0,0,.08); border-radius: .5rem; }
        .tb-panel__head { padding: .75rem 1rem; border-bottom: 1px solid rgba(0,0,0,.08); font-weight: 600; }
        .tb-panel__body { padding: .75rem 1rem; }
        .tb-section-item { display: flex; align-items: center; gap: .5rem; padding: .5rem .6rem; border: 1px solid rgba(0,0,0,.08);
            border-radius: .375rem; margin-bottom: .4rem; cursor: grab; background: #fff; }
        .tb-section-item[aria-selected="true"] { border-color: #0f766e; box-shadow: 0 0 0 2px rgba(15,118,110,.15); }
        .tb-section-item.dragging { opacity: .5; }
        .tb-section-item.drop-target { border-color: #0f766e; border-style: dashed; }
        .tb-section-item__label { flex: 1; font-size: .875rem; }
        .tb-hidden-badge { font-size: .7rem; }
        .tb-preview-frame { width: 100%; border: 1px solid rgba(0,0,0,.08); border-radius: .5rem; background: #fff; min-height: 480px; transition: max-width .2s; margin: 0 auto; }
        .tb-preview-frame[data-device="tablet"] { max-width: 768px; }
        .tb-preview-frame[data-device="mobile"] { max-width: 390px; }
        .tb-preview-block { padding: 1rem; border-bottom: 1px dashed rgba(0,0,0,.12); font-size: .85rem; }
        .tb-preview-block[data-hidden="1"] { opacity: .4; }
        .tb-field { margin-bottom: .75rem; }
        .tb-field label { font-size: .8rem; font-weight: 600; display: block; margin-bottom: .25rem; }
        .tb-dirty-bar { position: sticky; bottom: 0; z-index: 5; }
    </style>
@endpush

@section('content')
    <div class="content container-fluid">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <h2 class="h1 mb-0 text-capitalize">{{ translate('Theme_Builder') }}</h2>
            <div class="d-flex flex-wrap align-items-center gap-2">
                @if($version)
                    <span class="badge {{ $editable ? 'badge-soft-warning' : 'badge-soft-success' }}">
                        {{ $editable ? translate('draft') : translate('published') }} #{{ $version->id }}
                    </span>
                @endif
                <a href="{{ route('admin.theme.index') }}" class="btn btn-sm btn-outline-secondary">{{ translate('Theme_Management') }}</a>
            </div>
        </div>

        @if(!$version)
            <div class="alert alert-info">
                {{ translate('no_theme_version_is_available_create_a_theme_first') }}
                <a href="{{ route('admin.theme.index') }}">{{ translate('Theme_Management') }}</a>
            </div>
        @else
            @unless($editable)
                <div class="alert alert-warning">
                    {{ translate('this_version_is_published_and_read_only_duplicate_it_to_a_draft_to_edit') }}
                </div>
            @endunless

            {{-- page switcher --}}
            <ul class="nav nav-pills mb-3">
                @foreach($pages as $p)
                    <li class="nav-item">
                        <a class="nav-link {{ $page === $p ? 'active' : '' }}"
                           href="{{ route('admin.theme.builder.index', ['page' => $p, 'version' => $version->id]) }}">
                            {{ translate($p) }}
                        </a>
                    </li>
                @endforeach
            </ul>

            <div class="tb-wrap"
                 data-version="{{ $version->id }}"
                 data-page="{{ $page }}"
                 data-editable="{{ $editable ? 1 : 0 }}"
                 data-url-add="{{ route('admin.theme.builder.section.add') }}"
                 data-url-update="{{ route('admin.theme.builder.section.update') }}"
                 data-url-reorder="{{ route('admin.theme.builder.section.reorder') }}"
                 data-url-toggle="{{ route('admin.theme.builder.section.toggle') }}"
                 data-url-duplicate="{{ route('admin.theme.builder.section.duplicate') }}"
                 data-url-delete="{{ route('admin.theme.builder.section.delete') }}"
                 data-url-schema="{{ route('admin.theme.builder.section-schema') }}">

                {{-- LEFT: structure --}}
                <div class="tb-panel">
                    <div class="tb-panel__head d-flex justify-content-between align-items-center">
                        <span>{{ translate('page_structure') }}</span>
                    </div>
                    <div class="tb-panel__body">
                        <div id="tb-structure">
                            @forelse($structure as $section)
                                <div class="tb-section-item" draggable="{{ $editable ? 'true' : 'false' }}"
                                     data-id="{{ $section['id'] }}" data-type="{{ $section['type'] }}" aria-selected="false">
                                    <i class="fi fi-rr-menu-burger text-muted"></i>
                                    <span class="tb-section-item__label">{{ translate($section['label']) }}</span>
                                    @unless($section['is_visible'])
                                        <span class="badge badge-soft-secondary tb-hidden-badge">{{ translate('hidden') }}</span>
                                    @endunless
                                </div>
                            @empty
                                <p class="text-muted small mb-0" id="tb-empty">{{ translate('no_sections_yet_add_one_below') }}</p>
                            @endforelse
                        </div>

                        @if($editable)
                            <hr>
                            <label class="form-label small fw-bold">{{ translate('add_section') }}</label>
                            <div class="d-flex gap-2">
                                <select id="tb-new-type" class="form-control form-control-sm">
                                    @foreach($sectionTypes as $key => $def)
                                        <option value="{{ $key }}">{{ translate($def['label']) }}</option>
                                    @endforeach
                                </select>
                                <button type="button" id="tb-add" class="btn btn-sm btn-primary">{{ translate('add') }}</button>
                            </div>
                        @endif
                    </div>
                </div>

                {{-- CENTER: preview --}}
                <div class="tb-panel">
                    <div class="tb-panel__head d-flex justify-content-between align-items-center">
                        <span>{{ translate('preview') }}</span>
                        <div class="btn-group btn-group-sm" role="group" aria-label="{{ translate('device_preview') }}">
                            <button type="button" class="btn btn-outline-secondary active" data-device="desktop">{{ translate('desktop') }}</button>
                            <button type="button" class="btn btn-outline-secondary" data-device="tablet">{{ translate('tablet') }}</button>
                            <button type="button" class="btn btn-outline-secondary" data-device="mobile">{{ translate('mobile') }}</button>
                        </div>
                    </div>
                    <div class="tb-panel__body">
                        <div class="tb-preview-frame" id="tb-preview" data-device="desktop">
                            @foreach($structure as $section)
                                <div class="tb-preview-block" data-id="{{ $section['id'] }}" data-hidden="{{ $section['is_visible'] ? 0 : 1 }}">
                                    <strong>{{ translate($section['label']) }}</strong>
                                    @if(!empty($section['settings']['title']))
                                        <div class="text-muted">{{ $section['settings']['title'] }}</div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                        <p class="text-muted small mt-2 mb-0">{{ translate('this_is_a_structural_preview_publish_to_see_it_live_on_the_storefront') }}</p>
                    </div>
                </div>

                {{-- RIGHT: settings --}}
                <div class="tb-panel">
                    <div class="tb-panel__head">{{ translate('section_settings') }}</div>
                    <div class="tb-panel__body">
                        <div id="tb-settings">
                            <p class="text-muted small mb-0">{{ translate('select_a_section_to_edit_its_settings') }}</p>
                        </div>
                        <div id="tb-actions" class="d-none mt-3 d-flex flex-wrap gap-2">
                            <button type="button" id="tb-save" class="btn btn-sm btn-primary">{{ translate('save_draft') }}</button>
                            <button type="button" id="tb-toggle" class="btn btn-sm btn-outline-secondary">{{ translate('hide_show') }}</button>
                            <button type="button" id="tb-duplicate" class="btn btn-sm btn-outline-secondary">{{ translate('duplicate') }}</button>
                            <button type="button" id="tb-delete" class="btn btn-sm btn-outline-danger">{{ translate('delete') }}</button>
                        </div>
                    </div>
                </div>
            </div>
        @endif
    </div>
@endsection

@push('script')
    <script>
        "use strict";
        (function () {
            var root = document.querySelector('.tb-wrap');
            if (!root) return;

            var editable = root.dataset.editable === '1';
            var versionId = root.dataset.version;
            var page = root.dataset.page;
            var selectedId = null;
            var dirty = false;
            var csrf = document.querySelector('meta[name="csrf-token"]');

            function post(url, payload) {
                return fetch(url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': csrf ? csrf.getAttribute('content') : ''
                    },
                    body: JSON.stringify(payload)
                }).then(function (r) { return r.json().then(function (b) { return {ok: r.ok, body: b}; }); });
            }

            function notify(res) {
                if (!res.ok && res.body && res.body.message && window.toastMagic) {
                    toastMagic.error(res.body.message);
                }
                return res;
            }

            // ---- selection + settings form (rendered from the registry schema) ----
            function selectSection(el) {
                document.querySelectorAll('.tb-section-item').forEach(function (i) { i.setAttribute('aria-selected', 'false'); });
                el.setAttribute('aria-selected', 'true');
                selectedId = el.dataset.id;
                document.getElementById('tb-actions').classList.remove('d-none');

                fetch(root.dataset.urlSchema + '?type=' + encodeURIComponent(el.dataset.type), {
                    headers: {'X-Requested-With': 'XMLHttpRequest'}
                }).then(function (r) { return r.json(); }).then(function (data) {
                    renderSettings(data.schema || {});
                });
            }

            function renderSettings(schema) {
                var host = document.getElementById('tb-settings');
                host.innerHTML = '';
                Object.keys(schema).forEach(function (key) {
                    var field = schema[key];
                    var wrap = document.createElement('div');
                    wrap.className = 'tb-field';

                    var label = document.createElement('label');
                    label.textContent = field.label || key;
                    wrap.appendChild(label);

                    var input;
                    if (field.type === 'boolean') {
                        input = document.createElement('input');
                        input.type = 'checkbox';
                        input.className = 'form-check-input';
                        input.checked = !!field.default;
                    } else if (field.type === 'select' || field.type === 'source') {
                        input = document.createElement('select');
                        input.className = 'form-control form-control-sm';
                        (field.options || []).forEach(function (opt) {
                            var o = document.createElement('option');
                            o.value = opt; o.textContent = opt;
                            if (opt === field.default) o.selected = true;
                            input.appendChild(o);
                        });
                    } else if (field.type === 'textarea') {
                        input = document.createElement('textarea');
                        input.className = 'form-control form-control-sm';
                        input.rows = 3;
                        input.value = field.default || '';
                    } else {
                        input = document.createElement('input');
                        input.type = field.type === 'number' ? 'number' : (field.type === 'color' ? 'color' : 'text');
                        input.className = 'form-control form-control-sm';
                        if (field.default !== null && field.default !== undefined) input.value = field.default;
                    }
                    input.dataset.key = key;
                    input.disabled = !editable;
                    input.addEventListener('input', function () { dirty = true; });
                    input.addEventListener('change', function () { dirty = true; });
                    wrap.appendChild(input);
                    host.appendChild(wrap);
                });
            }

            function collectSettings() {
                var out = {};
                document.querySelectorAll('#tb-settings [data-key]').forEach(function (el) {
                    out[el.dataset.key] = el.type === 'checkbox' ? el.checked : el.value;
                });
                return out;
            }

            document.getElementById('tb-structure').addEventListener('click', function (e) {
                var item = e.target.closest('.tb-section-item');
                if (item) selectSection(item);
            });

            if (!editable) return; // read-only: selection/preview only

            // ---- drag & drop reordering (native HTML5 — no extra dependency) ----
            var dragged = null;
            var structure = document.getElementById('tb-structure');

            structure.addEventListener('dragstart', function (e) {
                var item = e.target.closest('.tb-section-item');
                if (!item) return;
                dragged = item;
                item.classList.add('dragging');
            });
            structure.addEventListener('dragend', function () {
                if (dragged) dragged.classList.remove('dragging');
                document.querySelectorAll('.tb-section-item').forEach(function (i) { i.classList.remove('drop-target'); });
                dragged = null;
            });
            structure.addEventListener('dragover', function (e) {
                e.preventDefault();
                var over = e.target.closest('.tb-section-item');
                if (!over || over === dragged) return;
                document.querySelectorAll('.tb-section-item').forEach(function (i) { i.classList.remove('drop-target'); });
                over.classList.add('drop-target');
            });
            structure.addEventListener('drop', function (e) {
                e.preventDefault();
                var target = e.target.closest('.tb-section-item');
                if (!dragged || !target || target === dragged) return;

                var items = Array.prototype.slice.call(structure.querySelectorAll('.tb-section-item'));
                var from = items.indexOf(dragged), to = items.indexOf(target);
                if (from < to) { target.after(dragged); } else { target.before(dragged); }

                var order = Array.prototype.map.call(structure.querySelectorAll('.tb-section-item'), function (i) { return i.dataset.id; });
                post(root.dataset.urlReorder, {version_id: versionId, page: page, order: order}).then(notify);
            });

            // ---- actions ----
            document.getElementById('tb-add').addEventListener('click', function () {
                var type = document.getElementById('tb-new-type').value;
                post(root.dataset.urlAdd, {version_id: versionId, page: page, type: type})
                    .then(notify).then(function (res) { if (res.ok) location.reload(); });
            });

            document.getElementById('tb-save').addEventListener('click', function () {
                if (!selectedId) return;
                post(root.dataset.urlUpdate, {section_id: selectedId, settings: collectSettings()})
                    .then(notify).then(function (res) {
                        if (res.ok) { dirty = false; if (window.toastMagic) toastMagic.success('{{ translate('draft_saved') }}'); }
                    });
            });

            document.getElementById('tb-toggle').addEventListener('click', function () {
                if (!selectedId) return;
                var item = structure.querySelector('.tb-section-item[data-id="' + selectedId + '"]');
                var currentlyHidden = item && item.querySelector('.tb-hidden-badge');
                post(root.dataset.urlToggle, {section_id: selectedId, visible: !!currentlyHidden})
                    .then(notify).then(function (res) { if (res.ok) location.reload(); });
            });

            document.getElementById('tb-duplicate').addEventListener('click', function () {
                if (!selectedId) return;
                post(root.dataset.urlDuplicate, {section_id: selectedId})
                    .then(notify).then(function (res) { if (res.ok) location.reload(); });
            });

            document.getElementById('tb-delete').addEventListener('click', function () {
                if (!selectedId) return;
                if (!confirm('{{ translate('are_you_sure') }}?')) return;
                post(root.dataset.urlDelete, {section_id: selectedId})
                    .then(notify).then(function (res) { if (res.ok) location.reload(); });
            });

            // ---- device preview ----
            document.querySelectorAll('[data-device]').forEach(function (btn) {
                if (btn.tagName !== 'BUTTON') return;
                btn.addEventListener('click', function () {
                    document.querySelectorAll('button[data-device]').forEach(function (b) { b.classList.remove('active'); });
                    btn.classList.add('active');
                    document.getElementById('tb-preview').dataset.device = btn.dataset.device;
                });
            });

            // ---- unsaved-changes warning ----
            window.addEventListener('beforeunload', function (e) {
                if (dirty) { e.preventDefault(); e.returnValue = ''; }
            });
        })();
    </script>
@endpush
