{{-- How long things took, what came back, and what was refunded.

     Every figure on this screen is subtracted from two timestamps the platform has been writing
     since its first order — picked_at, packed_at, shipped_at, received_at, the delivered row in the
     status history — and until now nothing anywhere subtracted any of them. A marketplace that
     enforces an SLA, opens breaches against it and suspends sellers for breaching it could not
     measure how late anything actually was.

     Medians and p90s rather than means throughout: one order that sat over a public holiday moves a
     mean by hours and a median not at all, and "one in ten takes longer than this" is a sentence
     about a real customer where an average is a sentence about nobody. --}}
@php($dispatch = $data['dispatch'])
@php($delivery = $data['delivery'])
@php($shipping = $data['shipping'])
@php($returns = $data['returns'])
@php($refunds = $data['refunds'])
@php($hours = fn (?float $value) => $value === null ? '—' : number_format($value, 1) . ' ' . translate('hours_short'))

<x-k.card :title="translate('from_order_to_carrier')">
    @if ($dispatch['measured'] === 0 && $dispatch['open'] === 0)
        <x-k.empty :title="translate('no_fulfilments_in_this_period')"
                   :text="translate('a_fulfilment_opens_when_a_seller_starts_picking_an_order')" />
    @else
        <div class="ana-metrics">
            <div class="ana-metric">
                <small>{{ translate('median_dispatch_time') }}</small>
                <span class="k-num">{{ $hours($dispatch['median_hours']) }}</span>
                <small class="ana-metric__caveat">{{ \App\Services\SellerCenter\Copy::line('measured_on_n_dispatched_fulfilments', ['count' => number_format($dispatch['measured'])]) }}</small>
            </div>
            <div class="ana-metric">
                <small>{{ translate('slowest_one_in_ten') }}</small>
                <span class="k-num">{{ $hours($dispatch['p90_hours']) }}</span>
                <small class="ana-metric__caveat">{{ translate('the_ninetieth_percentile_is_what_an_operator_acts_on') }}</small>
            </div>
            <div class="ana-metric">
                <small>{{ translate('past_the_marketplaces_own_limit') }}</small>
                <span class="k-num">{{ number_format($dispatch['late']) }}</span>
                {{-- The same key the shipping exception detector raises issues from and the seller's
                     own fulfilment screen reads, so the report, the issue and the screen cannot
                     disagree about what late means. --}}
                <small class="ana-metric__caveat">{{ \App\Services\SellerCenter\Copy::line('the_limit_is_n_hours', ['count' => $dispatch['threshold_hours']]) }}</small>
            </div>
            <div class="ana-metric">
                <small>{{ translate('still_open') }}</small>
                <span class="k-num">{{ number_format($dispatch['open']) }}</span>
                {{-- Open is not slow. It has no dispatch time because it has not been dispatched,
                     and counting it as either figure would be a claim nobody made. --}}
                <small class="ana-metric__caveat">{{ translate('not_yet_dispatched_so_not_yet_measured') }}</small>
            </div>
        </div>

        <div class="ana-metrics">
            <div class="ana-metric">
                <small>{{ translate('opened_to_picked') }}</small>
                <span class="k-num">{{ $hours($dispatch['stages']['to_pick']) }}</span>
            </div>
            <div class="ana-metric">
                <small>{{ translate('picked_to_packed') }}</small>
                <span class="k-num">{{ $hours($dispatch['stages']['to_pack']) }}</span>
            </div>
            <div class="ana-metric">
                <small>{{ translate('packed_to_shipped') }}</small>
                <span class="k-num">{{ $hours($dispatch['stages']['to_ship']) }}</span>
            </div>
        </div>
    @endif
</x-k.card>

