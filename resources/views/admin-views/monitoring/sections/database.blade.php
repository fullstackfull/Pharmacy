{{--
    Database: the server's own counters, the statements that are slow, and the routes that talk to
    it too often.

    The last table is the one that is easy to leave out and the only one that finds an N+1. A route
    issuing a couple of hundred perfectly fast queries has no slow query to show, so every
    per-statement view on this page calls it healthy. The slow-query ledger being empty and the
    query-heavy table being alarming is not a contradiction — it is the diagnosis.
--}}

@php
    $ms = static fn ($value) => $value === null ? '—' : number_format((float) $value, 1) . ' ms';
    $duration = static fn ($value) => $value === null
        ? '—'
        : ((float) $value >= 1000 ? number_format((float) $value / 1000, 1) . ' s' : number_format((float) $value) . ' ms');
    $count = static fn ($value) => $value === null ? '—' : number_format((float) $value, fmod((float) $value, 1) == 0 ? 0 : 1);

    // The shared chart renderer reads each point's `hits`, so a stored gauge is handed to it under
    // that key. Only the field name is adapted — the value is the sample as it was written.
    $asChart = static fn (array $chart) => [
        'points' => array_map(
            static fn (array $point) => ['t' => $point['t'], 'hits' => $point['v']],
            $chart['points'],
        ),
    ];

    $stateTitle = static fn (string $state) => match ($state) {
        'failed' => translate('this_could_not_be_read'),
        'not_configured' => translate('not_configured'),
        'permission_denied' => translate('permission_denied'),
        'not_supported' => translate('not_supported'),
        default => translate('no_data'),
    };
@endphp

{{-- Said once, at the top. A collector that could not reach the server produces forty identical
     unavailable rows underneath, and forty copies of one fact reads as forty faults. --}}
@if (($panel['server']['state'] ?? 'ok') !== 'ok')
    <div class="mon-attention">
        <div class="mon-attention__item mon-attention__item--{{ $panel['server']['state'] === 'failed' ? 'critical' : 'info' }}">
            <x-k.icon name="alert" :size="16" />
            <span class="mon-attention__body">
                <strong>{{ translate('the_database_collector_could_not_read_this_server') }}</strong>
                <small>{{ $panel['server']['note'] }}</small>
                @if (!empty($panel['server']['remedy']))
                    <code>{{ $panel['server']['remedy'] }}</code>
                @endif
            </span>
        </div>
    </div>
@endif

