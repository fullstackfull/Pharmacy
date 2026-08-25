@extends('layouts.seller.app')

@section('title', translate('nav_pricing'))

@php
    use App\Services\SellerCenter\Copy;
@endphp

@section('content')
    <x-sc.page-header :eyebrow="translate('nav_pricing')" :title="translate('your_price_floor')"
                      :sub="translate('the_lowest_you_are_prepared_to_go_and_what_happens_when_something_tries_to_go_lower')">
        <x-slot:actions>
            <x-sc.button variant="secondary" icon="list" :href="route('seller.pricing.history')">{{ translate('price_history') }}</x-sc.button>
        </x-slot:actions>
    </x-sc.page-header>

    <div class="sc-scroll">
        <div class="sc-page">
            @if (!$available)
                <x-sc.empty glyph="warning" :title="translate('the_price_floor_is_not_available_on_this_installation')"
                            :text="translate('the_pricing_policy_table_has_not_been_created_ask_the_marketplace_to_run_its_migrations')" />
            @else
                <div class="sc-grid-two">
                    <x-sc.card :title="translate('set_the_floor')">
                        <form method="POST" action="{{ route('seller.pricing.save') }}">
                            @csrf
                            <x-sc.field :label="translate('minimum_margin_percent')"
                                        :help="translate('your_share_after_the_marketplaces_commission_leave_blank_for_no_margin_rule')">
                                <x-sc.input type="number" step="0.01" min="0" max="100" name="min_margin_percent"
                                            :value="$policy?->min_margin_percent" />
                            </x-sc.field>

                            <x-sc.field :label="translate('minimum_price')"
                                        :help="translate('an_absolute_floor_whatever_the_margin_works_out_to')">
                                <x-sc.input type="number" step="0.01" min="0" name="min_price" :value="$policy?->min_price" />
                            </x-sc.field>

                            {{-- Advisory unless the seller enforces it. That choice belongs to them
                                 and is rendered as one: a marketplace deciding for a shop what it
                                 may not charge is a different product. --}}
                            <x-sc.field :label="translate('enforcement')"
                                        :help="translate('when_off_a_price_below_the_floor_is_flagged_and_still_saved_when_on_it_is_refused')">
                                <label class="sc-check">
                                    <input type="checkbox" name="enforce" value="1" @checked($policy?->enforce)>
                                    <span>{{ translate('refuse_prices_below_the_floor') }}</span>
                                </label>
                            </x-sc.field>

                            <x-sc.button type="submit" variant="primary" icon="check">{{ translate('save') }}</x-sc.button>
                        </form>
                    </x-sc.card>

                    <x-sc.card :title="translate('what_has_moved_recently')">
                        <x-slot:context>
                            <a href="{{ route('seller.pricing.history') }}">{{ translate('see_all') }}</a>
                        </x-slot:context>

                        @if ($recent->isEmpty())
                            <x-sc.empty glyph="tag" :title="translate('no_price_has_moved_yet')"
                                        :text="translate('every_change_is_recorded_here_whoever_or_whatever_made_it')" />
                        @else
                            {{-- Beside the form on purpose: a threshold chosen against nothing is a
                                 threshold chosen at random. --}}
                            <x-sc.timeline>
                                @foreach ($recent as $change)
                                    <x-sc.timeline-item :tone="$change->new_price < $change->previous_price ? 'high' : 'neutral'"
                                                        :time="$change->created_at?->format('Y-m-d')"
                                                        :meta="translate($change->source)">
                                        <strong>{{ $names[$change->product_id] ?? translate('product_no_longer_listed') }}</strong>
                                        —
                                        @if ($change->isFirstPrice())
                                            {{ Copy::line('first_listed_at_x', ['value' => number_format((float) $change->new_price, 2)]) }}
                                        @else
                                            {{ number_format((float) $change->previous_price, 2) }}
                                            →
                                            {{ number_format((float) $change->new_price, 2) }}
                                        @endif
                                    </x-sc.timeline-item>
                                @endforeach
                            </x-sc.timeline>
                        @endif
                    </x-sc.card>
                </div>
            @endif
        </div>
    </div>
@endsection
