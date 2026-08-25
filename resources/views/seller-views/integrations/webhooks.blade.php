@extends('layouts.seller.app')

@section('title', translate('nav_webhooks'))

@php
    use App\Models\SellerWebhook;
    use App\Services\SellerCenter\Copy;

    $columns = [
        ['key' => 'name', 'label' => translate('name'), 'width' => 170],
        ['key' => 'url', 'label' => translate('destination')],
        ['key' => 'events', 'label' => translate('subscribed_to'), 'priority' => 'md'],
        ['key' => 'health', 'label' => translate('health'), 'width' => 190],
        ['key' => 'status', 'label' => translate('status'), 'width' => 130],
        ['key' => 'action', 'label' => '', 'width' => 150],
    ];
@endphp

@section('content')
    <x-sc.page-header :eyebrow="translate('nav_platform')" :title="translate('nav_webhooks')"
                      :sub="translate('where_this_marketplace_sends_your_shops_events')"
                      :back="route('seller.integrations.index')">
        <x-slot:actions>
            <x-sc.button variant="secondary" :href="route('seller.integrations.health')">{{ translate('nav_integration_health') }}</x-sc.button>
        </x-slot:actions>
    </x-sc.page-header>

    <div class="sc-scroll">
        <div class="sc-page">
            @if ($secret)
                {{-- Shown once. Every delivery carries an HMAC of its exact body under this secret,
                     which is how the receiver tells our delivery from anybody else's POST to the
                     same URL. --}}
                <x-sc.alert tone="good" :title="translate('copy_this_signing_secret_now')">
                    <p>{{ translate('every_delivery_is_signed_with_it_verify_the_signature_or_anybody_can_post_to_your_endpoint') }}</p>
                    <p class="sc-code sc-no-print">{{ $secret }}</p>
                </x-sc.alert>
            @endif

            <x-sc.card class="mt-3" :title="translate('add_an_endpoint')">
                <form method="POST" action="{{ route('seller.integrations.webhooks.store') }}">
                    @csrf
                    <div class="sc-form-row">
                        <x-sc.field :label="translate('name')" required :error="$errors->first('name')">
                            <x-sc.input name="name" required maxlength="120" :value="old('name')" :invalid="$errors->has('name')" />
                        </x-sc.field>

                        <x-sc.field :label="translate('destination')" required
                                    :help="translate('https_only_a_signed_delivery_over_plain_http_is_signed_plaintext')"
                                    :error="$errors->first('url')">
                            <x-sc.input type="url" name="url" required maxlength="500" placeholder="https://"
                                        :value="old('url')" :invalid="$errors->has('url')" />
                        </x-sc.field>
                    </div>

                    <x-sc.field :label="translate('subscribed_to')" required :error="$errors->first('events')"
                                :help="translate('an_endpoint_receives_only_the_events_it_asked_for')">
                        <div class="sc-row">
                            @foreach ($events as $event)
                                <label class="sc-check">
                                    <input type="checkbox" name="events[]" value="{{ $event }}">
                                    <span class="sc-code">{{ $event }}</span>
                                </label>
                            @endforeach
                        </div>
                    </x-sc.field>

                    <div class="sc-form-footer">
                        <x-sc.button variant="primary" type="submit" icon="plus">{{ translate('add_an_endpoint') }}</x-sc.button>
                    </div>
                </form>
            </x-sc.card>

            <x-sc.table class="mt-3" :columns="$columns" :state="$state">
                <x-slot:empty>
                    <x-sc.empty glyph="broadcast" :title="translate('nothing_is_being_told_about_your_events')"
                                :text="translate('add_an_endpoint_and_this_marketplace_will_post_to_it_as_things_happen')" />
                </x-slot:empty>

                @foreach ($webhooks as $webhook)
                    <x-sc.tr :id="$webhook['id']">
                        <x-sc.td>{{ $webhook['name'] }}</x-sc.td>
                        <x-sc.td><span class="sc-code">{{ $webhook['url'] }}</span></x-sc.td>
                        <x-sc.td>{{ implode(translate('list_separator') . ' ', $webhook['events']) }}</x-sc.td>
                        <x-sc.td :tone="$webhook['consecutive_failures'] > 0 ? 'critical' : null">
                            @if ($webhook['never_called'])
                                {{-- An endpoint nothing has been sent to has not earned a green tick. --}}
                                <span class="sc-muted">{{ translate('nothing_sent_yet') }}</span>
                            @elseif ($webhook['consecutive_failures'] > 0)
                                {{ Copy::line('n_failures_in_a_row', ['count' => $webhook['consecutive_failures']]) }}
                            @else
                                {{ Copy::line('last_delivered_x', ['date' => \Illuminate\Support\Carbon::parse($webhook['last_success_at'])->format('Y-m-d H:i')]) }}
                            @endif
                        </x-sc.td>
                        <x-sc.td :sub="$webhook['disabled_reason']"><x-sc.badge :status="$webhook['status']" /></x-sc.td>
                        <x-sc.td action>
                            <form method="POST" action="{{ route('seller.integrations.webhooks.test', ['webhook' => $webhook['id']]) }}" style="display:inline">
                                @csrf
                                <input type="hidden" name="event" value="{{ $webhook['events'][0] ?? $events[0] }}">
                                <x-sc.button variant="ghost" size="sm" type="submit">{{ translate('send_a_test') }}</x-sc.button>
                            </form>

                            <form method="POST" action="{{ route('seller.integrations.webhooks.status', ['webhook' => $webhook['id']]) }}" style="display:inline">
                                @csrf
                                {{-- Disabled is the marketplace's state, not the seller's. Switching
                                     back to active is how it is cleared, deliberately. --}}
                                <input type="hidden" name="status"
                                       value="{{ $webhook['status'] === SellerWebhook::STATUS_ACTIVE ? SellerWebhook::STATUS_PAUSED : SellerWebhook::STATUS_ACTIVE }}">
                                <x-sc.button variant="ghost" size="sm" type="submit">
                                    {{ $webhook['status'] === SellerWebhook::STATUS_ACTIVE ? translate('pause') : translate('resume') }}
                                </x-sc.button>
                            </form>

                            <form method="POST" action="{{ route('seller.integrations.webhooks.destroy', ['webhook' => $webhook['id']]) }}"
                                  style="display:inline"
                                  data-sc-confirm="{{ translate('remove_this_endpoint_its_deliveries_stay_removing_it_does_not_un_send_them') }}">
                                @csrf
                                @method('DELETE')
                                <x-sc.button variant="ghost" size="sm" type="submit">{{ translate('remove') }}</x-sc.button>
                            </form>
                        </x-sc.td>
                    </x-sc.tr>
                @endforeach
            </x-sc.table>
        </div>
    </div>
@endsection