<div class="ana-grid ana-grid--2">
    <x-k.card :title="translate('from_order_to_customer')">
        @if ($delivery['measured'] === 0)
            <x-k.empty :title="translate('nothing_was_delivered_in_this_period')"
                       :text="translate('delivery_time_is_read_from_the_status_history_not_from_the_current_status')" />
        @else
            <div class="ana-metrics">
                <div class="ana-metric">
                    <small>{{ translate('median_delivery_time') }}</small>
                    <span class="k-num">{{ $hours($delivery['median_hours']) }}</span>
                </div>
                <div class="ana-metric">
                    <small>{{ translate('slowest_one_in_ten') }}</small>
                    <span class="k-num">{{ $hours($delivery['p90_hours']) }}</span>
                </div>
                <div class="ana-metric">
                    <small>{{ translate('deliveries_measured') }}</small>
                    <span class="k-num">{{ number_format($delivery['measured']) }}</span>
                </div>
            </div>
        @endif
    </x-k.card>

    <x-k.card :title="translate('what_shipping_cost')">
        @if ($shipping['orders'] === 0)
            <x-k.empty :title="translate('no_orders_in_this_period')"
                       :text="translate('shipping_is_counted_from_the_orders_that_were_placed')" />
        @else
            <div class="ana-metrics">
                <div class="ana-metric">
                    <small>{{ translate('total_shipping') }}</small>
                    <span class="k-num">{{ number_format($shipping['total'], 2) }}</span>
                </div>
                <div class="ana-metric">
                    <small>{{ translate('average_per_order') }}</small>
                    <span class="k-num">{{ $shipping['average'] === null ? '—' : number_format($shipping['average'], 2) }}</span>
                </div>
                <div class="ana-metric">
                    <small>{{ translate('shipped_free') }}</small>
                    <span class="k-num">{{ number_format($shipping['free']) }}</span>
                </div>
            </div>

            <table class="k-table">
                <thead>
                    <tr>
                        <th scope="col">{{ translate('delivery_type') }}</th>
                        <th scope="col" class="k-num">{{ translate('orders') }}</th>
                        <th scope="col" class="k-num">{{ translate('shipping') }}</th>
                    </tr>
                </thead>
                <tbody>
                    {{-- Only types that actually carried an order. A configured zone with nothing in
                         it is a setting, not a measurement, and listing it here would imply it was
                         counted and found to be zero. --}}
                    @foreach ($shipping['by_type'] as $row)
                        <tr>
                            <td>{{ translate($row['label']) }}</td>
                            <td class="k-num">{{ number_format($row['orders']) }}</td>
                            <td class="k-num">{{ number_format($row['total'], 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-k.card>
</div>

<div class="ana-grid ana-grid--2">
    <x-k.card :title="translate('what_came_back')">
        @if ($returns['opened'] === 0)
            <x-k.empty :title="translate('nothing_came_back_in_this_period')"
                       :text="translate('a_return_opens_when_a_refund_is_authorised_and_the_units_are_expected_back')" />
        @else
            <div class="ana-metrics">
                <div class="ana-metric">
                    <small>{{ translate('returns_opened') }}</small>
                    <span class="k-num">{{ number_format($returns['opened']) }}</span>
                </div>
                <div class="ana-metric">
                    <small>{{ translate('arrived') }}</small>
                    <span class="k-num">{{ number_format($returns['received']) }}</span>
                </div>
                <div class="ana-metric">
                    <small>{{ translate('back_on_the_shelf') }}</small>
                    <span class="k-num">
                        {{ $returns['restock_rate'] === null ? '—' : number_format($returns['restock_rate'] * 100, 1) . '%' }}
                    </span>
                    {{-- Of what arrived, not of what was opened: a return still in the post has not
                         failed to be restocked, and counting it so would make the rate fall every
                         busy week. --}}
                    <small class="ana-metric__caveat">{{ translate('of_what_arrived') }}</small>
                </div>
                <div class="ana-metric">
                    <small>{{ translate('median_time_to_arrive') }}</small>
                    <span class="k-num">{{ $hours($returns['median_receive_hours']) }}</span>
                </div>
            </div>

            <table class="k-table">
                <thead>
                    <tr>
                        <th scope="col">{{ translate('reason') }}</th>
                        <th scope="col" class="k-num">{{ translate('returns') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($returns['by_reason'] as $row)
                        <tr>
                            <td>{{ translate($row['reason']) }}</td>
                            <td class="k-num">{{ number_format($row['count']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-k.card>

    <x-k.card :title="translate('what_was_refunded')">
        @if ($refunds['requested'] === 0)
            <x-k.empty :title="translate('nothing_was_refunded_in_this_period')"
                       :text="translate('a_refund_request_is_raised_by_a_customer_against_one_order_line')" />
        @else
            <div class="ana-metrics">
                <div class="ana-metric">
                    <small>{{ translate('requested') }}</small>
                    <span class="k-num">{{ number_format($refunds['requested']) }}</span>
                </div>
                <div class="ana-metric">
                    <small>{{ translate('approved') }}</small>
                    <span class="k-num">{{ number_format($refunds['approved']) }}</span>
                </div>
                <div class="ana-metric">
                    <small>{{ translate('rejected') }}</small>
                    <span class="k-num">{{ number_format($refunds['rejected']) }}</span>
                </div>
                <div class="ana-metric">
                    <small>{{ translate('refunded_value') }}</small>
                    <span class="k-num">{{ number_format($refunds['value'], 2) }}</span>
                </div>
                <div class="ana-metric">
                    <small>{{ translate('median_time_to_settle') }}</small>
                    <span class="k-num">{{ $hours($refunds['median_settle_hours']) }}</span>
                    {{-- An upper bound rather than an exact figure: there is no settled-at column,
                         so this measures to the row's last change. Saying so is the difference
                         between a caveat and a wrong number. --}}
                    <small class="ana-metric__caveat">{{ translate('an_upper_bound_measured_to_the_rows_last_change') }}</small>
                </div>
            </div>
        @endif
    </x-k.card>
</div>
