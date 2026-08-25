{{--
    Telemetry feeds: the machine-readable surfaces this shop serves.

    None of them live under `api/`, so the explorer, the OpenAPI export, the Postman collection and
    the coverage score all skip them — which is how a complete monitoring API ended up served on
    every installation and described in no document. Each row states whether it is switched on HERE,
    because "the endpoint exists" and "the endpoint answers on this installation" are different
    facts and only the second is useful to whoever is wiring a collector.
--}}

<x-k.card :title="translate('machine_readable_feeds')">
    <p class="k-text-muted" style="margin-bottom:var(--k-size-3)">
        {{ translate('endpoints_for_collectors_rather_than_for_people') }}
    </p>
    <div class="k-table-wrap">
        <table class="k-table k-table--compact">
            <thead><tr>
                <th>{{ translate('feed') }}</th>
                <th>{{ translate('endpoint') }}</th>
                <th>{{ translate('authentication') }}</th>
                <th>{{ translate('format') }}</th>
                <th>{{ translate('status') }}</th>
            </tr></thead>
            <tbody>
            @foreach ($data['feeds'] as $key => $feed)
                <tr class="{{ $feed['enabled'] ? '' : 'mon-row--muted' }}">
                    <td>
                        <strong>{{ translate($key) }}</strong>
                        <small class="mon-metric__note" style="display:block">{{ translate($feed['note']) }}</small>
                    </td>
                    <td>
                        <code>{{ $feed['method'] }}</code>
                        @if ($feed['url'])
                            <code>{{ $feed['url'] }}</code>
                        @else
                            <span class="mon-metric__source">{{ translate('not_configured') }}</span>
                        @endif
                    </td>
                    <td>{{ translate($feed['auth']) }}</td>
                    <td><code>{{ $feed['format'] }}</code></td>
                    <td>
                        @if ($feed['enabled'])
                            <span class="mon-pill mon-pill--ok">{{ translate('on') }}</span>
                        @else
                            <span class="mon-pill mon-pill--missed">{{ translate('off') }}</span>
                            <small class="mon-metric__note" style="display:block">{{ translate($feed['disabled_hint']) }}</small>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</x-k.card>

<x-k.card :title="translate('monitoring_sections_available_as_json')">
    <p class="k-text-muted" style="margin-bottom:var(--k-size-3)">
        {{ translate('append_json_1_to_any_monitoring_section_url') }}
    </p>
    <div class="k-row" style="flex-wrap:wrap;gap:var(--k-size-2)">
        @foreach ($data['feeds']['monitoring_json']['sections'] as $section)
            <span class="k-chip"><code>{{ $section }}</code></span>
        @endforeach
    </div>
</x-k.card>
