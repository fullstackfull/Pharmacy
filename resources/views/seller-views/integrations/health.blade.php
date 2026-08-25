@extends('layouts.seller.app')

@section('title', translate('nav_integration_health'))

@php
    use App\Models\SellerWebhookDelivery;
    use App\Services\SellerCenter\Copy;

    $columns = [
        ['key' => 'when', 'label' => translate('when'), 'width' => 160],
        ['key' => 'endpoint', 'label' => translate('endpoint'), 'width' => 170],
        ['key' => 'event', 'label' => translate('event'), 'width' => 180],
        ['key' => 'status', 'label' => translate('status'), 'width' => 130],
        ['key' => 'attempts', 'label' => translate('attempts'), 'width' => 100, 'num' => true],
        ['key' => 'response', 'label' => translate('what_came_back')],
    ];

    $statusTabs = collect(['pending', 'delivered', 'failed'])
        ->map(fn (string $status) => [
            'key' => $status,
            'label' => translate($status),
            'href' => route('seller.integrations.health', array_filter([
                'status' => $status,
                'webhook_id' => $currentWebhook,
            ])),
        ])
        ->prepend([
            'key' => 'all',
            'label' => translate('everything'),
            'href' => route('seller.integrations.health', array_filter(['webhook_id' => $currentWebhook])),
        ])
        ->all();
@endphp

@section('content')
    <x-sc.page-header :eyebrow="translate('nav_platform')" :title="translate('what_was_sent_and_what_came_back')"
                      :sub="translate('every_attempt_kept_whether_it_worked_or_not')"
                      :back="route('seller.integrations.index')" />

    <div class="sc-scroll">
        <div class="sc-page">
            {{-- A seller whose integration is quietly missing every third order needs to see the
                 third one, and a failure counter on the endpoint cannot show them which. --}}
            <x-sc.tabs inline :current="$currentStatus ?? 'all'" :tabs="$statusTabs" />

            <x-sc.table class="mt-3" :columns="$columns" :state="$state">
                <x-slot:empty>
                    <x-sc.empty glyph="broadcast" :title="translate('nothing_has_been_sent_yet')"
                                :text="translate('deliveries_appear_here_as_events_happen_in_your_shop')" />
                </x-slot:empty>
                <x-slot:noResults>
                    <x-sc.empty glyph="funnel" :title="translate('no_deliveries_match_these_filters')"
                                :text="translate('choose_everything_to_see_them_all')" />
                </x-slot:noResults>

                @foreach ($deliveries as $delivery)
                    <x-sc.tr :id="$delivery->id">
                        <x-sc.td>{{ $delivery->created_at?->format('Y-m-d H:i') ?? '—' }}</x-sc.td>
                        <x-sc.td>{{ $webhooks->get($delivery->webhook_id)?->name ?? Copy::line('endpoint_n', ['id' => $delivery->webhook_id]) }}</x-sc.td>
                        <x-sc.td><span class="sc-code">{{ $delivery->event }}</span></x-sc.td>
                        <x-sc.td :sub="$delivery->next_attempt_at ? Copy::line('next_attempt_x', ['date' => $delivery->next_attempt_at->format('Y-m-d H:i')]) : null">
                            <x-sc.badge :status="$delivery->status" />
                        </x-sc.td>
                        <x-sc.td num>{{ number_format($delivery->attempts) }}</x-sc.td>
                        <x-sc.td :sub="$delivery->error">
                            @if ($delivery->response_code)
                                <span class="sc-code">{{ $delivery->response_code }}</span>
                            @else
                                {{-- No code at all means the connection never completed, which is a
                                     different failure from a rejection and reads differently. --}}
                                <span class="sc-muted">{{ translate('no_response') }}</span>
                            @endif
                        </x-sc.td>
                    </x-sc.tr>
                @endforeach
            </x-sc.table>

            <x-sc.pager :paginator="$deliveries" />
        </div>
    </div>
@endsection
