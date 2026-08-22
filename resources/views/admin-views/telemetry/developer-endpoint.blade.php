@extends('layouts.admin.app')

@section('title', implode('|', $endpoint['methods']) . ' ' . $endpoint['path'])

@push('css_or_js')
    <link rel="stylesheet" href="{{ dynamicAsset(path: 'public/assets/kohl/css/developer.css') }}">
@endpush

@section('content')
    {{-- Everything about one endpoint on one page, because the alternative is a developer holding
         four tabs open to answer a single question. Reference, examples, live health, who still
         calls it and what has changed about it — in that order, because that is the order the
         questions arrive in. --}}
    <div class="content container-fluid k dev dev--endpoint">
        <a class="dev-back" href="{{ route('admin.developer.section', ['section' => 'explorer']) }}">
            ← {{ translate('back_to_the_api_explorer') }}
        </a>

        <header class="dev-endpoint-head">
            <div class="dev-endpoint-head__signature">
                @foreach ($endpoint['methods'] as $method)
                    <span class="dev-method dev-method--{{ strtolower($method) }}">{{ $method }}</span>
                @endforeach
                <code class="dev-endpoint-head__path" data-copy="{{ $endpoint['full_url'] }}">{{ $endpoint['path'] }}</code>
            </div>

            <p class="dev-endpoint-head__summary">{{ $endpoint['summary'] }}</p>
            @if ($endpoint['description'])
                <p class="dev-note">{{ $endpoint['description'] }}</p>
            @endif

            <div class="dev-endpoint-head__tags">
                <x-k.badge tone="info">{{ translate($endpoint['audience']) }}</x-k.badge>
                @if ($endpoint['version'])<x-k.badge tone="neutral">{{ $endpoint['version'] }}</x-k.badge>@endif
                <x-k.badge tone="neutral">{{ translate($endpoint['group']) }}</x-k.badge>
                <x-k.badge :tone="$endpoint['deprecated'] ? 'warning' : 'success'">{{ translate($endpoint['stability']) }}</x-k.badge>
                <x-k.badge tone="neutral">{{ translate($endpoint['visibility']) }}</x-k.badge>
                @if ($endpoint['idempotent'])
                    <x-k.badge tone="success" title="{{ translate('safe_to_retry_with_the_same_input') }}">{{ translate('idempotent') }}</x-k.badge>
                @endif
                @if ($endpoint['destructive'])
                    <x-k.badge tone="danger" title="{{ translate('this_changes_or_removes_data') }}">{{ translate('destructive') }}</x-k.badge>
                @endif
            </div>
        </header>

        @if ($endpoint['deprecated'])
            <div class="dev-callout dev-callout--warn">
                <strong>{{ translate('deprecated') }}@if ($endpoint['deprecated_since']) — {{ translate('since') }} {{ $endpoint['deprecated_since'] }}@endif</strong>
                @if ($endpoint['replaced_by'])
                    <p>{{ translate('use') }} <code>{{ $endpoint['replaced_by'] }}</code> {{ translate('instead') }}.</p>
                @endif
                @if ($endpoint['sunset_at'])
                    <p>{{ translate('scheduled_for_removal_on') }} {{ $endpoint['sunset_at'] }}.</p>
                @endif
                <p>{{ $endpoint['removal']['message'] }}</p>
            </div>
        @endif

        <div class="dev-grid dev-grid--2">
            <x-k.card :title="translate('authentication')">
                <p><strong>{{ translate($endpoint['auth']['mechanism']) }}</strong></p>
                @if ($endpoint['auth']['header'])
                    <pre class="dev-code"><code>{{ $endpoint['auth']['header'] }}</code></pre>
                @endif
                <p class="dev-note">{{ $endpoint['auth']['note'] }}</p>

                @if ($endpoint['permissions'] !== [])
                    <p class="dev-note">
                        {{ translate('also_requires') }}:
                        @foreach ($endpoint['permissions'] as $permission)<code>{{ $permission }}</code> @endforeach
                    </p>
                @endif
            </x-k.card>

            <x-k.card :title="translate('limits_and_identity')">
                <ul class="dev-list">
                    <li>
                        <span>{{ translate('rate_limit') }}</span>
                        <strong>
                            @if (($endpoint['rate_limit']['requests'] ?? null) !== null)
                                {{ $endpoint['rate_limit']['requests'] }} / {{ $endpoint['rate_limit']['minutes'] }} {{ translate('minute_s') }}
                            @else
                                {{ translate('only_the_api_group_limit') }}
                            @endif
                        </strong>
                    </li>
                    <li><span>{{ translate('route_name') }}</span><strong>{{ $endpoint['name'] ?: '—' }}</strong></li>
                    <li><span>{{ translate('since') }}</span><strong>{{ $endpoint['since'] ?: '—' }}</strong></li>
                </ul>
                {{-- Internal detail: which class serves this. Useful to the team, meaningless and
                     slightly revealing to an outside integrator, so it sits under a summary. --}}
                <details class="dev-details">
                    <summary>{{ translate('implementation') }}</summary>
                    <p><code>{{ class_basename($endpoint['controller'] ?? '') }}@{{ $endpoint['action'] }}</code></p>
                    <p class="dev-muted">{{ implode(' · ', $endpoint['middleware']) }}</p>
                </details>
            </x-k.card>
        </div>

        @if ($endpoint['path_parameters'] !== [] || $endpoint['body'] !== [])
            <x-k.card :title="translate('request')">
                @if ($endpoint['path_parameters'] !== [])
                    <h4 class="dev-subhead">{{ translate('path_parameters') }}</h4>
                    <table class="dev-table">
                        <thead><tr><th>{{ translate('name') }}</th><th>{{ translate('type') }}</th><th>{{ translate('required') }}</th></tr></thead>
                        <tbody>
                        @foreach ($endpoint['path_parameters'] as $parameter)
                            <tr>
                                <td><code>{{ $parameter['name'] }}</code></td>
                                <td>{{ $parameter['type'] ?? 'string' }}</td>
                                <td>{{ ($parameter['required'] ?? true) ? translate('yes') : translate('no') }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                @endif

                @if ($endpoint['body'] !== [])
                    <h4 class="dev-subhead">
                        {{ in_array('GET', $endpoint['methods'], true) ? translate('query_parameters') : translate('body') }}
                        {{-- Where these rules came from, stated plainly: recovered from a
                             FormRequest is a stronger guarantee than read out of a controller. --}}
                        <small class="dev-muted">
                            @if ($endpoint['body_source'] === 'form_request')
                                {{ translate('from') }} <code>{{ class_basename($endpoint['request_class']) }}</code>
                            @else
                                {{ translate('read_from_the_controllers_own_validation') }}
                            @endif
                        </small>
                    </h4>
                    <table class="dev-table">
                        <thead><tr>
                            <th>{{ translate('name') }}</th><th>{{ translate('type') }}</th>
                            <th>{{ translate('required') }}</th><th>{{ translate('rules') }}</th>
                        </tr></thead>
                        <tbody>
                        @foreach ($endpoint['body'] as $field)
                            <tr>
                                <td><code>{{ $field['name'] }}</code></td>
                                <td>{{ $field['type'] }}{{ isset($field['format']) ? ' (' . $field['format'] . ')' : '' }}</td>
                                <td>{{ !empty($field['required']) ? translate('yes') : translate('no') }}</td>
                                <td class="dev-muted">{{ implode('; ', $field['constraints'] ?? []) ?: '—' }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                @endif
            </x-k.card>
        @elseif (array_intersect(['POST', 'PUT', 'PATCH'], $endpoint['methods']) !== [])
            <x-k.card :title="translate('request')">
                <x-k.empty
                    :title="translate('no_request_schema_could_be_recovered')"
                    :text="translate('this_endpoint_writes_but_its_validation_is_not_written_as_a_literal_rule_array_so_nothing_can_be_extracted_add_a_formrequest_or_an_apidoc_attribute_to_document_it')" />
            </x-k.card>
        @endif

        <x-k.card :title="translate('code')">
            <div class="dev-tabs" data-dev-tabs>
                @foreach ($endpoint['examples'] as $key => $example)
                    <button type="button" class="dev-tabs__tab {{ $loop->first ? 'is-active' : '' }}" data-tab="{{ $key }}">
                        {{ $example['label'] }}
                    </button>
                @endforeach
            </div>
            @foreach ($endpoint['examples'] as $key => $example)
                <div class="dev-tabs__panel {{ $loop->first ? 'is-active' : '' }}" data-panel="{{ $key }}">
                    <button type="button" class="dev-copy" data-copy-target="code-{{ $key }}">{{ translate('copy') }}</button>
                    <pre class="dev-code"><code id="code-{{ $key }}">{{ $example['code'] }}</code></pre>
                </div>
            @endforeach
        </x-k.card>

        <div class="dev-grid dev-grid--2">
            <x-k.card :title="translate('live_usage')">
                @if ($endpoint['health']['measured'] ?? false)
                    <div class="dev-metrics">
                        <div><span class="k-num">{{ number_format($endpoint['health']['hits']) }}</span><small>{{ translate('requests') }} · {{ $endpoint['health']['range'] }}</small></div>
                        <div><span class="k-num">{{ $endpoint['health']['error_rate'] }}%</span><small>{{ translate('errors') }}</small></div>
                        <div><span class="k-num">{{ $endpoint['health']['p95'] ?? '—' }}<i>ms</i></span><small>p95</small></div>
                        <div><span class="k-num">{{ $endpoint['health']['avg'] ?? '—' }}<i>ms</i></span><small>{{ translate('average') }}</small></div>
                    </div>
                    @if ($endpoint['health']['last_error'] ?? null)
                        <p class="dev-warn">
                            {{ translate('last_error') }}: <code>{{ $endpoint['health']['last_error']['type'] }}</code>
                            — {{ $endpoint['health']['last_error']['message'] }}
                        </p>
                    @endif
                @else
                    <x-k.empty
                        :title="translate('no_traffic_recorded')"
                        :text="$endpoint['health']['reason']['note'] ?? null ?? translate('nothing_has_called_this_endpoint_in_the_measured_window')" />
                @endif
            </x-k.card>

            <x-k.card :title="translate('can_this_be_removed')">
                <p class="{{ ($endpoint['removal']['safe'] ?? null) === false ? 'dev-warn' : 'dev-note' }}">
                    {{ $endpoint['removal']['message'] }}
                </p>

                @if ($endpoint['callers']['measured'] ?? false)
                    <h4 class="dev-subhead">{{ translate('calling_app_versions') }}</h4>
                    <ul class="dev-list">
                        @foreach ($endpoint['callers']['versions'] as $caller)
                            <li><span>{{ $caller['version'] }}</span><strong>{{ $caller['share'] }}%</strong></li>
                        @endforeach
                    </ul>
                @else
                    <p class="dev-muted">{{ $endpoint['callers']['reason']['remedy'] ?? null ?? translate('app_versions_are_not_being_recorded') }}</p>
                @endif
            </x-k.card>
        </div>

        @if ($endpoint['changes'] !== [] || $endpoint['related'] !== [])
            <div class="dev-grid dev-grid--2">
                @if ($endpoint['changes'] !== [])
                    <x-k.card :title="translate('history')">
                        @foreach ($endpoint['changes'] as $change)
                            <div class="dev-change dev-change--{{ $change->severity }}">
                                <span class="dev-change__badge">{{ translate($change->change_type) }}</span>
                                <div>
                                    <small>{{ $change->detail }}</small>
                                    <span class="dev-muted">{{ \Carbon\Carbon::parse($change->detected_at)->diffForHumans() }}</span>
                                </div>
                            </div>
                        @endforeach
                    </x-k.card>
                @endif

                @if ($endpoint['related'] !== [])
                    <x-k.card :title="translate('related_endpoints')">
                        @foreach ($endpoint['related'] as $related)
                            <a class="dev-related" href="{{ route('admin.developer.endpoint', ['id' => $related['id']]) }}">
                                <span class="dev-method dev-method--{{ strtolower($related['methods'][0] ?? 'get') }}">{{ $related['methods'][0] ?? 'GET' }}</span>
                                <code>{{ $related['path'] }}</code>
                            </a>
                        @endforeach
                    </x-k.card>
                @endif
            </div>
        @endif
    </div>
@endsection

@push('script')
<script>
    // Tabs and copy buttons. Deliberately no framework: this page is a reference a developer
    // reads, and it should render instantly on a slow connection in a hotel.
    document.querySelectorAll('[data-dev-tabs]').forEach(function (tabs) {
        tabs.addEventListener('click', function (event) {
            var tab = event.target.closest('[data-tab]');
            if (!tab) return;
            var root = tabs.parentElement;
            root.querySelectorAll('[data-tab]').forEach(function (item) { item.classList.remove('is-active'); });
            root.querySelectorAll('[data-panel]').forEach(function (panel) { panel.classList.remove('is-active'); });
            tab.classList.add('is-active');
            var panel = root.querySelector('[data-panel="' + tab.dataset.tab + '"]');
            if (panel) panel.classList.add('is-active');
        });
    });

    document.querySelectorAll('[data-copy-target]').forEach(function (button) {
        button.addEventListener('click', function () {
            var source = document.getElementById(button.dataset.copyTarget);
            if (!source) return;
            navigator.clipboard.writeText(source.textContent).then(function () {
                var original = button.textContent;
                button.textContent = '✓';
                setTimeout(function () { button.textContent = original; }, 1200);
            });
        });
    });
</script>
@endpush
