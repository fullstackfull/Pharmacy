@extends('layouts.seller.app')

@section('title', translate('price_history'))

@php
    use App\Services\SellerCenter\Copy;

    $columns = [
        ['key' => 'when', 'label' => translate('when'), 'width' => 130],
        ['key' => 'product', 'label' => translate('product')],
        ['key' => 'from', 'label' => translate('from'), 'width' => 110, 'num' => true],
        ['key' => 'to', 'label' => translate('to'), 'width' => 110, 'num' => true],
        ['key' => 'source', 'label' => translate('changed_by'), 'width' => 150],
        ['key' => 'who', 'label' => translate('who'), 'width' => 150, 'priority' => 'lg'],
    ];

    $tabs = collect(array_merge(['all'], $sources))->map(fn ($key) => [
        'key' => $key,
        'label' => translate($key),
        'href' => $key === 'all' ? route('seller.pricing.history') : route('seller.pricing.history', ['source' => $key]),
    ])->values()->all();
@endphp

@section('content')
    <x-sc.page-header :eyebrow="translate('nav_pricing')" :title="translate('price_history')"
                      :sub="translate('who_moved_this_price_and_when_on_a_catalogue_several_people_and_three_automations_can_write_to')"
                      :back="route('seller.pricing.index')" />

    @if (!$available)
        <div class="sc-scroll"><div class="sc-page">
            <x-sc.empty glyph="warning" :title="translate('price_history_is_not_available_on_this_installation')"
                        :text="translate('the_price_change_table_has_not_been_created_ask_the_marketplace_to_run_its_migrations')" />
        </div></div>
    @else
        <x-sc.tabs :tabs="$tabs" :current="$source ?? 'all'" />

        <div class="sc-scroll">
            <x-sc.toolbar :count="Copy::line('n_price_changes', ['count' => $changes->total()])" />

            <x-sc.table :columns="$columns" :state="$state">
                <x-slot:empty>
                    <x-sc.empty glyph="tag" :title="translate('no_price_has_moved_yet')"
                                :text="translate('every_change_is_recorded_here_whoever_or_whatever_made_it')" />
                </x-slot:empty>
                <x-slot:noResults>
                    <x-sc.empty glyph="funnel" :title="translate('no_changes_match_these_filters')"
                                :text="translate('adjust_or_clear_the_filters_to_see_more')" />
                </x-slot:noResults>

                @foreach ($changes as $change)
                    <x-sc.tr :id="$change->id">
                        <x-sc.td class="sc-muted">{{ $change->created_at?->format('Y-m-d H:i') }}</x-sc.td>
                        <x-sc.td>{{ $names[$change->product_id] ?? translate('product_no_longer_listed') }}</x-sc.td>
                        {{-- A first listing is not a change: there was no price before it, and
                             rendering a zero there would read as a product given away. --}}
                        <x-sc.td num class="sc-muted">
                            {{ $change->isFirstPrice() ? '—' : number_format((float) $change->previous_price, 2) }}
                        </x-sc.td>
                        <x-sc.td num :tone="!$change->isFirstPrice() && $change->new_price < $change->previous_price ? 'high' : null">
                            {{ number_format((float) $change->new_price, 2) }}
                        </x-sc.td>
                        <x-sc.td>{{ translate($change->source) }}</x-sc.td>
                        <x-sc.td drop="lg" class="sc-muted">{{ $change->actor_name ?: '—' }}</x-sc.td>
                    </x-sc.tr>
                @endforeach

                <x-slot:mobile>
                    @foreach ($changes as $change)
                        <x-sc.entity-card :title="$names[$change->product_id] ?? translate('product_no_longer_listed')"
                                          :figure="number_format((float) $change->new_price, 2)"
                                          :meta="translate($change->source) . ' · ' . $change->created_at?->format('Y-m-d')" />
                    @endforeach
                </x-slot:mobile>

                <x-slot:footer><x-sc.pager :paginator="$changes" /></x-slot:footer>
            </x-sc.table>
        </div>
    @endif
@endsection
