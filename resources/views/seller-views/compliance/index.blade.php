@extends('layouts.seller.app')

@section('title', translate('nav_compliance'))

@php
    use App\Models\BrandClaim;
    use App\Services\SellerCenter\Copy;

    $atRisk = collect($exposure)->filter(fn ($row) => !$row['may_list'])->values();
@endphp

@section('content')
    <x-sc.page-header :eyebrow="translate('nav_trust')" :title="translate('everything_the_marketplace_could_act_on')"
                      :sub="translate('three_things_can_cost_a_shop_its_listings_and_they_are_read_together_for_the_first_time_here')" />

    <div class="sc-scroll">
        <div class="sc-page">
            @if ($atRisk->isNotEmpty() && $enforcing)
                {{-- The one alert with an immediate cost. Brand enforcement is on and these listings
                     sit under a brand this shop is not authorised for. --}}
                <x-sc.alert tone="critical"
                            :title="Copy::line('n_brands_you_are_not_authorised_for', ['count' => $atRisk->count()])">
                    {{ translate('brand_enforcement_is_on_listings_under_an_unauthorised_brand_can_be_taken_down') }}
                    <x-slot:action>
                        <x-sc.button variant="secondary" size="sm" :href="route('seller.brands.protection')">
                            {{ translate('see_what_is_exposed') }}
                        </x-sc.button>
                    </x-slot:action>
                </x-sc.alert>
            @endif

            <div class="sc-grid-two mt-3">
                <x-sc.card :title="translate('identity_verification')">
                    <x-sc.info :label="translate('status')"><x-sc.badge :status="$verification" /></x-sc.info>

                    @if ($documents->isEmpty())
                        <x-sc.empty glyph="identification-card" :title="translate('no_documents_on_file')"
                                    :text="translate('verification_gates_payouts_it_does_not_gate_selling')" />
                    @else
                        @foreach ($documents as $document)
                            <x-sc.info :label="translate($document->type)">
                                <x-sc.badge :status="$document->status" />
                                @if ($document->expires_at)
                                    {{-- The date is the whole point. "Non-compliant" without one is
                                         an accusation; with one it is a task. --}}
                                    <span class="sc-muted">{{ Copy::line('expires_on_x', ['date' => $document->expires_at->format('Y-m-d')]) }}</span>
                                @endif
                            </x-sc.info>
                        @endforeach
                    @endif
                </x-sc.card>

                <x-sc.card :title="translate('brand_authorisation')">
                    <x-slot:context>
                        <a href="{{ route('seller.brands.index') }}">{{ translate('see_all') }}</a>
                    </x-slot:context>

                    @if ($claims->isEmpty())
                        <x-sc.empty glyph="seal-check" :title="translate('you_hold_no_brand_claims')"
                                    :text="$enforcing
                                        ? translate('brand_enforcement_is_on_so_a_claim_is_needed_before_listing_under_a_brand')
                                        : translate('brand_enforcement_is_off_on_this_marketplace_today')" />
                    @else
                        @foreach ($claims as $claim)
                            <x-sc.info :label="$claim->brand?->name ?? Copy::line('brand_n', ['id' => $claim->brand_id])">
                                <x-sc.badge :status="$claim->status" />
                                @if ($claim->expires_at)
                                    <span class="sc-muted">{{ Copy::line('expires_on_x', ['date' => $claim->expires_at->format('Y-m-d')]) }}</span>
                                @endif
                            </x-sc.info>
                        @endforeach
                    @endif
                </x-sc.card>
            </div>

            <div class="sc-grid-two mt-3">
                <x-sc.card :title="translate('lines_you_are_currently_over')">
                    @if ($openBreaches->isEmpty())
                        <x-sc.empty glyph="check-circle" :title="translate('you_are_inside_every_line')"
                                    :text="translate('nothing_here_is_being_held_against_you_today')" />
                    @else
                        @foreach ($openBreaches as $breach)
                            <x-sc.info :label="translate($breach->metric)"
                                       :value="Copy::line('x_against_a_limit_of_y', [
                                           'actual' => number_format((float) $breach->actual_value, 4),
                                           'limit' => number_format((float) $breach->threshold, 4),
                                       ])" tone="critical" />
                        @endforeach
                        <x-sc.button variant="secondary" size="sm" class="mt-2" :href="route('seller.performance.sla')">
                            {{ translate('nav_sla') }}
                        </x-sc.button>
                    @endif
                </x-sc.card>

                <x-sc.card :title="translate('breaches_over_the_last_quarter')">
                    @if ($breachTrend === [])
                        <x-sc.empty glyph="chart-line" :title="translate('nothing_to_trend')"
                                    :text="translate('no_line_has_been_crossed_in_the_last_ninety_days')" />
                    @else
                        {{-- A count per month rather than a headline. A trend answers whether things
                             are getting better or worse; a single number does not. --}}
                        @foreach ($breachTrend as $month => $count)
                            <x-sc.info :label="$month" :value="number_format($count)" />
                        @endforeach
                    @endif
                </x-sc.card>
            </div>
        </div>
    </div>
@endsection
