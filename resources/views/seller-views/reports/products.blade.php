@extends('layouts.seller.app')

@section('title', translate('nav_product_report'))

@php
    use App\Services\SellerCenter\Copy;

    $columns = [
        ['key' => 'product', 'label' => translate('product')],
        ['key' => 'listed', 'label' => translate('listed'), 'width' => 130, 'priority' => 'md'],
        ['key' => 'status', 'label' => translate('status'), 'width' => 150],
        ['key' => 'sold', 'label' => translate('units_sold'), 'width' => 120, 'num' => true],
        ['key' => 'price', 'label' => translate('price'), 'width' => 120, 'num' => true],
    ];

    $period = request()->only(['date_type', 'from', 'to']);
@endphp

@section('content')
    <x-sc.page-header :eyebrow="translate('nav_reports')" :title="translate('nav_product_report')"
                      :sub="translate('what_is_listed_what_sold_and_what_it_earned')"
                      :back="route('seller.reports.index')">
        <x-slot:actions>
            <x-sc.button variant="secondary" icon="file-xls" :href="route('seller.exports.products', $period)">{{ translate('excel') }}</x-sc.button>
        </x-slot:actions>
    </x-sc.page-header>

    <div class="sc-scroll">
        <div class="sc-page">
            @include('seller-views.reports._period', ['action' => route('seller.reports.products')])

            <div class="sc-stats mt-3">
                <x-sc.stat :label="translate('active')" :value="number_format($report['counts']['active'])" />
                <x-sc.stat :label="translate('awaiting_approval')" :value="number_format($report['counts']['pending'])" />
                <x-sc.stat :label="translate('units_sold')" :value="number_format($report['totals']['sold_quantity'])" />
                <x-sc.stat :label="translate('sold_for')" :value="number_format($report['totals']['sold_amount'], 2)"
                           :note="Copy::line('less_x_in_discount', ['amount' => number_format($report['totals']['discount_given'], 2)])" />
            </div>

            <x-sc.toolbar class="mt-3" :count="$products->total()" :search-url="route('seller.reports.products')"
                          :search-value="$search" :search-placeholder="translate('search_products')" />

            <x-sc.table :columns="$columns" :state="$state">
                <x-slot:empty>
                    <x-sc.empty glyph="package" :title="translate('nothing_was_listed_in_this_period')"
                                :text="translate('the_period_filters_on_when_a_product_was_listed_not_on_when_it_sold')" />
                </x-slot:empty>
                <x-slot:noResults>
                    <x-sc.empty glyph="funnel" :title="translate('no_products_match_that_search')"
                                :text="translate('adjust_or_clear_the_filters_to_see_more')" />
                </x-slot:noResults>

                @foreach ($products as $product)
                    <x-sc.tr :href="url('vendor/products/update/' . $product->id)" :id="$product->id">
                        <x-sc.td>{{ $product->getRawOriginal('name') }}</x-sc.td>
                        <x-sc.td>{{ $product->created_at?->format('Y-m-d') ?? '—' }}</x-sc.td>
                        <x-sc.td>
                            <x-sc.badge :status="[0 => 'pending', 1 => 'approved', 2 => 'rejected'][$product->request_status] ?? 'pending'" />
                        </x-sc.td>
                        {{-- Summed on the eager-loaded, delivered-only rows: a listing with no
                             delivered lines has sold nothing, which is 0 rather than absent. --}}
                        <x-sc.td num>{{ number_format((float) ($product->orderDetails->first()->product_quantity ?? 0)) }}</x-sc.td>
                        <x-sc.td num>{{ number_format((float) $product->unit_price, 2) }}</x-sc.td>
                    </x-sc.tr>
                @endforeach
            </x-sc.table>

            <x-sc.pager :paginator="$products" />
        </div>
    </div>
@endsection
