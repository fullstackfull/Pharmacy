@extends('layouts.admin.app')

@section('title', translate('bulk_operations'))

@php
    $shopName = fn ($id) => isset($sellers[$id])
        ? (trim(($sellers[$id]->f_name ?? '') . ' ' . ($sellers[$id]->l_name ?? '')) ?: ('#' . $id))
        : ('#' . $id);
    $tone = ['completed' => 'success', 'partial' => 'warning', 'failed' => 'danger', 'processing' => 'info', 'queued' => 'secondary'];
@endphp

@section('content')
    <div class="content container-fluid">
        <div class="mb-3">
            <h2 class="h1 mb-0">{{ translate('bulk_operations') }}</h2>
            <p class="mb-0 fs-12">{{ translate('price_and_stock_changes_sellers_made_in_bulk_and_how_far_each_got') }}.</p>
        </div>

        @include('admin-views.marketplace.seller-operations._nav')
        @include('admin-views.marketplace.seller-operations._seller-filter')

        <div class="card">
            <div class="card-body p-0">
                @if ($jobs === null)
                    <p class="text-muted p-4 mb-0">{{ translate('not_installed') }}</p>
                @else
                    <div class="k-table-wrap">
                        <table class="k-table">
                            <thead>
                                <tr>
                                    <th>{{ translate('seller') }}</th>
                                    <th>{{ translate('type') }}</th>
                                    <th>{{ translate('status') }}</th>
                                    <th class="text-end">{{ translate('total') }}</th>
                                    <th class="text-end">{{ translate('succeeded') }}</th>
                                    <th class="text-end">{{ translate('failed') }}</th>
                                    <th>{{ translate('date') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                            @forelse ($jobs as $job)
                                <tr>
                                    <td>{{ $shopName($job->seller_id) }}</td>
                                    <td class="fs-12">{{ $job->type }}</td>
                                    <td>
                                        <span class="k-badge k-badge--{{ $tone[$job->status] ?? 'secondary' }}">{{ translate($job->status) }}</span>
                                        @if ($job->error)
                                            <p class="fs-10 text-muted mb-0">{{ translate($job->error) }}</p>
                                        @endif
                                    </td>
                                    <td class="text-end">{{ $job->total }}</td>
                                    <td class="text-end">{{ $job->succeeded }}</td>
                                    <td class="text-end {{ $job->failed > 0 ? 'text-danger' : '' }}">{{ $job->failed }}</td>
                                    <td class="fs-12">{{ $job->created_at }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="text-center text-muted py-4">{{ translate('no_bulk_operations_yet') }}</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                    <div class="p-3">{{ $jobs->links() }}</div>
                @endif
            </div>
        </div>
    </div>
@endsection
