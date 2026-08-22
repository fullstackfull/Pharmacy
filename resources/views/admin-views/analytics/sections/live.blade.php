{{-- Live reads the event table directly. "Live" and "rolled up" are contradictory, so nothing on
     this screen comes from a rollup. --}}
<div class="ana-grid ana-grid--2">
    <x-k.card :title="translate('right_now')">
        <div class="ana-metrics">
            <div class="ana-metric">
                <small>{{ translate('active_visits') }}</small>
                <span class="k-num" data-live="active">{{ number_format($data['live']['active_sessions'] ?? 0) }}</span>
                <span class="ana-change ana-change--none">{{ translate('last') }} {{ $data['live']['window_minutes'] ?? 30 }} {{ translate('minutes') }}</span>
            </div>
            <div class="ana-metric">
                <small>{{ translate('events') }}</small>
                <span class="k-num" data-live="events">{{ number_format(count($data['live']['events'] ?? [])) }}</span>
                <span class="ana-change ana-change--none">{{ translate('most_recent_first') }}</span>
            </div>
        </div>
    </x-k.card>

    <x-k.card :title="translate('events_per_minute')">
        @php($minutes = collect($data['live']['per_minute'] ?? []))
        @if ($minutes->isEmpty())
            <x-k.empty :title="translate('quiet')" :text="translate('nothing_has_happened_in_this_window_collection_is_healthy')" />
        @else
            @php($peak = max(1, (int) $minutes->max('events')))
            <div class="ana-hours">
                @foreach ($minutes as $minute)
                    <div class="ana-hours__slot" title="{{ $minute->minute }} — {{ $minute->events }}">
                        <span class="ana-hours__bar" style="height: {{ max(4, round(100 * $minute->events / $peak)) }}%"></span>
                    </div>
                @endforeach
            </div>
        @endif
    </x-k.card>
</div>

<x-k.card :title="translate('the_last_things_that_happened')">
    @if (($data['live']['events'] ?? []) === [])
        <x-k.empty :title="translate('quiet')" :text="translate('no_customer_activity_in_this_window_this_is_a_real_zero_not_a_collection_failure')" />
    @else
        <table class="ana-table" data-live="feed">
            <thead><tr>
                <th>{{ translate('when') }}</th><th>{{ translate('event') }}</th>
                <th>{{ translate('what') }}</th><th>{{ translate('page') }}</th><th>{{ translate('channel') }}</th>
            </tr></thead>
            <tbody>
            @foreach ($data['live']['events'] as $event)
                <tr>
                    <td class="ana-muted">{{ \Carbon\Carbon::parse($event->occurred_at)->diffForHumans(null, true) }}</td>
                    <td><strong>{{ translate($event->name) }}</strong></td>
                    <td class="ana-muted">
                        {{ $event->entity_type ? $event->entity_type . ' ' . $event->entity_id : '—' }}
                        @if ($event->value)· {{ number_format((float) $event->value, 2) }}@endif
                    </td>
                    <td class="ana-muted">{{ $event->path }}</td>
                    <td class="ana-muted">{{ $event->channel }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif
</x-k.card>
