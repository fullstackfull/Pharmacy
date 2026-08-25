@extends('layouts.seller.app')

@section('title', translate('nav_refunds'))

@php
    use App\Services\SellerCenter\Copy;

    $columns = [
        ['key' => 'order', 'label' => translate('order'), 'width' => 110],
        ['key' => 'product', 'label' => translate('product')],
        ['key' => 'amount', 'label' => translate('amount'), 'width' => 120, 'num' => true],
        ['key' => 'payment', 'label' => translate('paid_by'), 'width' => 140, 'priority' => 'md'],
        ['key' => 'status', 'label' => translate('status'), 'width' => 130],
        ['key' => 'raised', 'label' => translate('raised'), 'width' => 120, 'priority' => 'lg'],
    ];

    $tabs = collect(array_merge(['all'], $statuses))->map(fn ($key) => [
        'key' => $key,
        'label' => translate($key),
        'href' => $key === 'all' ? route('seller.refunds.index') : route('seller.refunds.index', ['status' => $key]),
    ])->values()->all();
@endphp

@section('content')
    <x-sc.page-header :eyebrow="translate('nav_orders')" :title="translate('refunds_on_your_orders')"
                      :sub="translate('the_marketplace_decides_a_refund_this_is_where_you_watch_yours')" />

    @if (!$available)
        <div class="sc-scroll"><div class="sc-page">
            <x-sc.empty glyph="warning" :title="translate('refunds_are_not_available_on_this_installation')"
                        :text="translate('the_refund_tables_have_not_been_created_ask_the_marketplace_to_run_its_migrations')" />
        </div></div>
    @else
        <x-sc.tabs :tabs="$tabs" :current="$status ?? 'all'" />

        <div class="sc-scroll">
            <div class="sc-page" style="padding-bottom:0">
                <div class="sc-stats">
                    <x-sc.stat :label="translate('awaiting_a_decision')" :value="number_format($summary['pending'])"
                               :note="translate('raised_and_not_yet_ruled_on')" />
                    <x-sc.stat :label="translate('approved')" :value="number_format($summary['approved'])"
                               :note="translate('agreed_and_not_yet_paid_back')" />
                    <x-sc.stat :label="translate('refunded')" :value="number_format($summary['refunded'])"
                               :note="translate('money_returned_to_the_customer')" />
                    {{-- What has actually left the shop, not what has been asked for: a pending
                         request is not money the seller has lost, and counting it as such would
                         misstate the books on a screen sellers read as an account. --}}
                    <x-sc.stat :label="translate('value_refunded')"
                               :value="number_format($summary['value'], 2)"
                               :note="translate('settled_refunds_only')" />
                </div>

                {{-- Said once, plainly. A screen that lists a decision somebody else makes and shows
                     no control is honest; one that shows a disabled button is an argument. --}}
                <x-sc.alert tone="info" compact class="mt-3">
                    {{ translate('a_refund_is_ruled_on_by_the_marketplace_when_one_is_approved_a_return_opens_so_your_units_can_come_back') }}
                    <x-slot:action>
                        <x-sc.button variant="ghost" size="sm" :href="route('seller.returns.index')">{{ translate('nav_returns') }}</x-sc.button>
                    </x-slot:action>
                </x-sc.alert>
            </div>

            <x-sc.toolbar :count="Copy::line('n_refunds', ['count' => $refunds->total()])"
                          :search-url="route('seller.refunds.index')"
                          :search-value="request('q', '')"
                          :search-placeholder="translate('order_number')" />

            <x-sc.table :columns="$columns" :state="$state">
                <x-slot:empty>
                    <x-sc.empty glyph="receipt" :title="translate('no_refunds_on_your_orders')"
                                :text="translate('a_refund_request_appears_here_the_moment_a_customer_raises_one')" />
                </x-slot:empty>
                <x-slot:noResults>
                    <x-sc.empty glyph="funnel" :title="translate('no_refunds_match_these_filters')"
                                :text="translate('adjust_or_clear_the_filters_to_see_more')" />
                </x-slot:noResults>

                @foreach ($refunds as $refund)
                    <x-sc.tr :href="route('seller.orders.show', ['order' => $refund->order_id])" :id="$refund->id">
                        <x-sc.td class="sc-code">#{{ $refund->order_id }}</x-sc.td>
                        <x-sc.td>{{ $refund->product?->getRawOriginal('name') ?? translate('product_no_longer_listed') }}</x-sc.td>
                        <x-sc.td num>{{ number_format((float) $refund->amount, 2) }}</x-sc.td>
                        <x-sc.td drop="md" class="sc-muted">{{ $refund->order?->payment_method ? translate($refund->order->payment_method) : '—' }}</x-sc.td>
                        <x-sc.td><x-sc.badge :status="$refund->status" /></x-sc.td>
                        <x-sc.td drop="lg" class="sc-muted">{{ $refund->created_at?->format('Y-m-d') }}</x-sc.td>
                    </x-sc.tr>
                @endforeach

                <x-slot:mobile>
                    @foreach ($refunds as $refund)
                        <x-sc.entity-card :title="$refund->product?->getRawOriginal('name') ?? translate('product_no_longer_listed')"
                                          :href="route('seller.orders.show', ['order' => $refund->order_id])"
                                          :figure="number_format((float) $refund->amount, 2)"
                                          :meta="'#' . $refund->order_id">
                            <div class="sc-row"><x-sc.badge :status="$refund->status" /></div>
                        </x-sc.entity-card>
                    @endforeach
                </x-slot:mobile>

                <x-slot:footer><x-sc.pager :paginator="$refunds" /></x-slot:footer>
            </x-sc.table>
        </div>
    @endif
@endsection
