@extends('layouts.admin.app')

@section('title', translate('seller_operations'))

@php
    $shopName = fn ($id) => isset($sellers[$id])
        ? (trim(($sellers[$id]->f_name ?? '') . ' ' . ($sellers[$id]->l_name ?? '')) ?: ('#' . $id))
        : ('#' . $id);
@endphp

@section('content')
    <div class="content container-fluid">
        <div class="mb-3">
            <h2 class="h1 mb-0 d-flex align-items-center gap-2">
                <i class="fi fi-rr-dashboard"></i>
                {{ translate('seller_operations') }}
            </h2>
            <p class="mb-0 fs-12">{{ translate('what_the_sellers_are_doing_with_the_platform') }}.</p>
        </div>

        @include('admin-views.marketplace.seller-operations._nav')

        <div class="row g-2 mb-4">
            @foreach ($summary as $key => $card)
                <div class="col-6 col-md-4 col-xl-2">
                    <div class="card h-100">
                        <div class="card-body">
                            <p class="fs-12 text-muted mb-1">{{ translate($card['label']) }}</p>
                            @if (! $card['installed'])
                                {{-- Not installed and zero are different facts, and a dashboard of
                                     zeroes gives an operator no way to tell them apart. --}}
                                <h3 class="mb-0 text-muted">—</h3>
                                <p class="fs-10 text-muted mb-0">{{ translate('not_installed') }}</p>
                            @else
                                <h3 class="mb-0">{{ $card['total'] }}</h3>
                                <p class="fs-10 mb-0 {{ ($card['attention'] ?? 0) > 0 ? 'text-danger' : 'text-muted' }}">
                                    {{ $card['attention'] ?? 0 }} {{ translate('need_attention') }}
                                </p>
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="row g-3">
            <div class="col-12 col-xl-7">
                <div class="card h-100">
                    <div class="card-header">
                        <h5 class="mb-0">{{ translate('shops_with_open_issues') }}</h5>
                        <p class="fs-12 text-muted mb-0">{{ translate('ranked_by_what_is_worst_not_by_how_many') }}.</p>
                    </div>
                    <div class="card-body p-0">
                        <div class="k-table-wrap">
                            <table class="k-table">
                                <thead>
                                    <tr>
                                        <th>{{ translate('seller') }}</th>
                                        <th class="text-end">{{ translate('critical') }}</th>
                                        <th class="text-end">{{ translate('open') }}</th>
                                        <th class="text-end">{{ translate('worst_score') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                @forelse ($issuesBySeller as $row)
                                    <tr>
                                        <td>{{ $shopName($row->seller_id) }}</td>
                                        <td class="text-end">
                                            @if ((int) $row->critical > 0)
                                                <span class="k-badge k-badge--danger">{{ $row->critical }}</span>
                                            @else
                                                0
                                            @endif
                                        </td>
                                        <td class="text-end">{{ $row->total }}</td>
                                        <td class="text-end">{{ $row->worst_score }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" class="text-center text-muted py-4">
                                            {{ translate('no_open_issues_on_any_shop') }}
                                        </td>
                                    </tr>
                                @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12 col-xl-5">
                <div class="card h-100">
                    <div class="card-header">
                        <h5 class="mb-0">{{ translate('webhook_deliveries_last_24h') }}</h5>
                    </div>
                    <div class="card-body">
                        @if (! $deliveryHealth['installed'])
                            <p class="text-muted mb-0">{{ translate('not_installed') }}</p>
                        @elseif (($deliveryHealth['delivered'] + $deliveryHealth['failed'] + $deliveryHealth['pending']) === 0)
                            <p class="text-muted mb-0">{{ translate('nothing_was_sent_in_the_last_day') }}.</p>
                        @else
                            <div class="d-flex gap-4">
                                <div>
                                    <p class="fs-12 text-muted mb-1">{{ translate('delivered') }}</p>
                                    <h4 class="mb-0">{{ $deliveryHealth['delivered'] }}</h4>
                                </div>
                                <div>
                                    <p class="fs-12 text-muted mb-1">{{ translate('failed') }}</p>
                                    <h4 class="mb-0 {{ $deliveryHealth['failed'] > 0 ? 'text-danger' : '' }}">{{ $deliveryHealth['failed'] }}</h4>
                                </div>
                                <div>
                                    <p class="fs-12 text-muted mb-1">{{ translate('still_trying') }}</p>
                                    <h4 class="mb-0">{{ $deliveryHealth['pending'] }}</h4>
                                </div>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
