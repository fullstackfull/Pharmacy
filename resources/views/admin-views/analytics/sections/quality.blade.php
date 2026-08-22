{{-- Analytics grading itself. A pipeline that cannot report its own failure draws a flat line
     everybody reads as a quiet week. --}}
<x-k.card :title="translate('collection_health')">
    <div class="ana-health ana-health--{{ $data['health']['state'] }}">
        <strong>{{ translate($data['health']['state']) }}</strong>
        @if (isset($data['health']['message']))
            <p>{{ $data['health']['message'] }}</p>
        @endif
    </div>
    <ul class="ana-list">
        @isset($data['health']['last_event_at'])
            <li><span>{{ translate('last_event') }}</span><strong>{{ $data['health']['last_event_at'] }} ({{ $data['health']['last_event_age_minutes'] ?? '—' }} {{ translate('minutes_ago') }})</strong></li>
        @endisset
        @isset($data['health']['last_rollup_at'])
            <li><span>{{ translate('last_rollup') }}</span><strong>{{ $data['health']['last_rollup_at'] }}</strong></li>
        @endisset
        @isset($data['health']['write_failures'])
            <li>
                <span>{{ translate('failed_writes') }}</span>
                <strong class="{{ $data['health']['write_failures'] > 0 ? 'ana-warn' : '' }}">{{ number_format($data['health']['write_failures']) }}</strong>
            </li>
        @endisset
    </ul>
    @if (($data['health']['write_failure_detail'] ?? null))
        <p class="ana-warn">{{ $data['health']['write_failure_detail'] }}</p>
    @endif
</x-k.card>

<x-k.card :title="translate('traffic_excluded_from_every_figure')">
    <p class="ana-note">{{ translate('bots_and_staff_are_recorded_and_flagged_never_deleted_a_filter_nobody_can_measure_is_indistinguishable_from_one_that_has_stopped_working') }}</p>
    @if (($data['excluded']['rows'] ?? []) === [])
        <x-k.empty :title="translate('nothing_was_excluded')" :text="translate('no_bot_or_staff_traffic_was_recorded_in_this_period')" />
    @else
        <div class="ana-metrics">
            <div class="ana-metric">
                <small>{{ translate('counted_as_customers') }}</small>
                <span class="k-num">{{ number_format($data['excluded']['counted_sessions']) }}</span>
            </div>
            <div class="ana-metric">
                <small>{{ translate('excluded') }}</small>
                <span class="k-num">{{ number_format($data['excluded']['excluded_sessions']) }}</span>
                <span class="ana-change ana-change--none">{{ $data['excluded']['excluded_share'] }}% {{ translate('of_all_recorded_visits') }}</span>
            </div>
        </div>
        <table class="ana-table">
            <thead><tr><th>{{ translate('kind') }}</th><th class="ana-num">{{ translate('visits') }}</th><th class="ana-num">{{ translate('visitors') }}</th><th class="ana-num">{{ translate('pageviews') }}</th></tr></thead>
            <tbody>
            @foreach ($data['excluded']['rows'] as $row)
                <tr>
                    <td>{{ translate($row['kind']) }}</td>
                    <td class="ana-num">{{ number_format($row['sessions']) }}</td>
                    <td class="ana-num">{{ number_format($row['visitors']) }}</td>
                    <td class="ana-num">{{ number_format($row['pageviews']) }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif
</x-k.card>

@include('admin-views.analytics.sections._breakdown', ['breakdown' => $data['events'], 'title' => translate('what_is_being_recorded'), 'label' => translate('event'), 'dimension' => 'event', 'window' => $window, 'showEngagement' => false])
