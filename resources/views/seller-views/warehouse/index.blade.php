@extends('layouts.seller.app')

@section('title', translate('nav_warehouse'))

@php
    use App\Services\SellerCenter\Copy;

    $columns = array_merge(
        [
            ['key' => 'sku', 'label' => translate('sku'), 'width' => 130],
            ['key' => 'product', 'label' => translate('product')],
            ['key' => 'total', 'label' => translate('on_hand'), 'width' => 90, 'num' => true],
        ],
        $warehouses->map(fn ($warehouse) => [
            'key' => 'w' . $warehouse->id,
            'label' => $warehouse->name,
            'width' => 110,
            'num' => true,
        ])->all(),
        [['key' => 'unallocated', 'label' => translate('unallocated'), 'width' => 120, 'num' => true]],
    );
@endphp

@section('content')
    <x-sc.page-header :eyebrow="translate('nav_inventory')" :title="translate('where_your_stock_is')"
                      :sub="translate('current_stock_says_how_much_you_have_this_says_where_it_is')" />

    @if (!$available)
        <div class="sc-scroll"><div class="sc-page">
            <x-sc.empty glyph="warning" :title="translate('warehouses_are_not_available_on_this_installation')"
                        :text="translate('the_warehouse_tables_have_not_been_created_ask_the_marketplace_to_run_its_migrations')" />
        </div></div>
    @elseif ($warehouses->isEmpty())
        <div class="sc-scroll"><div class="sc-page">
            <x-sc.empty glyph="buildings" :title="translate('you_have_no_locations_yet')"
                        :text="translate('with_one_location_every_unit_is_simply_in_stock_add_a_second_and_this_screen_tells_you_which_one_to_pick_from')" />
        </div></div>
    @else
        <div class="sc-scroll">
            <div class="sc-page" style="padding-bottom:0">
                <div class="sc-stats">
                    @foreach ($warehouses as $warehouse)
                        <x-sc.stat :label="$warehouse->name"
                                   :value="number_format($held[$warehouse->id] ?? 0)"
                                   :note="$warehouse->is_default ? translate('default_location') : ($warehouse->code ?: translate('units_held_here'))" />
                    @endforeach
                </div>

                {{-- The invariant the service enforces, said out loud: every operation preserves
                     current_stock and only partitions it, so placed plus unallocated always equals
                     what the shop has. Showing the remainder is what makes that checkable rather
                     than something a seller has to take on trust. --}}
                <x-sc.alert tone="info" compact class="mt-3">
                    {{ translate('units_placed_in_a_location_plus_units_unallocated_always_equal_what_you_have_moving_stock_between_locations_never_changes_the_total') }}
                </x-sc.alert>
            </div>

            <x-sc.toolbar :count="Copy::line('n_skus', ['count' => $products->count()])"
                          :search-url="route('seller.warehouse.index')"
                          :search-value="$search"
                          :search-placeholder="translate('name_or_sku')" />

            <x-sc.table :columns="$columns" :state="$state" :cards="false">
                <x-slot:empty>
                    <x-sc.empty glyph="stack" :title="translate('no_physical_products_to_place')"
                                :text="translate('only_physical_products_occupy_a_location')" />
                </x-slot:empty>
                <x-slot:noResults>
                    <x-sc.empty glyph="funnel" :title="translate('no_products_match_this_search')"
                                :text="translate('adjust_or_clear_the_filters_to_see_more')" />
                </x-slot:noResults>

                @foreach ($products as $product)
                    @php($placed = array_sum($placement[$product->id] ?? []))
                    @php($unallocated = max(0, (int) $product->current_stock - $placed))
                    <x-sc.tr :href="url('vendor/products/update/' . $product->id)" :id="$product->id">
                        <x-sc.td class="sc-code">{{ $product->code ?: '—' }}</x-sc.td>
                        <x-sc.td>{{ $product->getRawOriginal('name') }}</x-sc.td>
                        <x-sc.td num>{{ number_format((int) $product->current_stock) }}</x-sc.td>
                        @foreach ($warehouses as $warehouse)
                            @php($units = $placement[$product->id][$warehouse->id] ?? 0)
                            <x-sc.td num class="{{ $units > 0 ? '' : 'sc-muted' }}">{{ $units > 0 ? number_format($units) : '—' }}</x-sc.td>
                        @endforeach
                        {{-- Not an error and not a warning: stock that has not been assigned to a
                             location is still sellable. It is only worth noticing when a shop
                             believes it has placed everything. --}}
                        <x-sc.td num class="sc-muted">{{ number_format($unallocated) }}</x-sc.td>
                    </x-sc.tr>
                @endforeach

                <x-slot:footer>
                    <p class="sc-muted" style="padding:8px 12px">
                        {{ Copy::line('showing_the_first_n_products_by_name', ['count' => $products->count()]) }}
                    </p>
                </x-slot:footer>
            </x-sc.table>
        </div>
    @endif
@endsection
