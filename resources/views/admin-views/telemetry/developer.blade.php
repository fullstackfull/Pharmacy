@extends('layouts.admin.app')

@section('title', translate('developer_portal'))

@section('content')
    <div class="content container-fluid k" id="dev-portal">
        <x-k.page-header :title="translate('developer_portal')"
                         :subtitle="translate('the_live_api_surface_of_this_store_generated_from_the_route_table_so_it_can_never_go_stale')">
            <x-slot:actions>
                <span class="k-badge k-badge--info">v{{ $version['version'] }}</span>
                <code class="k-text-subtle">{{ $baseUrl }}</code>
            </x-slot:actions>
        </x-k.page-header>

        <div class="row g-3" style="margin-block-end:16px">
            @foreach ($guides as $guide)
                <div class="col-lg-4 col-md-6">
                    <x-k.card :title="$guide['title']">
                        <p class="k-text-subtle">{{ $guide['body'] }}</p>
                        <div style="position:relative">
                            <pre class="k-dev-snippet"><code>{{ $guide['snippet'] }}</code></pre>
                            <button type="button" class="k-btn k-btn--ghost k-btn--sm k-btn--icon k-dev-copy"
                                    title="{{ translate('copy') }}" aria-label="{{ translate('copy') }}">
                                <x-k.icon name="copy" :size="14" />
                            </button>
                        </div>
                    </x-k.card>
                </div>
            @endforeach
        </div>

        <x-k.card :title="translate('api_reference')">
            <div class="k-row" style="gap:12px;margin-block-end:12px;flex-wrap:wrap">
                <div class="k-search" style="max-inline-size:340px">
                    <x-k.icon name="search" :size="15" />
                    <input type="search" class="k-input" id="dev-filter"
                           placeholder="{{ translate('filter_endpoints') }}" aria-label="{{ translate('filter_endpoints') }}">
                </div>
                <nav class="k-tabs" id="dev-version-tabs">
                    @foreach (array_keys($reference) as $index => $apiVersion)
                        <button type="button" class="k-tab" data-version="{{ $apiVersion }}"
                                aria-selected="{{ $index === 0 ? 'true' : 'false' }}">{{ $apiVersion }}</button>
                    @endforeach
                </nav>
                <span class="k-text-subtle">{{ translate('bearer_badge_means_the_call_needs_an_authorization_token') }}</span>
            </div>

            @foreach ($reference as $apiVersion => $resources)
                <div data-version-panel="{{ $apiVersion }}" @if (!$loop->first) hidden @endif>
                    @foreach ($resources as $resource => $routes)
                        <details class="k-dev-group" @if ($loop->parent->first && $loop->index < 3) open @endif>
                            <summary>
                                <span class="fw-semibold">{{ $resource }}</span>
                                <span class="k-tab__count k-num">{{ count($routes) }}</span>
                            </summary>
                            <div class="k-table-wrap">
                                <table class="k-table">
                                    <tbody>
                                    @foreach ($routes as $route)
                                        <tr data-endpoint="{{ strtolower($route['uri'] . ' ' . $route['name']) }}">
                                            <td style="inline-size:110px">
                                                <span class="k-badge {{ str_contains($route['methods'], 'POST') ? 'k-badge--warning' : 'k-badge--success' }}" >
                                                    {{ $route['methods'] }}
                                                </span>
                                            </td>
                                            <td><code>{{ $route['uri'] }}</code></td>
                                            <td style="inline-size:90px">
                                                @if ($route['auth'])
                                                    <x-k.badge tone="info">bearer</x-k.badge>
                                                @endif
                                            </td>
                                            <td class="k-table__num">
                                                <button type="button" class="k-btn k-btn--ghost k-btn--sm k-btn--icon k-dev-copy-uri"
                                                        data-uri="{{ $baseUrl . $route['uri'] }}"
                                                        title="{{ translate('copy_full_url') }}" aria-label="{{ translate('copy_full_url') }}">
                                                    <x-k.icon name="copy" :size="14" />
                                                </button>
                                            </td>
                                        </tr>
                                    @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </details>
                    @endforeach
                </div>
            @endforeach
        </x-k.card>
    </div>
@endsection

@push('script')
    <script>
        "use strict";
        (function () {
            var root = document.getElementById('dev-portal');

            root.querySelectorAll('#dev-version-tabs .k-tab').forEach(function (tab) {
                tab.addEventListener('click', function () {
                    root.querySelectorAll('#dev-version-tabs .k-tab').forEach(function (t) {
                        t.setAttribute('aria-selected', t === tab ? 'true' : 'false');
                    });
                    root.querySelectorAll('[data-version-panel]').forEach(function (panel) {
                        panel.hidden = panel.getAttribute('data-version-panel') !== tab.getAttribute('data-version');
                    });
                });
            });

            document.getElementById('dev-filter').addEventListener('input', function () {
                var query = this.value.trim().toLowerCase();
                root.querySelectorAll('[data-endpoint]').forEach(function (row) {
                    row.hidden = query !== '' && row.getAttribute('data-endpoint').indexOf(query) === -1;
                });
                // A group with every row hidden collapses out of the way.
                root.querySelectorAll('.k-dev-group').forEach(function (group) {
                    var any = Array.prototype.some.call(group.querySelectorAll('[data-endpoint]'), function (row) { return !row.hidden; });
                    group.style.display = any ? '' : 'none';
                    if (query !== '' && any) group.open = true;
                });
            });

            function copyText(text, button) {
                (navigator.clipboard ? navigator.clipboard.writeText(text) : Promise.reject())
                    .catch(function () {
                        var area = document.createElement('textarea');
                        area.value = text; document.body.appendChild(area);
                        area.select(); document.execCommand('copy'); area.remove();
                    })
                    .finally(function () {
                        button.classList.add('k-btn--primary');
                        setTimeout(function () { button.classList.remove('k-btn--primary'); }, 700);
                    });
            }
            root.addEventListener('click', function (event) {
                var snippetBtn = event.target.closest('.k-dev-copy');
                if (snippetBtn) copyText(snippetBtn.parentElement.querySelector('code').innerText, snippetBtn);
                var uriBtn = event.target.closest('.k-dev-copy-uri');
                if (uriBtn) copyText(uriBtn.getAttribute('data-uri'), uriBtn);
            });
        })();
    </script>
@endpush
