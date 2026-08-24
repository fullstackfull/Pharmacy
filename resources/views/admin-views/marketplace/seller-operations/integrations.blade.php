@extends('layouts.admin.app')

@section('title', translate('keys_and_webhooks'))

@php
    $shopName = fn ($id) => isset($sellers[$id])
        ? (trim(($sellers[$id]->f_name ?? '') . ' ' . ($sellers[$id]->l_name ?? '')) ?: ('#' . $id))
        : ('#' . $id);
@endphp

@section('content')
    <div class="content container-fluid">
        <div class="mb-3">
            <h2 class="h1 mb-0">{{ translate('keys_and_webhooks') }}</h2>
            <p class="mb-0 fs-12">{{ translate('what_acts_as_a_shop_without_a_person_and_what_the_platform_calls') }}.</p>
        </div>

        @include('admin-views.marketplace.seller-operations._nav')
        @include('admin-views.marketplace.seller-operations._seller-filter')

        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">{{ translate('api_keys') }}</h5>
                <p class="fs-12 text-muted mb-0">{{ translate('only_the_prefix_is_stored_the_key_itself_cannot_be_recovered') }}.</p>
            </div>
            <div class="card-body p-0">
                @if ($keys === null)
                    <p class="text-muted p-4 mb-0">{{ translate('not_installed') }}</p>
                @else
                    <div class="k-table-wrap">
                        <table class="k-table">
                            <thead>
                                <tr>
                                    <th>{{ translate('seller') }}</th>
                                    <th>{{ translate('name') }}</th>
                                    <th>{{ translate('prefix') }}</th>
                                    <th>{{ translate('scopes') }}</th>
                                    <th>{{ translate('last_used') }}</th>
                                    <th>{{ translate('status') }}</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                            @forelse ($keys as $key)
                                <tr>
                                    <td>{{ $shopName($key->seller_id) }}</td>
                                    <td>{{ $key->name }}</td>
                                    <td class="fs-12">sk_seller_{{ $key->prefix }}…</td>
                                    <td class="fs-10">{{ empty($key->scopes) ? translate('none') : implode(' · ', $key->scopes) }}</td>
                                    <td class="fs-12">
                                        {{-- Never used is a fact, not a blank. It is usually the
                                             answer to whether the key is still needed. --}}
                                        {{ $key->last_used_at ?? translate('never_used') }}
                                    </td>
                                    <td>
                                        <span class="k-badge k-badge--{{ $key->isUsable() ? 'success' : 'danger' }}">
                                            {{ translate($key->isUsable() ? 'active' : 'revoked') }}
                                        </span>
                                    </td>
                                    <td class="text-end">
                                        @if ($key->isUsable())
                                            <form method="POST" action="{{ route('admin.marketplace.seller-operations.revoke-key') }}"
                                                  onsubmit="return confirm('{{ translate('anything_using_this_key_stops_working_immediately') }}')">
                                                @csrf
                                                <input type="hidden" name="id" value="{{ $key->id }}">
                                                <button class="btn btn--sm btn-outline-danger" type="submit">{{ translate('revoke') }}</button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="text-center text-muted py-4">{{ translate('no_seller_has_issued_a_key_yet') }}</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                    <div class="p-3">{{ $keys->links() }}</div>
                @endif
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">{{ translate('webhook_endpoints') }}</h5>
                @if ($health['installed'])
                    <p class="fs-12 text-muted mb-0">
                        {{ translate('last_24h') }}: {{ $health['delivered'] }} {{ translate('delivered') }},
                        {{ $health['failed'] }} {{ translate('failed') }},
                        {{ $health['pending'] }} {{ translate('still_trying') }}.
                    </p>
                @endif
            </div>
            <div class="card-body p-0">
                @if ($webhooks === null)
                    <p class="text-muted p-4 mb-0">{{ translate('not_installed') }}</p>
                @else
                    <div class="k-table-wrap">
                        <table class="k-table">
                            <thead>
                                <tr>
                                    <th>{{ translate('seller') }}</th>
                                    <th>{{ translate('name') }}</th>
                                    <th>{{ translate('url') }}</th>
                                    <th>{{ translate('events') }}</th>
                                    <th>{{ translate('health') }}</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                            @forelse ($webhooks as $webhook)
                                <tr>
                                    <td>{{ $shopName($webhook->seller_id) }}</td>
                                    <td>{{ $webhook->name }}</td>
                                    <td class="fs-10 text-truncate" style="max-width:260px">{{ $webhook->url }}</td>
                                    <td class="fs-10">{{ empty($webhook->events) ? translate('none') : implode(' · ', $webhook->events) }}</td>
                                    <td>
                                        @if ($webhook->status === 'disabled')
                                            <span class="k-badge k-badge--danger">{{ translate('disabled') }}</span>
                                            <p class="fs-10 text-muted mb-0">{{ translate($webhook->disabled_reason ?? 'webhook_disabled') }}</p>
                                        @elseif ($webhook->last_success_at === null && $webhook->last_failure_at === null)
                                            {{-- Nothing has been sent to it. Not a green tick it has not earned. --}}
                                            <span class="k-badge k-badge--secondary">{{ translate('never_called') }}</span>
                                        @elseif ($webhook->consecutive_failures > 0)
                                            <span class="k-badge k-badge--warning">{{ translate('failing') }} ({{ $webhook->consecutive_failures }})</span>
                                        @else
                                            <span class="k-badge k-badge--success">{{ translate('healthy') }}</span>
                                        @endif
                                    </td>
                                    <td class="text-end">
                                        @if ($webhook->status !== 'disabled')
                                            <form method="POST" action="{{ route('admin.marketplace.seller-operations.disable-webhook') }}"
                                                  onsubmit="return confirm('{{ translate('the_platform_will_stop_calling_this_endpoint') }}')">
                                                @csrf
                                                <input type="hidden" name="id" value="{{ $webhook->id }}">
                                                <button class="btn btn--sm btn-outline-danger" type="submit">{{ translate('disable') }}</button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="text-center text-muted py-4">{{ translate('no_seller_has_added_an_endpoint_yet') }}</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                    <div class="p-3">{{ $webhooks->links() }}</div>
                @endif
            </div>
        </div>
    </div>
@endsection
