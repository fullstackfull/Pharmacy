<x-k.card :title="translate('revenue')">
    <div class="ana-metrics">
        @include('admin-views.analytics.sections._metric', ['label' => 'revenue', 'metric' => $data['totals']['revenue'], 'format' => 'money'])
        @include('admin-views.analytics.sections._metric', ['label' => 'orders', 'metric' => $data['totals']['orders']])
        @include('admin-views.analytics.sections._metric', ['label' => 'average_order_value', 'metric' => $data['totals']['average_order_value'], 'format' => 'money'])
        @include('admin-views.analytics.sections._metric', ['label' => 'conversion_rate', 'metric' => $data['totals']['conversion_rate'], 'suffix' => '%'])
        @include('admin-views.analytics.sections._metric', ['label' => 'carts_started', 'metric' => $data['totals']['cart_adds']])
        @include('admin-views.analytics.sections._metric', ['label' => 'checkouts_started', 'metric' => $data['totals']['checkouts']])
    </div>
</x-k.card>

@include('admin-views.analytics.sections._trend', ['trend' => $data['trend'], 'trendState' => $data['totals']['state'] ?? null])

<div class="ana-grid ana-grid--2">
    @include('admin-views.analytics.sections._breakdown', ['breakdown' => $data['sources'], 'title' => translate('revenue_by_source'), 'label' => translate('source'), 'dimension' => 'source', 'window' => $window])
    @include('admin-views.analytics.sections._breakdown', ['breakdown' => $data['campaigns'], 'title' => translate('revenue_by_campaign'), 'label' => translate('campaign'), 'dimension' => 'campaign', 'window' => $window])
</div>

{{-- What each order actually looked like.

     Payment method, coupon use, guest share and shipping cost have been written into
     analytics_events.properties on every order since the day that table was built, and exactly one
     reader existed in the whole codebase. So the shop recorded, on every sale, the answers to
     "which payment method do people actually use", "how many orders used a coupon" and "what is
     shipping costing us" — and could ask none of them. --}}
@php($attributes = $data['attributes'])
<x-k.card :title="translate('what_each_order_looked_like')">
    @if (($attributes['state'] ?? '') === 'ok')
        <div class="ana-metrics">
            <div class="ana-metric">
                <small>{{ translate('orders_with_a_coupon') }}</small>
                <span class="k-num">{{ number_format($attributes['coupon_orders']) }}</span>
                <span class="ana-change ana-change--none">{{ translate('of') }} {{ number_format($attributes['orders']) }}</span>
            </div>
            <div class="ana-metric">
                <small>{{ translate('guest_orders') }}</small>
                <span class="k-num">{{ number_format($attributes['guest_orders']) }}</span>
                <span class="ana-change ana-change--none">{{ translate('of') }} {{ number_format($attributes['orders']) }}</span>
            </div>
            <div class="ana-metric">
                <small>{{ translate('average_shipping_cost') }}</small>
                <span class="k-num">
                    {{ $attributes['average_shipping'] === null ? '—' : number_format(usdToDefaultCurrency($attributes['average_shipping']), 2) }}
                </span>
                <span class="ana-change ana-change--none">{{ getCurrencyCode() }}</span>
            </div>
        </div>

        <div class="k-table-wrap">
            <table class="k-table k-table--compact">
                <thead><tr>
                    <th>{{ translate('payment_method') }}</th>
                    <th class="k-table__num">{{ translate('orders') }}</th>
                    <th class="k-table__num">{{ translate('share') }}</th>
                </tr></thead>
                <tbody>
                @foreach ($attributes['payment_methods'] as $method)
                    <tr>
                        <td>{{ translate($method['key']) }}</td>
                        <td class="k-table__num k-num">{{ number_format($method['orders']) }}</td>
                        <td class="k-table__num k-num">
                            {{ $attributes['orders'] > 0 ? round($method['orders'] / $attributes['orders'] * 100, 1) : 0 }}%
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        @if ($attributes['capped'])
            {{-- Said out loud rather than presented as the whole truth: this is the most recent
                 slice, and somebody could price shipping off it. --}}
            <p class="mon-note">{{ translate('read_from_the_most_recent_orders_in_this_window_not_all_of_them') }}</p>
        @endif
    @elseif (($attributes['state'] ?? '') === 'unavailable')
        <p class="mon-note mon-note--critical">{{ translate('this_could_not_be_read') }}</p>
    @else
        <x-k.empty icon="orders"
                   :title="translate('no_orders_in_this_window')"
                   :text="translate('order_attributes_are_read_from_the_events_recorded_when_an_order_is_placed')" />
    @endif
    <p class="mon-note">{{ translate('source') }}: <code>{{ $attributes['source'] ?? 'analytics_events.properties' }}</code></p>
</x-k.card>
