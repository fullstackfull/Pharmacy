@extends('layouts.seller.app')

@section('title', translate('nav_exports'))

@php
    use App\Services\SellerCenter\Copy;

    $period = ['date_type' => $window->type, 'from' => $window->from->toDateString(), 'to' => $window->to->toDateString()];

    $exports = [
        [
            'title' => translate('nav_order_report'),
            'text' => translate('every_order_in_the_period_with_its_amounts_discounts_and_commission'),
            'glyph' => 'receipt',
            'windowed' => true,
            'files' => [
                ['label' => translate('excel'), 'href' => route('seller.exports.orders', $period), 'icon' => 'file-xls'],
                ['label' => translate('pdf'), 'href' => route('seller.exports.orders-pdf', $period), 'icon' => 'file-pdf'],
            ],
        ],
        [
            'title' => translate('nav_product_report'),
            'text' => translate('products_listed_in_the_period_with_what_each_has_sold'),
            'glyph' => 'package',
            'windowed' => true,
            'files' => [
                ['label' => translate('excel'), 'href' => route('seller.exports.products', $period), 'icon' => 'file-xls'],
            ],
        ],
        [
            'title' => translate('nav_stock_report'),
            'text' => translate('current_stock_for_every_physical_product_lowest_first'),
            'glyph' => 'warehouse',
            'windowed' => false,
            'files' => [
                ['label' => translate('excel'), 'href' => route('seller.exports.stock'), 'icon' => 'file-xls'],
            ],
        ],
    ];
@endphp

@section('content')
    <x-sc.page-header :eyebrow="translate('nav_platform')" :title="translate('everything_you_can_take_with_you')"
                      :sub="translate('produced_by_the_same_exporters_the_app_uses_so_two_downloads_are_one_spreadsheet')">
        <x-slot:actions>
            <x-sc.button variant="secondary" :href="route('seller.reports.index', $period)">{{ translate('nav_reports') }}</x-sc.button>
        </x-slot:actions>
    </x-sc.page-header>

    <div class="sc-scroll">
        <div class="sc-page">
            @include('seller-views.reports._period', ['action' => route('seller.exports.index'), 'periods' => $periods])

            <div class="sc-grid-cards mt-3">
                @foreach ($exports as $export)
                    <x-sc.card :title="$export['title']">
                        <p>{{ $export['text'] }}</p>
                        <p class="sc-muted">
                            {{-- Said on every card, because a spreadsheet with the wrong dates in it
                                 is indistinguishable from a correct one until somebody acts on it. --}}
                            {{ $export['windowed']
                                ? Copy::line('covering_x_to_y', ['from' => $window->from->toDateString(), 'to' => $window->to->toDateString()])
                                : translate('stock_carries_no_period_a_level_is_what_it_is_now') }}
                        </p>
                        <div class="sc-row">
                            @foreach ($export['files'] as $file)
                                <x-sc.button variant="secondary" size="sm" :icon="$file['icon']" :href="$file['href']">
                                    {{ $file['label'] }}
                                </x-sc.button>
                            @endforeach
                        </div>
                    </x-sc.card>
                @endforeach
            </div>

            <x-sc.alert tone="info" class="mt-3" :title="translate('nothing_is_queued_and_nothing_is_kept')">
                {{ translate('a_generated_file_left_on_the_server_is_a_copy_of_your_commercial_data_sitting_where_nobody_is_watching_these_stream_and_are_gone') }}
            </x-sc.alert>
        </div>
    </div>
@endsection