{{-- Two stored gauges. Round-trip time says whether the server is reachable quickly; queries per
     second says how much work it is being asked for. Read together they separate "the database is
     slow" from "the application is asking it for too much". --}}
@foreach ($panel['charts'] as $chartKey => $chart)
    <x-k.card :title="translate($chartKey === 'latency_ms' ? 'database_round_trip_over_time' : 'queries_per_second_over_time')">
        @if (($chart['state'] ?? '') === 'ok')
            <div class="mon-chart" data-mon-chart='@json($asChart($chart))'></div>
            <p class="mon-note">
                {{ translate('latest') }}: {{ $count($chart['latest']) }} {{ $chart['unit'] }} —
                <code>{{ $chart['metric'] }}</code>, {{ translate('window') }}:
                {{ $panel['window']['since'] }} → {{ $panel['window']['until'] }} ({{ $panel['window']['timezone'] }})
            </p>
        @else
            <x-k.empty icon="trend-up" :title="$stateTitle($chart['state'] ?? 'no_data')" :text="$chart['note'] ?? ''" />
            @if (!empty($chart['remedy']))
                <details class="mon-metric__remedy">
                    <summary>{{ translate('how_to_enable_this') }}</summary>
                    <code>{{ $chart['remedy'] }}</code>
                </details>
            @endif
        @endif
    </x-k.card>
@endforeach

{{-- The server's own counters, grouped the way somebody diagnoses a database. Each reading renders
     its own state, so a counter this login may not read can never be drawn as a zero. --}}
@foreach ($panel['groups'] as $group)
    <x-k.card :title="translate($group['key'])">
        <p class="mon-note" style="margin-block-start:0">{{ translate($group['why']) }}.</p>
        <div class="mon-grid">
            @foreach ($group['metrics'] as $name => $metric)
                @include('admin-views.monitoring.partials._metric', ['metric' => $metric, 'label' => translate($name)])
            @endforeach
        </div>
    </x-k.card>
@endforeach

{{-- Size on disk, biggest first. Row counts are InnoDB's own sampled estimate, which the note
     underneath says out loud rather than letting somebody reconcile it against an export. --}}
<x-k.card :title="translate('largest_tables')">
    @if (($panel['largest_tables']['state'] ?? '') === 'ok' && !empty($panel['largest_tables']['rows']))
        <div class="k-table-wrap">
            <table class="k-table k-table--compact">
                <thead>
                <tr>
                    <th>{{ translate('table') }}</th>
                    <th class="k-table__num">{{ translate('data') }}</th>
                    <th class="k-table__num">{{ translate('indexes') }}</th>
                    <th class="k-table__num">{{ translate('total') }}</th>
                    <th class="k-table__num">{{ translate('rows') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($panel['largest_tables']['rows'] as $table)
                    <tr>
                        <td>{{ $table['table'] }}</td>
                        <td class="k-table__num k-num">{{ $count($table['data_mb']) }} MB</td>
                        <td class="k-table__num k-num">{{ $count($table['index_mb']) }} MB</td>
                        <td class="k-table__num k-num">{{ $count($table['total_mb']) }} MB</td>
                        <td class="k-table__num k-num">~{{ number_format($table['rows']) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <p class="mon-note">{{ $panel['largest_tables']['note'] }} — {{ $panel['largest_tables']['source'] }}</p>
    @else
        <x-k.empty icon="reports" :title="$stateTitle($panel['largest_tables']['state'] ?? 'no_data')"
                   :text="$panel['largest_tables']['note'] ?? ''" />
        @if (!empty($panel['largest_tables']['remedy']))
            <details class="mon-metric__remedy">
                <summary>{{ translate('how_to_enable_this') }}</summary>
                <code>{{ $panel['largest_tables']['remedy'] }}</code>
            </details>
        @endif
    @endif
</x-k.card>

{{-- The server's per-statement summary. It covers every statement rather than only the slow ones,
     which is the one thing the application's own ledger cannot do — so where it is switched off,
     the instruction to switch it on is the content of this card. --}}
<x-k.card :title="translate('per_statement_summary_from_the_server')">
    @if (($panel['query_digests']['state'] ?? '') === 'ok' && !empty($panel['query_digests']['rows']))
        <div class="k-table-wrap">
            <table class="k-table k-table--compact">
                <thead>
                <tr>
                    <th>{{ translate('statement') }}</th>
                    <th class="k-table__num">{{ translate('calls') }}</th>
                    <th class="k-table__num">{{ translate('avg') }}</th>
                    <th class="k-table__num">{{ translate('rows_examined') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($panel['query_digests']['rows'] as $digest)
                    <tr>
                        <td>
                            <details class="mon-metric__remedy">
                                <summary>{{ \Illuminate\Support\Str::limit($digest['statement'], 90) }}</summary>
                                <code>{{ $digest['statement'] }}</code>
                            </details>
                        </td>
                        <td class="k-table__num k-num">{{ number_format($digest['calls']) }}</td>
                        <td class="k-table__num k-num">{{ $ms($digest['avg_ms']) }}</td>
                        <td class="k-table__num k-num">{{ number_format($digest['rows_examined']) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <p class="mon-note">{{ $panel['query_digests']['source'] }}</p>
    @else
        <x-k.empty icon="reports" :title="$stateTitle($panel['query_digests']['state'] ?? 'no_data')"
                   :text="$panel['query_digests']['note'] ?? ''" />
        @if (!empty($panel['query_digests']['remedy']))
            <details class="mon-metric__remedy">
                <summary>{{ translate('how_to_enable_this') }}</summary>
                <code>{{ $panel['query_digests']['remedy'] }}</code>
            </details>
        @endif
        <p class="mon-note">
            {{ translate('until_that_is_switched_on_the_slow_query_ledger_below_is_recorded_by_the_application_itself_and_covers_only_statements_over_its_own_threshold') }}
        </p>
    @endif
</x-k.card>

{{-- The application's own ledger, in the two orders that answer different questions. By total time
     is what to fix; by executions is what is being called in a loop. A statement can top one list
     and be missing from the other, which is why both are drawn. --}}
@php
    $slow = $panel['slow_queries'];
    $slowTables = [
        ['key' => 'by_total_ms', 'title' => 'slowest_statements_by_total_time', 'why' => 'executions_multiplied_by_duration', 'then' => 'the_statement_costing_the_database_the_most_wall_clock_time'],
        ['key' => 'by_executions', 'title' => 'most_executed_slow_statements', 'why' => 'the_same_ledger_ranked_by_how_often_each_statement_ran', 'then' => 'where_one_called_repeatedly_surfaces_even_though_no_single_run_is_expensive'],
    ];
@endphp

@if (($slow['state'] ?? '') === 'ok')
    @foreach ($slowTables as $slowTable)
        <x-k.card :title="translate($slowTable['title'])">
            <p class="mon-note" style="margin-block-start:0">{{ translate($slowTable['why']) }} — {{ translate($slowTable['then']) }}.</p>
            <div class="k-table-wrap">
                <table class="k-table k-table--compact">
                    <thead>
                    <tr>
                        <th>{{ translate('statement') }}</th>
                        <th>{{ translate('table') }}</th>
                        <th>{{ translate('route') }}</th>
                        <th class="k-table__num">{{ translate('executions') }}</th>
                        <th class="k-table__num">{{ translate('avg') }}</th>
                        <th class="k-table__num">{{ translate('max') }}</th>
                        <th class="k-table__num">{{ translate('total_time') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($slow[$slowTable['key']] as $row)
                        <tr>
                            <td>
                                <details class="mon-metric__remedy">
                                    <summary>{{ \Illuminate\Support\Str::limit($row['sql'], 90) }}</summary>
                                    <code>{{ $row['sql'] }}</code>
                                </details>
                            </td>
                            <td>{{ $row['table'] ?? '—' }}</td>
                            <td>
                                <span class="k-truncate" style="display:block;max-inline-size:220px"
                                      title="{{ $row['route'] ?? '' }}">{{ $row['route'] ?? '—' }}</span>
                            </td>
                            <td class="k-table__num k-num">{{ number_format($row['executions']) }}</td>
                            <td class="k-table__num k-num">{{ $ms($row['avg_ms']) }}</td>
                            <td class="k-table__num k-num">{{ $ms($row['max_ms']) }}</td>
                            <td class="k-table__num k-num">{{ $duration($row['total_ms']) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            @if ($loop->last)
                <p class="mon-note">
                    {{ translate('the_route_column_is_the_caller_recorded_against_the_statement_most_often') }}.
                    {{ translate('it_is_a_place_to_start_not_a_claim_that_nothing_else_runs_it') }}.
                </p>
            @endif
        </x-k.card>
    @endforeach
@else
    <x-k.card :title="translate('slow_statements')">
        <x-k.empty icon="reports" :title="$stateTitle($slow['state'] ?? 'no_data')" :text="$slow['note'] ?? ''" />
        @if (!empty($slow['remedy']))
            <details class="mon-metric__remedy">
                <summary>{{ translate('how_to_enable_this') }}</summary>
                <code>{{ $slow['remedy'] }}</code>
            </details>
        @endif
    </x-k.card>
@endif

{{-- The N+1 detector. An empty ledger above and a loud table here is the shape of a route loading
     rows in a loop: every individual statement is fast, and there are hundreds of them. --}}
@php
    $heavy = $panel['query_heavy_routes'];
    $heavyTables = [
        ['key' => 'by_db_time', 'title' => 'routes_spending_the_most_database_time', 'why' => 'total_database_time_in_this_window', 'then' => 'where_the_server_is_actually_being_spent_rather_than_where_it_is_slowest'],
        ['key' => 'by_queries_per_request', 'title' => 'routes_issuing_the_most_queries_per_request', 'why' => 'statements_divided_by_requests_so_a_busy_page_cannot_reach_the_top_simply_by_being_busy', 'then' => 'this_is_the_ranking_that_finds_an_n_plus_one'],
    ];
@endphp

@if (($heavy['state'] ?? '') === 'ok')
    @foreach ($heavyTables as $heavyTable)
        <x-k.card :title="translate($heavyTable['title'])">
            <p class="mon-note" style="margin-block-start:0">{{ translate($heavyTable['why']) }} — {{ translate($heavyTable['then']) }}.</p>
            <div class="k-table-wrap">
                <table class="k-table k-table--compact">
                    <thead>
                    <tr>
                        <th>{{ translate('route') }}</th>
                        <th>{{ translate('method') }}</th>
                        <th>{{ translate('channel') }}</th>
                        <th class="k-table__num">{{ translate('count') }}</th>
                        <th class="k-table__num">{{ translate('queries_per_request') }}</th>
                        <th class="k-table__num">{{ translate('db_ms') }}</th>
                        <th class="k-table__num">{{ translate('per_query') }}</th>
                        <th class="k-table__num">{{ translate('total_time') }}</th>
                        <th class="k-table__num">{{ translate('share_of_response') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($heavy[$heavyTable['key']] as $row)
                        <tr>
                            <td>
                                <span class="k-truncate" style="display:block;max-inline-size:260px"
                                      title="{{ $row['channel'] }} {{ $row['method'] }} {{ $row['route'] }}">{{ $row['route'] }}</span>
                            </td>
                            <td>{{ $row['method'] }}</td>
                            <td>{{ translate($row['channel']) }}</td>
                            <td class="k-table__num k-num">{{ number_format($row['hits']) }}</td>
                            <td class="k-table__num k-num">
                                @if ($row['suspect'])
                                    <span class="mon-pill mon-pill--warning">{{ $count($row['queries_per_request']) }}</span>
                                @else
                                    {{ $count($row['queries_per_request']) }}
                                @endif
                            </td>
                            <td class="k-table__num k-num">{{ $ms($row['db_ms_per_request']) }}</td>
                            <td class="k-table__num k-num">{{ $ms($row['ms_per_query']) }}</td>
                            <td class="k-table__num k-num">{{ $duration($row['db_ms_total']) }}</td>
                            <td class="k-table__num k-num">
                                {{ $row['db_share_pct'] === null ? '—' : $row['db_share_pct'] . '%' }}
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            @if ($loop->last)
                <p class="mon-note">
                    {{ translate('a_route_marked_amber_is_issuing_more_than') }}
                    {{ $panel['queries_per_request_suspect'] }}
                    {{ translate('statements_for_a_single_request_which_is_the_shape_of_rows_being_loaded_in_a_loop') }}.
                    {{ translate('that_line_is_a_reading_aid_not_a_measurement') }}.
                </p>
            @endif
        </x-k.card>
    @endforeach
@else
    <x-k.card :title="translate('routes_spending_the_most_database_time')">
        <x-k.empty icon="reports" :title="$stateTitle($heavy['state'] ?? 'no_data')" :text="$heavy['note'] ?? ''" />
        @if (!empty($heavy['remedy']))
            <details class="mon-metric__remedy">
                <summary>{{ translate('how_to_enable_this') }}</summary>
                <code>{{ $heavy['remedy'] }}</code>
            </details>
        @endif
    </x-k.card>
@endif

<p class="mon-note">
    {{ translate('server_counters_are_read_live_from') }} <code>SHOW GLOBAL STATUS</code>,
    <code>SHOW GLOBAL VARIABLES</code> {{ translate('and') }} <code>information_schema</code>;
    {{ translate('slow_statements_from') }} <code>monitoring_slow_queries</code>
    ({{ translate('recorded_per_hour_covering_from') }} {{ $panel['slow_queries']['covers_from'] }}),
    {{ translate('and_the_route_tables_from') }} <code>monitoring_request_buckets</code>
    {{ translate('for_the_selected_window') }}.
</p>
