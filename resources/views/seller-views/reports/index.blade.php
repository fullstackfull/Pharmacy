@extends('layouts.seller.app')

@section('title', translate('nav_reports'))

@php
    use App\Services\SellerCenter\Copy;
@endphp

@section('content')
    <x-sc.page-header :eyebrow="translate('nav_platform')" :title="translate('what_this_shop_did')"
                      :sub="translate('three_reports_under_one_period_so_they_can_be_read_against_each_other')">
        <x-slot:actions>
            <x-sc.button variant="secondary" icon="download-simple"
                         :href="route('seller.exports.index', ['date_type' => $window->type, 'from' => $window->from->toDateString(), 'to' => $window->to->toDateString()])">
                {{ translate('nav_exports') }}
            </x-sc.button>
        </x-slot:actions>
    </x-sc.page-header>

    <div class="sc-scroll">
        <div class="sc-page">
            @include('seller-views.reports._period', ['action' => route('seller.reports.index')])

            <p class="sc-muted">{{ Copy::line('covering_x_to_y', [
                'from' => $window->from->toDateString(),
                'to' => $window->to->toDateString(),
            ]) }}</p>

            <div class="sc-grid-two mt-3">
                <x-sc.card :title="translate('orders')">
                    <x-slot:context>
                        <a href="{{ route('seller.reports.orders', request()->only(['date_type', 'from', 'to'])) }}">{{ translate('see_all') }}</a>
                    </x-slot:context>

                    <div class="sc-stats">
                        <x-sc.stat :label="translate('delivered')" :value="number_format($orders['counts']['delivered'])" />
                        <x-sc.stat :label="translate('still_moving')" :value="number_format($orders['counts']['ongoing'])" />
                        <x-sc.stat :label="translate('cancelled_or_returned')" :value="number_format($orders['counts']['canceled'])" />
                    </div>

                    {{-- Due is everything not yet resolved either way. An order that was cancelled is
                         not owed, and counting it would overstate what is coming. --}}
                    <x-sc.info :label="translate('settled')" :value="number_format($orders['amounts']['settled'], 2)" />
                    <x-sc.info :label="translate('still_due')" :value="number_format($orders['amounts']['due'], 2)" />

                    @if (array_sum($orders['chart']) > 0)
                        <x-sc.chart-line :series="array_values($orders['chart'])" :labels="$orders['chart_labels']" />
                    @else
                        <x-sc.empty glyph="chart-line" :title="translate('nothing_delivered_in_this_period')"
                                    :text="translate('the_chart_plots_delivered_orders_only')" />
                    @endif
                </x-sc.card>

                <x-sc.card :title="translate('products')">
                    <x-slot:context>
                        <a href="{{ route('seller.reports.products', request()->only(['date_type', 'from', 'to'])) }}">{{ translate('see_all') }}</a>
                    </x-slot:context>

                    <div class="sc-stats">
                        <x-sc.stat :label="translate('active')" :value="number_format($products['counts']['active'])" />
                        <x-sc.stat :label="translate('awaiting_approval')" :value="number_format($products['counts']['pending'])" />
                        <x-sc.stat :label="translate('rejected')" :value="number_format($products['counts']['rejected'])" />
                    </div>

                    <x-sc.info :label="translate('units_sold')" :value="number_format($products['totals']['sold_quantity'])" />
                    <x-sc.info :label="translate('sold_for')" :value="number_format($products['totals']['sold_amount'], 2)" />
                    <x-sc.info :label="translate('discount_given')" :value="number_format($products['totals']['discount_given'], 2)" />
                </x-sc.card>
            </div>

            <x-sc.card class="mt-3" :title="translate('how_you_were_paid')">
                {{-- The order row records how the first payment arrived; anything added by an order
                     edit lands in the edit history with its own method, and a returned amount lands
                     there too. Reading only the order row understates every edited order. --}}
                @foreach ($orders['payments'] as $method => $amount)
                    <x-sc.info :label="translate($method)" :value="number_format((float) $amount, 2)" />
                @endforeach
            </x-sc.card>

            <x-sc.card class="mt-3" :title="translate('stock')">
                <x-slot:context>
                    <a href="{{ route('seller.reports.stock') }}">{{ translate('see_all') }}</a>
                </x-slot:context>
                {{-- Stock is the one report with no period: a level is what it is now, and showing
                     today's figure under a March heading would be false. --}}
                <p class="sc-muted">{{ translate('stock_carries_no_period_a_level_is_what_it_is_now') }}</p>
            </x-sc.card>
        </div>
    </div>
@endsection
