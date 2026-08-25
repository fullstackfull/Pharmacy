@extends('layouts.seller.app')

@section('title', translate('nav_returns'))

@php
    use App\Services\SellerCenter\Copy;
    use App\Services\SellerCenter\Lists\ReturnList;

    $columns = [
        ['key' => 'reference', 'label' => translate('reference'), 'width' => 140],
        ['key' => 'order', 'label' => translate('order'), 'width' => 100],
        ['key' => 'product', 'label' => translate('product')],
        ['key' => 'qty', 'label' => translate('units'), 'width' => 80, 'num' => true],
        ['key' => 'reason', 'label' => translate('reason'), 'width' => 160, 'priority' => 'md'],
        ['key' => 'status', 'label' => translate('status'), 'width' => 130],
        ['key' => 'action', 'label' => '', 'width' => 90],
    ];

    $views = collect(ReturnList::VIEWS)->map(fn ($view, $key) => [
        'key' => $key,
        'label' => translate($view['label']),
        'href' => $key === 'all' ? route('seller.returns.index') : route('seller.returns.index', ['view' => $key]),
        'tone' => $view['tone'],
    ])->values()->all();
@endphp

@section('content')
    <x-sc.page-header :eyebrow="translate('nav_orders')" :title="translate('returns_coming_back')"
                      :sub="translate('a_refund_gives_back_the_money_a_return_is_how_the_units_come_home')" />

    @if (!$available)
        {{-- The tables are not on this installation. Said plainly rather than rendered as an empty
             list, because "you have no returns" and "returns are not set up here" send a seller to
             two completely different places. --}}
        <div class="sc-scroll"><div class="sc-page">
            <x-sc.empty glyph="warning" :title="translate('returns_are_not_available_on_this_installation')"
                        :text="translate('the_return_tables_have_not_been_created_ask_the_marketplace_to_run_its_migrations')" />
        </div></div>
    @else
        <x-sc.tabs :tabs="$views" :current="$currentView" />

        <div class="sc-scroll">
            <div class="sc-page" style="padding-bottom:0">
                <div class="sc-stats">
                    <x-sc.stat :label="translate('open_returns')" :value="number_format($summary['open'])"
                               :note="translate('authorized_in_transit_or_arrived')" />
                    <x-sc.stat :label="translate('in_transit')" :value="number_format($summary['in_transit'])"
                               :note="translate('on_their_way_back_to_you')" />
                    {{-- The number that costs money while it waits: units the shop has paid for,
                         sitting on a shelf, not sellable until somebody decides. --}}
                    <x-sc.stat :label="translate('awaiting_your_decision')" :value="number_format($summary['awaiting_decision'])"
                               :tone="$summary['awaiting_decision'] > 0 ? 'high' : null"
                               :note="translate('arrived_and_not_yet_restocked_or_refused')" />
                    <x-sc.stat :label="translate('units_back_in_stock')" :value="number_format($summary['units_back'])"
                               :note="translate('restocked_and_sellable_again')" />
                </div>

                @if ($summary['awaiting_decision'] > 0)
                    <x-sc.alert tone="high" class="mt-3"
                                :title="Copy::line('n_returns_are_waiting_on_you', ['count' => $summary['awaiting_decision']])">
                        {{ translate('every_unit_here_is_stock_you_have_already_paid_for_and_cannot_sell') }}
                        <x-slot:action>
                            <x-sc.button variant="secondary" size="sm" :href="route('seller.returns.index', ['view' => 'received'])">
                                {{ translate('review_them') }}
                            </x-sc.button>
                        </x-slot:action>
                    </x-sc.alert>
                @endif
            </div>

            <x-sc.toolbar :count="Copy::line('n_returns', ['count' => $returns->total()])"
                          :search-url="route('seller.returns.index')"
                          :search-value="request('q', '')"
                          :search-placeholder="translate('reference_order_or_tracking')"
                          :chips="$filters->chips()"
                          :clear-url="$filters->urlClearAll()" />

            <x-sc.table :columns="$columns" :state="$state">
                <x-slot:empty>
                    <x-sc.empty glyph="package" :title="translate('nothing_has_come_back_yet')"
                                :text="translate('when_a_refund_is_approved_a_return_opens_here_so_the_units_can_be_restocked')" />
                </x-slot:empty>
                <x-slot:noResults>
                    <x-sc.empty glyph="funnel" :title="translate('no_returns_match_these_filters')"
                                :text="translate('adjust_or_clear_the_filters_to_see_more')" />
                </x-slot:noResults>

                @foreach ($returns as $rma)
                    <x-sc.tr :href="route('seller.returns.show', ['rma' => $rma->id])" :id="$rma->id">
                        <x-sc.td class="sc-code">{{ $rma->reference }}</x-sc.td>
                        <x-sc.td class="sc-code">#{{ $rma->order_id }}</x-sc.td>
                        <x-sc.td>{{ $names[$rma->product_id] ?? translate('product_no_longer_listed') }}</x-sc.td>
                        <x-sc.td num>{{ number_format((int) $rma->qty) }}</x-sc.td>
                        <x-sc.td drop="md" class="sc-muted">{{ $rma->reason ? translate($rma->reason) : '—' }}</x-sc.td>
                        <x-sc.td><x-sc.badge :status="$rma->status" /></x-sc.td>
                        <x-sc.td action>
                            <a class="sc-btn sc-btn--ghost sc-btn--sm" href="{{ route('seller.returns.show', ['rma' => $rma->id]) }}">
                                {{ translate('open') }}
                            </a>
                        </x-sc.td>
                    </x-sc.tr>
                @endforeach

                <x-slot:mobile>
                    @foreach ($returns as $rma)
                        <x-sc.entity-card :title="$names[$rma->product_id] ?? translate('product_no_longer_listed')"
                                          :href="route('seller.returns.show', ['rma' => $rma->id])"
                                          :figure="number_format((int) $rma->qty)"
                                          :meta="$rma->reference . ' · #' . $rma->order_id">
                            <div class="sc-row"><x-sc.badge :status="$rma->status" /></div>
                        </x-sc.entity-card>
                    @endforeach
                </x-slot:mobile>

                <x-slot:footer><x-sc.pager :paginator="$returns" /></x-slot:footer>
            </x-sc.table>
        </div>
    @endif
@endsection
