@extends('layouts.seller.app')

@php
    use App\Services\SellerCenter\Copy;

    /*
     | One template, four screens. Picking, packing, shipments and exceptions are four questions
     | about the same rows — a template per question is how the same fulfilment ends up described
     | two different ways depending on which door you came in through.
     */
    $meta = [
        'shipments' => ['title' => 'all_fulfilments', 'route' => 'seller.shipments.index'],
        'picking' => ['title' => 'to_pick', 'route' => 'seller.picking.index'],
        'packing' => ['title' => 'to_pack', 'route' => 'seller.packing.index'],
        'exceptions' => ['title' => 'fulfilments_that_have_stalled', 'route' => 'seller.shipments.exceptions'],
    ][$screen];

    $tabs = [
        ['key' => 'shipments', 'label' => translate('all'), 'href' => route('seller.shipments.index')],
        ['key' => 'picking', 'label' => translate('to_pick'), 'href' => route('seller.picking.index')],
        ['key' => 'packing', 'label' => translate('to_pack'), 'href' => route('seller.packing.index')],
        ['key' => 'exceptions', 'label' => translate('stalled'), 'href' => route('seller.shipments.exceptions'), 'tone' => 'critical'],
    ];

    $columns = [
        ['key' => 'reference', 'label' => translate('reference'), 'width' => 140],
        ['key' => 'order', 'label' => translate('order'), 'width' => 100],
        ['key' => 'status', 'label' => translate('status'), 'width' => 130],
        ['key' => 'waiting', 'label' => translate('waiting'), 'width' => 120, 'num' => true],
        ['key' => 'dispatch', 'label' => translate('dispatch_time'), 'width' => 130, 'num' => true, 'priority' => 'md'],
        ['key' => 'carrier', 'label' => translate('carrier'), 'width' => 140, 'priority' => 'lg'],
        ['key' => 'action', 'label' => '', 'width' => 150],
    ];

    $nextStatus = [
        'pending' => 'picking',
        'picking' => 'packed',
        'packed' => 'ready',
        'ready' => 'shipped',
    ];
@endphp

@section('title', translate($meta['title']))

