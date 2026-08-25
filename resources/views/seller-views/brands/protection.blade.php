@extends('layouts.seller.app')

@section('title', translate('nav_brand_protection'))

@php
    use App\Services\SellerCenter\Copy;

    $columns = [
        ['key' => 'brand', 'label' => translate('brand')],
        ['key' => 'products', 'label' => translate('listings'), 'width' => 110, 'num' => true],
        ['key' => 'claim', 'label' => translate('your_claim'), 'width' => 150],
        ['key' => 'standing', 'label' => translate('standing'), 'width' => 170],
    ];

    $rows = collect($exposure);
    $atRisk = $rows->reject(fn ($row) => $row['may_list'])->values();
    $exposedListings = (int) $atRisk->sum('products');
@endphp

@section('content')
    <x-sc.page-header :eyebrow="translate('nav_trust')" :title="translate('what_a_revocation_would_cost')"
                      :sub="translate('counted_in_listings_from_your_own_catalogue_not_described_in_the_abstract')"
                      :back="route('seller.brands.index')" />

    <div class="sc-scroll">
        <div class="sc-page">
            @unless ($available)
                <x-sc.alert tone="info" :title="translate('the_brand_registry_is_not_running_on_this_marketplace')">
                    {{ translate('nothing_is_being_withheld_there_is_no_registry_to_read') }}
                </x-sc.alert>
            @else
                <div class="sc-stats">
                    <x-sc.stat :label="translate('brands_you_list_under')" :value="number_format($rows->count())"
                               :note="translate('counted_from_the_products_in_your_catalogue')" />
                    <x-sc.stat :label="translate('brands_you_are_not_authorised_for')" :value="number_format($atRisk->count())"
                               :tone="$atRisk->isNotEmpty() && $enforcing ? 'critical' : null"
                               :note="$enforcing ? translate('enforcement_is_on') : translate('enforcement_is_off')" />
                    {{-- The figure that turns a policy question into an operational one: not how
                         many brands are unclaimed, but how many listings stand on them. --}}
                    <x-sc.stat :label="translate('listings_that_would_be_affected')" :value="number_format($exposedListings)"
                               :tone="$exposedListings > 0 && $enforcing ? 'critical' : null"
                               :note="translate('the_listings_sitting_under_those_brands')" />
                </div>

                @if ($atRisk->isNotEmpty() && !$enforcing)
                    <x-sc.alert tone="info" class="mt-3" :title="translate('brand_enforcement_is_off_on_this_marketplace_today')">
                        {{ translate('these_listings_are_not_at_risk_today_this_is_what_would_be_at_risk_if_enforcement_were_turned_on') }}
                    </x-sc.alert>
                @endif

                <x-sc.table class="mt-3" :columns="$columns" :state="$rows->isEmpty() ? 'empty' : 'normal'">
                    <x-slot:empty>
                        <x-sc.empty glyph="tag" :title="translate('none_of_your_listings_carry_a_brand')"
                                    :text="translate('brand_exposure_is_counted_from_the_brand_set_on_each_product')" />
                    </x-slot:empty>

                    @foreach ($rows as $row)
                        <x-sc.tr :id="$row['brand_id']">
                            <x-sc.td>{{ $row['brand_name'] ?? Copy::line('brand_n', ['id' => $row['brand_id']]) }}</x-sc.td>
                            <x-sc.td num>{{ number_format($row['products']) }}</x-sc.td>
                            <x-sc.td>
                                @if ($row['claim_status'] === null)
                                    <span class="sc-muted">{{ translate('no_claim') }}</span>
                                @else
                                    <x-sc.badge :status="$row['claim_status']" />
                                @endif
                            </x-sc.td>
                            <x-sc.td :tone="$row['may_list'] ? null : 'critical'">
                                {{ $row['may_list'] ? translate('you_may_list_under_this_brand') : translate('you_may_not_list_under_this_brand') }}
                            </x-sc.td>
                        </x-sc.tr>
                    @endforeach
                </x-sc.table>
            @endunless
        </div>
    </div>
@endsection
