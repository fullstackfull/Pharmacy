{{--
    Scheduler: whether cron is firing at all, and what each task did the last time it ran.

    The cron verdict comes first and takes the full width, because when it is negative every row
    underneath is describing a machine that is not running. Nothing in the schedule executes:
    settlements never mature, abandoned-cart reminders never send, and every rollup this dashboard
    reads stops being built — silently, while the shop keeps taking orders and looks healthy. That
    is the one sentence somebody has to read before any table.

    The two red states are drawn differently on purpose. A failed task ran and broke; a missed one
    never ran at all. They have different causes and different fixes, so they must not share a pill.
--}}

@php
    $window = $panel['window'];
    $cron = $panel['cron'];
    $tasks = $panel['tasks'];
    $history = $panel['history'];
    $statistics = $panel['statistics'];

    $duration = static fn ($milliseconds) => $milliseconds === null
        ? '—'
        : ((int) $milliseconds >= 1000
            ? number_format((int) $milliseconds / 1000, 1) . ' s'
            : number_format((int) $milliseconds) . ' ms');

    // Minutes are exact and unreadable past an hour. The stored figure is never rounded away —
    // it is the title on the cell, so the precise number is always one hover from the summary.
    $elapsed = static function (?int $minutes) {
        if ($minutes === null) {
            return null;
        }
        if ($minutes < 60) {
            return $minutes . ' ' . translate('min');
        }
        if ($minutes < 1440) {
            return intdiv($minutes, 60) . ' ' . translate('hr');
        }

        return intdiv($minutes, 1440) . ' ' . translate('days');
    };

    $statusPill = static fn (?string $status) => match ($status) {
        'healthy', 'success' => 'mon-pill--healthy',
        'running' => 'mon-pill--running',
        'late' => 'mon-pill--warning',
        'missed' => 'mon-pill--missed',
        'failed' => 'mon-pill--critical',
        default => 'mon-pill--unknown',
    };

    $stateTitle = static fn (string $state) => match ($state) {
        'failed' => translate('this_could_not_be_read'),
        'not_configured' => translate('not_configured'),
        'not_supported' => translate('not_supported'),
        'permission_denied' => translate('permission_denied'),
        'collector_offline' => translate('collector_offline'),
        default => translate('no_data'),
    };

    // What each pill claims, stated once under the table. Six states that all mean "not fine" in
    // different ways are worse than none unless the page says which is which.
    $statusMeanings = [
        'healthy' => 'the_task_has_run_since_the_last_time_its_schedule_said_it_should',
        'running' => 'the_task_started_and_has_not_reported_finishing_yet',
        'late' => 'it_was_due_and_has_not_run_yet_but_is_still_within_the_grace_period',
        'missed' => 'it_was_due_well_past_the_grace_period_and_never_ran',
        'failed' => 'it_ran_and_ended_with_an_error',
        'unknown' => 'no_run_has_ever_been_recorded_for_this_task_which_is_not_the_same_as_healthy',
    ];

    $stoppedTasksShown = 12;
@endphp

{{-- First, largest, and before any table: the fact that decides whether the rest of this page
     describes a working machine. --}}
@if ($cron['running'] === false)
    <div class="mon-attention">
        <div class="mon-attention__item mon-attention__item--critical">
            <x-k.icon name="alert" :size="16" />
            <span class="mon-attention__body">
                <strong>{{ translate('cron_is_not_running_so_not_one_scheduled_task_is_executing') }}</strong>
                <small>{{ $cron['note'] }}</small>
                <small>{{ translate('nothing_raises_an_error_while_this_is_true_settlements_never_mature_reminders_are_never_sent_and_every_rollup_this_dashboard_reads_stops_being_built') }}</small>
                @if ($cron['last_run_at'])
                    <small>
                        {{ translate('last_recorded_run') }}: {{ $cron['last_run_at'] }} ({{ $window['timezone'] }})
                        @if ($cron['last_run_age_minutes'] !== null)
                            — {{ $elapsed($cron['last_run_age_minutes']) }} {{ translate('ago') }}
                        @endif
                    </small>
                @endif
                @if (!empty($cron['remedy']))
                    <code>{{ $cron['remedy'] }}</code>
                @endif
                @if (!empty($cron['stopped_tasks']))
                    <small>
                        {{ count($cron['stopped_tasks']) }}
                        {{ translate('tasks_are_defined_in_this_application_and_none_of_them_can_be_running') }}:
                        {{ implode(', ', array_slice($cron['stopped_tasks'], 0, $stoppedTasksShown)) }}@if (count($cron['stopped_tasks']) > $stoppedTasksShown), +{{ count($cron['stopped_tasks']) - $stoppedTasksShown }} {{ translate('more') }}@endif
                    </small>
                @endif
            </span>
        </div>
    </div>
