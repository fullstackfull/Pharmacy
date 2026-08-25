@extends('layouts.seller.app')

@section('title', translate('nav_integrations'))

@php
    use App\Models\SellerWebhook;
    use App\Services\SellerCenter\Copy;

    $usable = $keys->filter(fn ($key) => $key->isUsable());
    $active = $webhooks->where('status', SellerWebhook::STATUS_ACTIVE);
    $disabled = $webhooks->where('status', SellerWebhook::STATUS_DISABLED);
@endphp

@section('content')
    <x-sc.page-header :eyebrow="translate('nav_platform')" :title="translate('how_your_systems_talk_to_this_marketplace')"
                      :sub="translate('and_how_it_talks_back_to_them')">
        <x-slot:actions>
            <x-sc.button variant="secondary" :href="route('seller.integrations.health')">{{ translate('nav_integration_health') }}</x-sc.button>
        </x-slot:actions>
    </x-sc.page-header>

    <div class="sc-scroll">
        <div class="sc-page">
            @if ($disabled->isNotEmpty())
                {{-- The marketplace switched these off after they stopped answering. Nothing is
                     being delivered to them and nothing will be until somebody looks. --}}
                <x-sc.alert tone="critical"
                            :title="Copy::choice('one_endpoint_was_switched_off', 'n_endpoints_were_switched_off', $disabled->count())">
                    {{ translate('an_endpoint_that_stops_answering_is_switched_off_rather_than_retried_for_ever') }}
                    <x-slot:action>
                        <x-sc.button variant="secondary" size="sm" :href="route('seller.integrations.webhooks')">
                            {{ translate('nav_webhooks') }}
                        </x-sc.button>
                    </x-slot:action>
                </x-sc.alert>
            @elseif ($failing->isNotEmpty())
                <x-sc.alert tone="medium"
                            :title="Copy::choice('one_endpoint_is_failing', 'n_endpoints_are_failing', $failing->count())">
                    {{ translate('deliveries_are_being_retried_an_endpoint_is_switched_off_after_ten_failures_in_a_row') }}
                </x-sc.alert>
            @endif

            <div class="sc-grid-two mt-3">
                <x-sc.card :title="translate('nav_api_keys')">
                    <x-slot:context>
                        <a href="{{ route('seller.integrations.api') }}">{{ translate('manage') }}</a>
                    </x-slot:context>

                    <x-sc.info :label="translate('keys_that_still_work')" :value="number_format($usable->count())" />
                    <x-sc.info :label="translate('keys_ever_issued')" :value="number_format($keys->count())" />
                    <p class="sc-muted">{{ translate('a_key_acts_as_the_whole_shop_within_its_scopes_and_is_shown_once_when_issued') }}</p>
                </x-sc.card>

                <x-sc.card :title="translate('nav_webhooks')">
                    <x-slot:context>
                        <a href="{{ route('seller.integrations.webhooks') }}">{{ translate('manage') }}</a>
                    </x-slot:context>

                    <x-sc.info :label="translate('endpoints_receiving_events')" :value="number_format($active->count())" />
                    <x-sc.info :label="translate('endpoints_switched_off')" :value="number_format($disabled->count())"
                               :tone="$disabled->isNotEmpty() ? 'critical' : null" />
                    <x-sc.info :label="translate('events_you_can_subscribe_to')" :value="number_format(count($events))" />
                </x-sc.card>
            </div>

            <x-sc.card class="mt-3" :title="translate('events_this_marketplace_raises')">
                {{-- A fixed list. Every name here is raised from a real place in the application:
                     an event a seller can subscribe to and never receive would be worse than one
                     that is not offered. --}}
                <div class="sc-row">
                    @foreach ($events as $event)
                        <x-sc.chip :value="$event" />
                    @endforeach
                </div>
            </x-sc.card>
        </div>
    </div>
@endsection
