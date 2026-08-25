@extends('layouts.seller.app')

@section('title', translate('nav_stock_report'))

@php
    use App\Services\SellerCenter\Copy;

    $columns = [
        ['key' => 'product', 'label' => translate('product')],
        ['key' => 'sku', 'label' => translate('sku'), 'width' => 150, 'priority' => 'md'],
        ['key' => 'stock', 'label' => translate('in_stock'), 'width' => 120, 'num' => true],
        ['key' => 'state', 'label' => translate('standing'), 'width' => 160],
        ['key' => 'price', 'label' => translate('price'), 'width' => 120, 'num' => true],
    ];
@endphp

@section('content')
    <x-sc.page-header :eyebrow="translate('nav_reports')" :title="translate('nav_stock_report')"
                      :sub="Copy::line('low_is_anything_at_or_below_x_units', ['limit' => $limit])"
                      :back="route('seller.reports.index')">
        <x-slot:actions>
            <x-sc.button variant="secondary" icon="file-xls" :href="route('seller.exports.stock')">{{ translate('excel') }}</x-sc.button>
        </x-slot:actions>
    </x-sc.page-header>

    <div class="sc-scroll">
        <div class="sc-page">
            {{-- No period picker here, deliberately. A stock level is a fact about now; asking what
                 it was in March would need the movement ledger replayed backwards, and printing
                 today's figure under a March heading would be false. --}}
            <form method="GET" class="sc-form-row" action="{{ route('seller.reports.stock') }}">
                <x-sc.field :label="translate('category')">
                    <x-sc.select name="category_id" :value="$currentCategory"
                                 :placeholder="translate('every_category')"
                                 :options="$categories->map(fn ($category) => ['value' => $category->id, 'label' => $category->name])->all()" />
                </x-sc.field>

                <x-sc.field :label="translate('order_by')">
                    <x-sc.select name="sort" :value="$sort" :options="[
                        ['value' => 'ASC', 'label' => translate('lowest_stock_first')],
                        ['value' => 'DESC', 'label' => translate('highest_stock_first')],
                    ]" />
                </x-sc.field>

                <div class="sc-form-footer">
                    <x-sc.button variant="secondary" type="submit">{{ translate('apply') }}</x-sc.button>
                </div>
            </form>

            <x-sc.toolbar class="mt-3" :count="$products->total()" :search-url="route('seller.reports.stock')"
                          :search-value="$search" :search-placeholder="translate('search_products')" />

            <x-sc.table :columns="$columns" :state="$state">
                <x-slot:empty>
                    <x-sc.empty glyph="package" :title="translate('no_physical_products_to_count')"
                                :text="translate('the_stock_report_covers_physical_products_a_digital_one_has_no_level')" />
                </x-slot:empty>
                <x-slot:noResults>
                    <x-sc.empty glyph="funnel" :title="translate('no_products_match_these_filters')"
                                :text="translate('adjust_or_clear_the_filters_to_see_more')" />
                </x-slot:noResults>

                @foreach ($products as $product)
                    <x-sc.tr :href="url('vendor/products/update/' . $product->id)" :id="$product->id">
                        <x-sc.td>{{ $product->getRawOriginal('name') }}</x-sc.td>
                        <x-sc.td><span class="sc-code">{{ $product->code ?? '—' }}</span></x-sc.td>
                        <x-sc.td num :tone="(int) $product->current_stock <= $limit ? 'critical' : null">
                            {{ number_format((int) $product->current_stock) }}
                        </x-sc.td>
                        <x-sc.td>
                            @if ((int) $product->current_stock <= 0)
                                <x-sc.badge status="out_of_stock" />
                            @elseif ((int) $product->current_stock <= $limit)
                                <x-sc.badge status="low_stock" />
                            @else
                                <x-sc.badge status="in_stock" />
                            @endif
                        </x-sc.td>
                        <x-sc.td num>{{ number_format((float) $product->unit_price, 2) }}</x-sc.td>
                    </x-sc.tr>
                @endforeach
            </x-sc.table>

            <x-sc.pager :paginator="$products" />
        </div>
    </div>
@endsection
