{{--
    Requests: which route to open first.

    Four tables of the same window in four orders, because "the worst route" has four answers and
    three of them mislead. Nothing here is sortable on purpose — the pre-sorted tables ARE the
    feature, and each carries the sentence saying why its order is the one worth reading.
--}}

@php
    $summary = $panel['summary'] ?? [];
    $current = $summary['current'] ?? [];
    $delta = $summary['delta'] ?? [];
    $measured = (bool) ($current['has_data'] ?? false);
    $collection = $panel['collection'] ?? [];

    // A caption is only honest where a delta exists. "vs previous window" printed beside no arrow
    // reads as "unchanged", which is a claim there is no baseline to support.
    $caption = static fn (string $field) => ($delta[$field] ?? null) === null ? null : translate('vs_previous_window');

    // Every percentile is present on a row that has data at all, so the dash is unreachable rather
    // than a stand-in for a reading we failed to take.
    $ms = static fn ($value) => $value === null ? '—' : number_format((float) $value, 1) . ' ms';
    $duration = static fn ($value) => $value === null
        ? '—'
        : ((float) $value >= 1000 ? number_format((float) $value / 1000, 1) . ' s' : number_format((float) $value) . ' ms');

    $stateTitle = static fn (string $state) => match ($state) {
        'failed' => translate('this_could_not_be_read'),
        'not_configured' => translate('monitoring_collection_is_switched_off'),
        default => translate('no_requests_recorded_in_this_window'),
    };
@endphp

{{-- Said before anything else: a stopped collector and a quiet hour draw the same flat line, and
     only one of them is a problem. --}}
@if (($collection['state'] ?? 'ok') !== 'ok')
    <div class="mon-attention">
        <div class="mon-attention__item mon-attention__item--{{ ($collection['state'] ?? '') === 'stale' ? 'warning' : 'critical' }}">
            <x-k.icon name="alert" :size="16" />
            <span class="mon-attention__body">
                <strong>{{ ($collection['state'] ?? '') === 'stale' ? translate('request_collection_has_fallen_behind') : translate('requests_are_not_being_recorded') }}</strong>
                <small>{{ $collection['note'] }}</small>
                @if (!empty($collection['remedy']))
                    <code>{{ $collection['remedy'] }}</code>
                @endif
            </span>
        </div>
    </div>
@endif