@elseif ($cron['running'] === null)
    {{-- Not the same claim as "cron is down", and it must not be drawn as one: this is monitoring
         failing to read its own table, which is a different job for a different person. --}}
    <div class="mon-attention">
        <div class="mon-attention__item mon-attention__item--warning">
            <x-k.icon name="alert" :size="16" />
            <span class="mon-attention__body">
                <strong>{{ translate('whether_cron_is_running_could_not_be_determined') }}</strong>
                <small>{{ $cron['note'] ?? $stateTitle($cron['state']) }}</small>
                <small>{{ translate('the_task_states_below_are_read_from_the_same_source_so_treat_them_as_unconfirmed_until_this_is_resolved') }}</small>
                @if (!empty($cron['remedy']))
                    <code>{{ $cron['remedy'] }}</code>
                @endif
            </span>
        </div>
    </div>
@endif

{{-- The collector's own readings, each rendering its own state so a count it could not take can
     never be drawn as a zero. --}}
<x-k.card :title="translate('scheduler_at_a_glance')">
    @if (!empty($panel['headline']))
        <div class="mon-grid">
            @foreach ($panel['headline'] as $name => $metric)
                @include('admin-views.monitoring.partials._metric', ['metric' => $metric, 'label' => translate($name)])
            @endforeach
        </div>
    @else
        <x-k.empty icon="clock" :title="$stateTitle($cron['state'])" :text="$cron['note'] ?? ''" />
    @endif

    <p class="mon-note">
        @if ($cron['running'] === true)
            {{ translate('cron_reported_a_run') }}
            @if ($cron['last_run_age_minutes'] !== null)
                {{ $elapsed($cron['last_run_age_minutes']) }} {{ translate('ago') }}
            @endif
            ({{ $cron['last_run_at'] }} {{ $window['timezone'] }}) —
            {{ translate('anything_older_than') }} {{ $cron['late_after_minutes'] }}
            {{ translate('minutes_is_treated_as_a_stopped_cron') }}.
        @else
            {{ translate('a_run_older_than') }} {{ $cron['late_after_minutes'] }}
            {{ translate('minutes_is_treated_as_a_stopped_cron') }}
            (<code>monitoring.thresholds.scheduler_late_minutes</code>).
        @endif
    </p>
</x-k.card>

{{-- Every defined task, worst state first. An operator opens this page to find what is broken,
     and a table in alphabetical order buries the one row that matters. --}}
