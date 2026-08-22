{{-- One ranked table, shared by every screen that ranks something. Written once so twelve screens
     cannot drift into showing different columns for the same shape of data. --}}
@php($rows = $breakdown['rows'] ?? [])
<x-k.card :title="$title">
    @if ($rows === [])
        @include('admin-views.analytics.sections._empty', ['state' => $breakdown['state'] ?? 'no_traffic'])
    @else
        <table class="ana-table">
            <thead>
            <tr>
                <th>{{ $label ?? translate('key') }}</th>
                <th class="ana-num">{{ translate('sessions') }}</th>
                @if ($showEngagement ?? true)
                    <th class="ana-num">{{ translate('bounce') }}</th>
                @endif
                <th class="ana-num">{{ translate('orders') }}</th>
                @if ($showRevenue ?? true)
                    <th class="ana-num">{{ translate('revenue') }}</th>
                @endif
                <th class="ana-num">{{ translate('share') }}</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($rows as $row)
                <tr>
                    <td>
                        @if (($row['deleted'] ?? false))
                            <span class="ana-muted" title="{{ translate('this_record_has_been_deleted_since') }}">#{{ $row['key'] }} — {{ translate('deleted') }}</span>
                        @else
                            {{ $row['name'] ?? $row['key'] }}
                        @endif
                    </td>
                    <td class="ana-num">{{ number_format($row['sessions'] ?: $row['events']) }}</td>
                    @if ($showEngagement ?? true)
                        <td class="ana-num">{{ $row['bounce_rate'] !== null ? $row['bounce_rate'] . '%' : '—' }}</td>
                    @endif
                    <td class="ana-num">{{ number_format($row['orders']) }}</td>
                    @if ($showRevenue ?? true)
                        <td class="ana-num">{{ $row['revenue'] > 0 ? number_format($row['revenue'], 2) : '—' }}</td>
                    @endif
                    <td class="ana-num">
                        <span class="ana-bar"><i style="width: {{ min(100, (float) ($row['share'] ?? 0)) }}%"></i></span>
                        {{ $row['share'] !== null ? $row['share'] . '%' : '—' }}
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>

        @isset($dimension)
            <a class="k-btn k-btn--ghost k-btn--sm ana-export"
               href="{{ route('admin.analytics.export', ['dimension' => $dimension, 'range' => $window->key]) }}">
                <i class="tio-download"></i> {{ translate('export_csv') }}
            </a>
        @endisset
    @endif
</x-k.card>
