@extends('layouts.vendor.app')

@section('title', translate('payouts'))

@php
    $statusColours = [
        'requested' => 'info', 'under_review' => 'warning', 'approved' => 'primary',
        'processing' => 'primary', 'paid' => 'success', 'rejected' => 'danger', 'failed' => 'danger',
    ];
@endphp

@section('content')
    <div class="content container-fluid">
        <div class="mb-3">
            <h2 class="h1 mb-0 d-flex align-items-center gap-2">
                <i class="fi fi-rr-coins"></i>
                {{ translate('payouts') }}
            </h2>
            <p class="mb-0 fs-12">{{ translate('request_a_payout_of_your_available_balance') }}</p>
        </div>

        {{-- The four buckets, with the one number that decides everything: withdrawable. --}}
        <div class="row g-3 mb-3">
            @foreach ([
                ['pending', translate('pending_in_return_window'), 'warning'],
                ['available', translate('available'), 'success'],
                ['reserved', translate('reserved_for_payouts'), 'info'],
            ] as [$key, $label, $colour])
                <div class="col-6 col-lg-3">
                    <div class="card h-100 border-{{ $colour }}">
                        <div class="card-body">
                            <p class="mb-1 fs-12 text-muted">{{ $label }}</p>
                            <h4 class="mb-0">{{ setCurrencySymbol($balances[$key]) }}</h4>
                        </div>
                    </div>
                </div>
            @endforeach
            <div class="col-6 col-lg-3">
                <div class="card h-100 border-dark bg-dark text-white">
                    <div class="card-body">
                        <p class="mb-1 fs-12 opacity-75">{{ translate('withdrawable_now') }}</p>
                        <h4 class="mb-0">{{ setCurrencySymbol($withdrawable) }}</h4>
                    </div>
                </div>
            </div>
        </div>

        @if ($inCoolingPeriod)
            <div class="alert alert-warning">
                <i class="fi fi-rr-info"></i>
                {{ translate('your_bank_details_changed_recently_so_payouts_are_paused_briefly_for_your_security') }}
            </div>
        @endif

        {{-- Request form, disabled when there is nothing to withdraw or a cooling window is open. --}}
        <div class="card mb-3">
            <div class="card-body">
                <form action="{{ route('vendor.business-settings.payouts.store') }}" method="post" class="row g-2 align-items-end">
                    @csrf
                    <div class="col-sm-4">
                        <label class="form-label" for="amount">{{ translate('amount') }}</label>
                        <input type="number" step="0.01" min="0.01" max="{{ $withdrawable }}"
                               class="form-control" id="amount" name="amount"
                               {{ ($withdrawable <= 0 || $inCoolingPeriod) ? 'disabled' : '' }} required>
                    </div>
                    <div class="col-sm-4">
                        <label class="form-label" for="method">{{ translate('method') }}</label>
                        <select class="form-control" id="method" name="method" {{ ($withdrawable <= 0 || $inCoolingPeriod) ? 'disabled' : '' }}>
                            <option value="bank_transfer">{{ translate('bank_transfer') }}</option>
                            <option value="manual">{{ translate('manual') }}</option>
                        </select>
                    </div>
                    <div class="col-sm-4">
                        <button type="submit" class="btn btn-primary w-100"
                                {{ ($withdrawable <= 0 || $inCoolingPeriod) ? 'disabled' : '' }}>
                            <i class="fi fi-rr-paper-plane"></i> {{ translate('request_payout') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h5 class="mb-0">{{ translate('my_payout_requests') }}</h5></div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th>{{ translate('reference') }}</th>
                                <th>{{ translate('date') }}</th>
                                <th class="text-end">{{ translate('amount') }}</th>
                                <th>{{ translate('method') }}</th>
                                <th>{{ translate('status') }}</th>
                                <th class="text-end">{{ translate('action') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($requests as $payout)
                                <tr>
                                    <td class="fw-medium">{{ $payout->reference }}</td>
                                    <td class="fs-12">{{ $payout->created_at?->format('d M Y H:i') }}</td>
                                    <td class="text-end fw-semibold">{{ setCurrencySymbol($payout->amount) }}</td>
                                    <td class="fs-12">{{ translate($payout->method ?? 'bank_transfer') }}</td>
                                    <td>
                                        <span class="badge bg-{{ $statusColours[$payout->status] ?? 'secondary' }}">
                                            {{ translate($payout->status) }}
                                        </span>
                                    </td>
                                    <td class="text-end">
                                        @if ($payout->isOpen())
                                            <form action="{{ route('vendor.business-settings.payouts.cancel', $payout->id) }}" method="post"
                                                  onsubmit="return confirm('{{ translate('cancel_this_payout_request') }}?');">
                                                @csrf
                                                <button class="btn btn-sm btn-outline-danger">{{ translate('cancel') }}</button>
                                            </form>
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-4">{{ translate('no_payout_requests_yet') }}</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                {!! $requests->links() !!}
            </div>
        </div>
    </div>
@endsection