@section('content')
    <x-sc.page-header :eyebrow="translate('nav_fulfilment')" :title="translate($meta['title'])"
                      :sub="translate('the_work_between_paid_and_on_its_way')" />

    @if (!$available)
        <div class="sc-scroll"><div class="sc-page">
            <x-sc.empty glyph="warning" :title="translate('fulfilment_is_not_available_on_this_installation')"
                        :text="translate('the_fulfilment_table_has_not_been_created_ask_the_marketplace_to_run_its_migrations')" />
        </div></div>
    @else
        <x-sc.tabs :tabs="$tabs" :current="$screen" />

        <div class="sc-scroll">
            <div class="sc-page" style="padding-bottom:0">
                <div class="sc-stats">
                    <x-sc.stat :label="translate('to_pick')" :value="number_format($summary['to_pick'])"
                               :note="translate('opened_and_not_yet_picked')" />
                    <x-sc.stat :label="translate('to_pack')" :value="number_format($summary['to_pack'])"
                               :note="translate('picked_and_waiting_to_be_packed')" />
                    <x-sc.stat :label="translate('ready_to_hand_over')" :value="number_format($summary['ready'])"
                               :note="translate('packed_and_waiting_for_a_carrier')" />
                    {{-- The figure this whole area was missing. The timestamps were always written
                         and nothing ever subtracted them, so a marketplace that suspends sellers for
                         lateness could not show a seller which of their orders was late. --}}
                    <x-sc.stat :label="translate('stalled')" :value="number_format($summary['late'])"
                               :tone="$summary['late'] > 0 ? 'critical' : null"
                               :note="Copy::line('no_movement_for_over_n_hours', ['count' => $list->silenceHours()])" />
                </div>

                @if ($screen !== 'exceptions' && $summary['late'] > 0)
                    <x-sc.alert tone="critical" class="mt-3"
                                :title="Copy::line('n_fulfilments_have_stalled', ['count' => $summary['late']])">
                        {{ translate('the_marketplace_measures_lateness_from_the_last_thing_that_happened_not_from_when_the_order_was_placed') }}
                        <x-slot:action>
                            <x-sc.button variant="secondary" size="sm" :href="route('seller.shipments.exceptions')">
                                {{ translate('see_them') }}
                            </x-sc.button>
                        </x-slot:action>
                    </x-sc.alert>
                @endif
            </div>

            <x-sc.toolbar :count="Copy::line('n_fulfilments', ['count' => $fulfilments->total()])"
                          :search-url="route($meta['route'])"
                          :search-value="request('q', '')"
                          :search-placeholder="translate('reference_order_or_tracking')"
                          :chips="$filters->chips()"
                          :clear-url="$filters->urlClearAll()" />

            <x-sc.table :columns="$columns" :state="$state">
                <x-slot:empty>
                    <x-sc.empty glyph="package"
                                :title="$screen === 'exceptions' ? translate('nothing_has_stalled') : translate('no_fulfilment_work_right_now')"
                                :text="$screen === 'exceptions'
                                    ? translate('every_open_fulfilment_has_moved_within_the_marketplaces_window')
                                    : translate('a_fulfilment_opens_when_an_order_is_ready_to_be_picked')" />
                </x-slot:empty>
                <x-slot:noResults>
                    <x-sc.empty glyph="funnel" :title="translate('no_fulfilments_match_these_filters')"
                                :text="translate('adjust_or_clear_the_filters_to_see_more')" />
                </x-slot:noResults>

                @foreach ($fulfilments as $fulfilment)
                    @php($waiting = $list->hoursSinceLastMove($fulfilment))
                    @php($dispatch = $list->dispatchHours($fulfilment))
                    @php($late = $list->isLate($fulfilment))
                    <x-sc.tr :id="$fulfilment->id">
                        <x-sc.td class="sc-code">{{ $fulfilment->reference }}</x-sc.td>
                        <x-sc.td class="sc-code">
                            <a href="{{ route('seller.orders.show', ['order' => $fulfilment->order_id]) }}">#{{ $fulfilment->order_id }}</a>
                        </x-sc.td>
                        <x-sc.td><x-sc.badge :status="$fulfilment->status" /></x-sc.td>
                        <x-sc.td num :tone="$late ? 'critical' : null">
                            {{ Copy::line('n_hours', ['count' => number_format($waiting, 1)]) }}
                        </x-sc.td>
                        {{-- Blank while it is still open, never zero: a fulfilment that has not
                             shipped has no dispatch time, and zero would read as instant. --}}
                        <x-sc.td num drop="md" class="sc-muted">
                            {{ $dispatch === null ? '—' : Copy::line('n_hours', ['count' => number_format($dispatch, 1)]) }}
                        </x-sc.td>
                        <x-sc.td drop="lg" class="sc-muted">{{ $fulfilment->carrier ?: '—' }}</x-sc.td>
                        <x-sc.td action>
                            @if (isset($nextStatus[$fulfilment->status]))
                                <form method="POST" action="{{ route('seller.shipments.advance', ['fulfilment' => $fulfilment->id]) }}">
                                    @csrf
                                    <input type="hidden" name="to" value="{{ $nextStatus[$fulfilment->status] }}">
                                    <button type="submit" class="sc-btn sc-btn--ghost sc-btn--sm">
                                        {{ translate('mark_' . $nextStatus[$fulfilment->status]) }}
                                    </button>
                                </form>
                            @else
                                <span class="sc-muted">—</span>
                            @endif
                        </x-sc.td>
                    </x-sc.tr>
                @endforeach

                <x-slot:mobile>
                    @foreach ($fulfilments as $fulfilment)
                        <x-sc.entity-card :title="$fulfilment->reference"
                                          :href="route('seller.orders.show', ['order' => $fulfilment->order_id])"
                                          :figure="Copy::line('n_hours', ['count' => number_format($list->hoursSinceLastMove($fulfilment), 1)])"
                                          :meta="'#' . $fulfilment->order_id">
                            <div class="sc-row"><x-sc.badge :status="$fulfilment->status" /></div>
                        </x-sc.entity-card>
                    @endforeach
                </x-slot:mobile>

                <x-slot:footer><x-sc.pager :paginator="$fulfilments" /></x-slot:footer>
            </x-sc.table>
        </div>
    @endif
@endsection