{{-- The window's headline figures, each against the same window before it. --}}
<div class="k-stats mon-stats">
    <x-k.stat :label="translate('requests')"
              :value="$measured ? number_format($current['hits']) : translate('no_data')"
              icon="trend-up" :delta="$delta['hits'] ?? null" :caption="$caption('hits')" />
    <x-k.stat :label="translate('requests_per_second')"
              :value="$measured ? $current['requests_per_second'] : translate('no_data')"
              icon="trend-up" :delta="$delta['requests_per_second'] ?? null" :caption="$caption('requests_per_second')" />
    <x-k.stat :label="translate('error_rate')"
              :value="$measured ? $current['error_rate'] . '%' : translate('no_data')"
              icon="alert" :delta="$delta['error_rate'] ?? null" :caption="$caption('error_rate')" />
    <x-k.stat :label="translate('average')"
              :value="$measured ? $ms($current['avg']) : translate('no_data')"
              icon="clock" :delta="$delta['avg'] ?? null" :caption="$caption('avg')" />
    <x-k.stat :label="translate('response_time') . ' p50'"
              :value="$measured ? $ms($current['p50']) : translate('no_data')"
              icon="clock" />
    <x-k.stat :label="translate('response_time') . ' p95'"
              :value="$measured ? $ms($current['p95']) : translate('no_data')"
              icon="clock" :delta="$delta['p95'] ?? null" :caption="$caption('p95')" />
    <x-k.stat :label="translate('response_time') . ' p99'"
              :value="$measured ? $ms($current['p99']) : translate('no_data')"
              icon="clock" :delta="$delta['p99'] ?? null" :caption="$caption('p99')" />
    <x-k.stat :label="translate('database_time_per_request')"
              :value="$measured ? $ms($current['db_ms_avg']) : translate('no_data')"
              icon="reports" :delta="$delta['db_ms_avg'] ?? null" :caption="$caption('db_ms_avg')" />
</div>

@if (!$measured)
    <p class="mon-note {{ ($summary['state'] ?? '') === 'failed' ? 'mon-note--critical' : '' }}">
        {{ $summary['note'] ?? '' }}
        @if (!empty($summary['remedy'])) <code>{{ $summary['remedy'] }}</code> @endif
    </p>
@endif

<x-k.card :title="translate('requests_over_time')">
    @if (!empty($panel['timeline']['points']))
        {{-- Inline SVG rather than a charting library: the series is already aggregated
             server-side, so there is nothing for a library to do that costs less than loading it.
             Only the points travel into the attribute — the state and its remedy are prose for the
             page, not for the chart. --}}
        <div class="mon-chart"
             data-mon-chart='@json(['points' => $panel['timeline']['points'], 'resolution' => $panel['timeline']['resolution'] ?? null])'></div>
        <p class="mon-note">
            {{ translate('the_red_line_is_5xx_responses') }} —
            {{ translate('window') }}: {{ $panel['window']['since'] }} → {{ $panel['window']['until'] }} ({{ $panel['window']['timezone'] }}),
            {{ translate('resolution') }}: {{ translate($panel['window']['resolution']) }}
        </p>
    @else
        <x-k.empty icon="trend-up" :title="$stateTitle($panel['timeline']['state'] ?? 'no_data')"
                   :text="$panel['timeline']['note'] ?? ''" />
        @if (!empty($panel['timeline']['remedy']))
            <details class="mon-metric__remedy">
                <summary>{{ translate('how_to_enable_this') }}</summary>
                <code>{{ $panel['timeline']['remedy'] }}</code>
            </details>
        @endif
    @endif
</x-k.card>

{{-- The figures the headline cards have no room for. Each renders its own state, so a reading
     that was never taken can never be drawn as a zero. --}}
<x-k.card :title="translate('what_this_window_contains')">
    <div class="mon-grid">
        @foreach ($panel['readings'] as $readingKey => $reading)
            @include('admin-views.monitoring.partials._metric', ['metric' => $reading, 'label' => translate($readingKey)])
        @endforeach
    </div>
</x-k.card>

{{-- Where the traffic came in. The same p95 means different things on each: half a second on an
     admin report is nobody's emergency, half a second on the API is every mobile screen. --}}
<x-k.card :title="translate('traffic_by_channel')">
    <div class="k-table-wrap">
        <table class="k-table k-table--compact">
            <thead>
            <tr>
                <th>{{ translate('channel') }}</th>
                <th class="k-table__num">{{ translate('count') }}</th>
                <th class="k-table__num">{{ translate('share') }}</th>
                <th class="k-table__num">{{ translate('avg') }}</th>
                <th class="k-table__num">p50</th>
                <th class="k-table__num">p95</th>
                <th class="k-table__num">p99</th>
                <th class="k-table__num">{{ translate('errors') }}</th>
                <th class="k-table__num">{{ translate('error_rate') }}</th>
                <th class="k-table__num">{{ translate('db_ms') }}</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($panel['channels']['rows'] as $channel)
                @php($reading = $channel['summary'] ?? [])
                <tr class="{{ ($channel['state'] ?? '') === 'ok' ? '' : 'mon-row--muted' }}">
                    <td>{{ translate($channel['channel']) }}</td>
                    @if (($channel['state'] ?? '') === 'ok')
                        <td class="k-table__num k-num">{{ number_format($reading['hits']) }}</td>
                        <td class="k-table__num k-num">{{ $channel['share'] === null ? '—' : $channel['share'] . '%' }}</td>
                        <td class="k-table__num k-num">{{ $ms($reading['avg']) }}</td>
                        <td class="k-table__num k-num">{{ $ms($reading['p50']) }}</td>
                        <td class="k-table__num k-num">{{ $ms($reading['p95']) }}</td>
                        <td class="k-table__num k-num">{{ $ms($reading['p99']) }}</td>
                        <td class="k-table__num k-num">{{ number_format($reading['errors']) }}</td>
                        <td class="k-table__num k-num">
                            @if (($channel['severity'] ?? 'ok') === 'ok')
                                {{ $reading['error_rate'] }}%
                            @else
                                <span class="mon-pill mon-pill--{{ $channel['severity'] }}">{{ $reading['error_rate'] }}%</span>
                            @endif
                        </td>
                        <td class="k-table__num k-num">{{ $ms($reading['db_ms_avg']) }}</td>
                    @else
                        {{-- A channel with nothing says so in its own row. Nine zeroes would read as
                             a measurement, and it is not one. --}}
                        <td colspan="9">
                            <span class="mon-metric__state">{{ translate($channel['state'] ?? 'no_data') }}</span>
                            <span class="mon-metric__note">{{ $channel['note'] ?? '' }}</span>
                        </td>
                    @endif
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</x-k.card>

{{-- The four rankings. The first one is the answer to "what should I fix"; the other three are
     the questions people ask instead. --}}
@foreach ($panel['breakdowns'] as $breakdown)
    <x-k.card :title="translate($breakdown['title'])">
        <p class="mon-note" style="margin-block-start:0">{{ translate($breakdown['why']) }}</p>

        @if (($breakdown['state'] ?? '') === 'ok')
            <div class="k-table-wrap">
                <table class="k-table k-table--compact">
                    <thead>
                    <tr>
                        <th>{{ translate('route') }}</th>
                        <th>{{ translate('method') }}</th>
                        <th class="k-table__num">{{ translate('count') }}</th>
                        <th class="k-table__num">{{ translate('avg') }}</th>
                        <th class="k-table__num">p50</th>
                        <th class="k-table__num">p95</th>
                        <th class="k-table__num">p99</th>
                        <th class="k-table__num">{{ translate('errors') }}</th>
                        <th class="k-table__num">{{ translate('error_rate') }}</th>
                        <th class="k-table__num">{{ translate('db_ms') }}</th>
                        <th class="k-table__num">{{ translate('total_time') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($breakdown['rows'] as $row)
                        <tr>
                            <td>
                                <span class="k-truncate" style="display:block;max-inline-size:260px"
                                      title="{{ $row['channel'] }} {{ $row['method'] }} {{ $row['route'] }}">{{ $row['route'] }}</span>
                            </td>
                            <td>{{ $row['method'] }}</td>
                            <td class="k-table__num k-num">{{ number_format($row['hits']) }}</td>
                            <td class="k-table__num k-num">{{ $ms($row['avg']) }}</td>
                            <td class="k-table__num k-num">{{ $ms($row['p50']) }}</td>
                            <td class="k-table__num k-num">{{ $ms($row['p95']) }}</td>
                            <td class="k-table__num k-num">{{ $ms($row['p99']) }}</td>
                            <td class="k-table__num k-num">{{ number_format($row['errors']) }}</td>
                            <td class="k-table__num k-num">
                                @if (($row['severity'] ?? 'ok') === 'ok')
                                    {{ $row['error_rate'] }}%
                                @else
                                    <span class="mon-pill mon-pill--{{ $row['severity'] }}">{{ $row['error_rate'] }}%</span>
                                @endif
                            </td>
                            <td class="k-table__num k-num">{{ $ms($row['db_ms_avg']) }}</td>
                            <td class="k-table__num k-num">{{ $duration($row['total_time_ms']) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <x-k.empty icon="reports" :title="$stateTitle($breakdown['state'] ?? 'no_data')"
                       :text="$breakdown['note'] ?? ''" />
            @if (!empty($breakdown['remedy']))
                <details class="mon-metric__remedy">
                    <summary>{{ translate('how_to_enable_this') }}</summary>
                    <code>{{ $breakdown['remedy'] }}</code>
                </details>
            @endif
        @endif
    </x-k.card>
@endforeach

<p class="mon-note">
    {{ translate('every_figure_on_this_page_is_read_from') }} <code>monitoring_request_buckets</code>,
    {{ translate('folded_per_minute_per_route_percentiles_are_interpolated_from_the_stored_latency_histogram_not_from_sampled_requests') }}
</p>
