@extends('layouts.seller.app')

@section('title', translate('nav_brand_registry'))

@php
    use App\Models\BrandClaim;
    use App\Services\SellerCenter\Copy;

    $columns = [
        ['key' => 'brand', 'label' => translate('brand')],
        ['key' => 'claim_type', 'label' => translate('claim_type'), 'width' => 160],
        ['key' => 'status', 'label' => translate('status'), 'width' => 150],
        ['key' => 'documents', 'label' => translate('documents'), 'width' => 100, 'num' => true],
        ['key' => 'expires', 'label' => translate('expires'), 'width' => 120],
        ['key' => 'submitted', 'label' => translate('submitted'), 'width' => 120, 'priority' => 'md'],
    ];
@endphp

@section('content')
    <x-sc.page-header :eyebrow="translate('nav_trust')" :title="translate('brands_this_shop_may_sell_under')"
                      :sub="translate('a_claim_is_approved_by_a_person_reading_documents_never_by_time_passing')">
        <x-slot:actions>
            <x-sc.button variant="secondary" :href="route('seller.brands.protection')">{{ translate('nav_brand_protection') }}</x-sc.button>
        </x-slot:actions>
    </x-sc.page-header>

    <div class="sc-scroll">
        <div class="sc-page">
            @unless ($available)
                <x-sc.alert tone="info" :title="translate('the_brand_registry_is_not_running_on_this_marketplace')">
                    {{ translate('nothing_is_being_withheld_there_is_no_registry_to_read') }}
                </x-sc.alert>
            @else
                @unless ($enforcing)
                    {{-- Enforcement off changes what every row on this screen means: a claim is a
                         record rather than a permission, and saying so is the difference between an
                         urgent screen and an accurate one. --}}
                    <x-sc.alert tone="info" :title="translate('brand_enforcement_is_off_on_this_marketplace_today')">
                        {{ translate('claims_are_still_recorded_and_reviewed_and_will_apply_the_day_enforcement_is_turned_on') }}
                    </x-sc.alert>
                @endunless

                <x-sc.tabs class="mt-3" :current="$currentView" :tabs="[
                    ['key' => 'all', 'label' => translate('all_claims'), 'href' => route('seller.brands.index')],
                    ['key' => 'authorization', 'label' => translate('current_authorisations'), 'href' => route('seller.brands.index', ['view' => 'authorization'])],
                ]" />

                <x-sc.table class="mt-3" :state="$state" :columns="$columns">
                    <x-slot:empty>
                        <x-sc.empty glyph="seal-check" :title="translate('you_hold_no_brand_claims')"
                                    :text="translate('a_claim_is_needed_only_for_a_brand_you_do_not_own_outright')" />
                    </x-slot:empty>
                    <x-slot:noResults>
                        <x-sc.empty glyph="seal-check" :title="translate('no_current_authorisation')"
                                    :text="translate('an_authorisation_is_an_approved_claim_that_has_not_expired')" />
                    </x-slot:noResults>

                    @foreach ($claims as $claim)
                        <x-sc.tr :id="$claim->id">
                            <x-sc.td>{{ $claim->brand?->name ?? Copy::line('brand_n', ['id' => $claim->brand_id]) }}</x-sc.td>
                            <x-sc.td>{{ translate($claim->claim_type) }}</x-sc.td>
                            <x-sc.td>
                                <x-sc.badge :status="$claim->status" />
                                @if ($claim->status === BrandClaim::STATUS_APPROVED && !$claim->entitles())
                                    {{-- Approved and expired is the state that costs listings, and
                                         it reads as "approved" everywhere it is not spelled out. --}}
                                    <x-sc.badge status="expired" />
                                @endif
                            </x-sc.td>
                            <x-sc.td num>{{ number_format($claim->documents->count()) }}</x-sc.td>
                            <x-sc.td>{{ $claim->expires_at?->format('Y-m-d') ?? translate('no_expiry') }}</x-sc.td>
                            <x-sc.td>{{ $claim->submitted_at?->format('Y-m-d') ?? '—' }}</x-sc.td>
                        </x-sc.tr>
                    @endforeach
                </x-sc.table>
            @endunless
        </div>
    </div>
@endsection
