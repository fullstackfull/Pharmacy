@extends('layouts.seller.app')

@section('title', translate('nav_order_report'))

@php
    use App\Services\SellerCenter\Copy;

    $columns = [
        ['key' => 'order', 'label' => translate('order'), 'width' => 110],
        ['key' => 'placed', 'label' => translate('placed'), 'width' => 150],
        ['key' => 'status', 'label' => translate('status'), 'width' => 140],
        ['key' => 'payment', 'label' => translate('payment'), 'width' => 160, 'priority' => 'md'],
        ['key' => 'amount', 'label' => translate('order_amount'), 'width' => 130, 'num' => true],
        ['key' => 'discount', 'label' => translate('discount'), 'width' => 120, 'num' => true, 'priority' => 'lg'],
        ['key' => 'commission', 'label' => translate('commission'), 'width' => 130, 'num' => true],
    ];

    $period = request()->only(['date_type', 'from', 'to']);
@endphp

@section('content')
    <x-sc.page-header :eyebrow="translate('nav_reports')" :title="translate('nav_order_report')"
                      :sub="translate('every_order_in_the_period_with_what_the_marketplace_took')"
                      :back="route('seller.reports.index')">
        <x-slot:actions>
            <x-sc.button variant="secondary" icon="file-xls" :href="route('seller.exports.orders', $period)">{{ translate('excel') }}</x-sc.button>
            <x-sc.button variant="secondary" icon="file-pdf" :href="route('seller.exports.orders-pdf', $period)">{{ translate('pdf') }}</x-sc.button>
        </x-slot:actions>
    </x-sc.page-header>

    <div class="sc-scroll">
        <div class="sc-page">
            @include('seller-views.reports._period', ['action' => route('seller.reports.orders')])

            <div class="sc-stats mt-3">
                <x-sc.stat :label="translate('orders')" :value="number_format($report['counts']['total'])"
                           :note="Copy::line('covering_x_to_y', ['from' => $window->from->toDateString(), 'to' => $window->to->toDateString()])" />
                <x-sc.stat :label="translate('delivered')" :value="number_format($report['counts']['delivered'])" />
                <x-sc.stat :label="translate('settled')" :value="number_format($report['amounts']['settled'], 2)" />
                <x-sc.stat :label="translate('still_due')" :value="number_format($report['amounts']['due'], 2)" />
            </div>

            <x-sc.toolbar class="mt-3" :count="$orders->total()" :search-url="route('seller.reports.orders')"
                          :search-value="$search" :search-placeholder="translate('search_by_order_number')" />

            <x-sc.table :columns="$columns" :state="$state">
                <x-slot:empty>
                    <x-sc.empty glyph="receipt" :title="translate('no_orders_in_this_period')"
                                :text="translate('choose_a_wider_period_to_see_more')" />
                </x-slot:empty>
                <x-slot:noResults>
                    <x-sc.empty glyph="funnel" :title="translate('no_orders_match_that_search')"
                                :text="translate('the_search_matches_an_order_number')" />
                </x-slot:noResults>

                @foreach ($orders as $order)
                    <x-sc.tr :href="route('seller.orders.show', ['order' => $order->id])" :id="$order->id">
                        <x-sc.td><span class="sc-code">{{ $order->id }}</span></x-sc.td>
                        <x-sc.td>{{ $order->created_at?->format('Y-m-d H:i') ?? '—' }}</x-sc.td>
                        <x-sc.td><x-sc.badge :status="$order->order_status" /></x-sc.td>
                        <x-sc.td :sub="translate($order->payment_status)">{{ translate($order->payment_method ?? 'unknown') }}</x-sc.td>
                        <x-sc.td num>{{ number_format((float) $order->order_amount, 2) }}</x-sc.td>
                        {{-- Per-line, from the details: a discount recorded per line is the only
                             place the real figure lives. --}}
                        <x-sc.td num>{{ number_format((float) ($order->details_sum_discount ?? 0), 2) }}</x-sc.td>
                        <x-sc.td num>{{ number_format((float) $order->admin_commission, 2) }}</x-sc.td>
                    </x-sc.tr>
                @endforeach
            </x-sc.table>

            <x-sc.pager :paginator="$orders" />
        </div>
    </div>
@endsection