<x-k.card :title="translate('scheduled_tasks')">
    @if ($tasks['state'] === 'ok' && !empty($tasks['rows']))
        <div class="k-table-wrap">
            <table class="k-table k-table--compact">
                <thead>
                <tr>
                    <th>{{ translate('task') }}</th>
                    <th>{{ translate('schedule') }}</th>
                    <th>{{ translate('last_run') }}</th>
                    <th class="k-table__num">{{ translate('duration') }}</th>
                    <th>{{ translate('status') }}</th>
                    <th>{{ translate('next_due') }}</th>
                    <th class="k-table__num">{{ translate('success_rate_in_window') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($tasks['rows'] as $task)
                    <tr>
                        <td>
                            <code>{{ $task['task'] }}</code>
                            @if ($task['description'])
                                <small class="mon-metric__note" style="display:block">{{ $task['description'] }}</small>
                            @endif
                            @if ($task['last_error'])
                                {{-- The error belongs beside the task, not only in the history: a
                                     nightly task that has failed every night reads as one row here. --}}
                                <small class="mon-note mon-note--critical" style="display:block">{{ $task['last_error'] }}</small>
                            @endif
                        </td>
                        <td>
                            {{ $task['schedule'] ?? '—' }}
                            <small class="mon-metric__note" style="display:block"><code>{{ $task['expression'] }}</code></small>
                        </td>
                        <td class="k-num">
                            @if ($task['last_run_at'])
                                {{ $task['last_run_at'] }}
                                @if ($task['last_run_age_minutes'] !== null)
                                    <small class="mon-metric__note" style="display:block"
                                           title="{{ $task['last_run_age_minutes'] }} {{ translate('minutes') }}">
                                        {{ $elapsed($task['last_run_age_minutes']) }} {{ translate('ago') }}
                                    </small>
                                @endif
                            @else
                                <span class="mon-metric__state">{{ translate('never_recorded') }}</span>
                            @endif
                        </td>
                        <td class="k-table__num k-num">{{ $duration($task['last_duration_ms']) }}</td>
                        <td>
                            <span class="mon-pill {{ $statusPill($task['status']) }}">{{ translate($task['status']) }}</span>
                            @if ($task['last_status'] && $task['last_status'] !== $task['status'])
                                <small class="mon-metric__note" style="display:block">
                                    {{ translate('last_run') }}: {{ translate($task['last_status']) }}
                                </small>
                            @endif
                        </td>
                        <td class="k-num">
                            @if ($task['next_due_at'])
                                {{ $task['next_due_at'] }}
                                @if ($task['next_due_in_minutes'] !== null)
                                    <small class="mon-metric__note" style="display:block"
                                           title="{{ $task['next_due_in_minutes'] }} {{ translate('minutes') }}">
                                        {{ translate('in') }} {{ $elapsed($task['next_due_in_minutes']) }}
                                    </small>
                                @endif
                            @else
                                <span class="mon-metric__state">{{ translate('unreadable_expression') }}</span>
                            @endif
                        </td>
                        <td class="k-table__num k-num">
                            @if ($task['success_rate'] !== null)
                                {{ $task['success_rate'] }}%
                                <small class="mon-metric__note" style="display:block">
                                    {{ $task['successes'] }}/{{ $task['settled'] }} {{ translate('runs') }}
                                </small>
                            @else
                                {{-- Zero settled runs is not a zero per cent success rate: it is the
                                     absence of anything to rate, and the two read as opposites. --}}
                                <span class="mon-metric__state">{{ translate('no_runs_in_window') }}</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        <p class="mon-note">
            {{ translate('what_each_status_means') }}:
        </p>
        <ul class="mon-note">
            @foreach ($statusMeanings as $status => $meaning)
                <li>
                    <span class="mon-pill {{ $statusPill($status) }}">{{ translate($status) }}</span>
                    {{ translate($meaning) }}@if (!empty($tasks['counts'])) — {{ $tasks['counts'][$status] ?? 0 }} {{ translate('of') }} {{ count($tasks['rows']) }}@endif
                </li>
            @endforeach
        </ul>

        <p class="mon-note">
            {{ translate('schedules_are_stated_in_the_timezone_the_scheduler_evaluates_them_in') }}
            ({{ $window['scheduler_timezone'] }});
            {{ translate('run_and_due_timestamps_are_shown_in') }} {{ $window['timezone'] }}.
            {{ translate('the_success_rate_covers') }} {{ $window['since'] }} → {{ $window['until'] }}
            {{ translate('and_counts_only_runs_that_finished_either_way') }}.
        </p>

        @if ($statistics['state'] !== 'ok')
            <p class="mon-note">{{ $statistics['note'] }}</p>
            @if (!empty($statistics['remedy']))
                <details class="mon-metric__remedy">
                    <summary>{{ translate('how_to_enable_this') }}</summary>
                    <code>{{ $statistics['remedy'] }}</code>
                </details>
            @endif
        @endif

        @if (!empty($statistics['truncated']))
            <p class="mon-note">{{ translate('more_task_names_were_recorded_in_this_window_than_this_page_reads_so_some_success_rates_are_missing_rather_than_zero') }}.</p>
        @endif

        @if (!empty($tasks['retired']))
            {{-- Runs recorded under a name the schedule no longer defines. Real history with no
                 owner: usually a renamed command, occasionally one that was removed by accident. --}}
            <p class="mon-note">
                {{ translate('this_window_also_holds_runs_for_tasks_the_schedule_no_longer_defines') }}:
                {{ implode(', ', $tasks['retired']) }}.
            </p>
        @endif
    @else
        <x-k.empty icon="clock" :title="$stateTitle($tasks['state'])" :text="$tasks['note'] ?? ''" />
        @if (!empty($tasks['remedy']))
            <details class="mon-metric__remedy">
                <summary>{{ translate('how_to_enable_this') }}</summary>
                <code>{{ $tasks['remedy'] }}</code>
            </details>
        @endif
    @endif
</x-k.card>

{{-- What actually ran, newest first. The table above says what each task is doing now; this one is
     how it got there — the same task failing three nights running is visible here and nowhere else. --}}
<x-k.card :title="translate('recent_runs')">
    @if ($history['state'] === 'ok' && !empty($history['rows']))
        <div class="k-table-wrap">
            <table class="k-table k-table--compact">
                <thead>
                <tr>
                    <th>{{ translate('started') }}</th>
                    <th>{{ translate('task') }}</th>
                    <th>{{ translate('schedule') }}</th>
                    <th>{{ translate('outcome') }}</th>
                    <th class="k-table__num">{{ translate('duration') }}</th>
                    <th>{{ translate('error') }}</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($history['rows'] as $run)
                    <tr>
                        <td class="k-num">{{ $run['started_at'] ?? '—' }}</td>
                        <td><code>{{ $run['task'] }}</code></td>
                        <td><code>{{ $run['expression'] ?? '—' }}</code></td>
                        <td><span class="mon-pill {{ $statusPill($run['status']) }}">{{ translate($run['status']) }}</span></td>
                        <td class="k-table__num k-num">{{ $duration($run['duration_ms']) }}</td>
                        <td>
                            @if ($run['error'])
                                <span class="mon-note mon-note--critical">{{ $run['error'] }}</span>
                            @else
                                —
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <p class="mon-note">
            {{ count($history['rows']) }}
            @if ($history['truncated'])
                {{ translate('most_recent_runs_this_window_holds_more_than_are_listed') }}
            @else
                {{ translate('runs_recorded_in_this_window') }}
            @endif
            — {{ $window['since'] }} → {{ $window['until'] }} ({{ $window['timezone'] }}).
            {{ translate('error_text_is_redacted_and_shortened_where_it_is_written_a_scheduled_task_message_is_a_reliable_place_to_find_a_token_or_a_customer_address') }}.
        </p>
    @else
        <x-k.empty icon="clock" :title="$stateTitle($history['state'])" :text="$history['note'] ?? ''" />
        @if (!empty($history['remedy']))
            <details class="mon-metric__remedy">
                <summary>{{ translate('how_to_enable_this') }}</summary>
                <code>{{ $history['remedy'] }}</code>
            </details>
        @endif
    @endif
</x-k.card>

{{-- Normally empty. A reading the collector produces and this page draws nowhere is
     indistinguishable from one nobody ever took, so it is named rather than dropped. --}}
@if (!empty($panel['unrendered']))
    <p class="mon-note">
        {{ translate('the_collector_also_returned_readings_this_page_does_not_draw') }}:
        @foreach ($panel['unrendered'] as $reading)
            <code>{{ $reading['metric'] }}</code> ({{ translate($reading['state']) }}){{ $loop->last ? '' : ',' }}
        @endforeach
    </p>
@endif

<p class="mon-note">
    {{ translate('the_task_list_and_its_due_times_are_read_from_the_applications_own_schedule_object_so_a_task_added_to') }}
    <code>bootstrap/app.php</code>
    {{ translate('is_monitored_the_moment_it_exists') }}.
    {{ translate('runs_durations_and_outcomes_are_read_from') }} <code>monitoring_scheduled_runs</code>,
    {{ translate('written_by_the_task_listeners_in') }} <code>MonitoringServiceProvider</code>.
</p>
