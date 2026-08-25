@extends('layouts.admin.app')
@section('title', translate('notification_delivery_log'))

@section('content')
    {{--
        The delivery record for every transactional message this shop sends.

        Built because the failing part of this system was the part nobody could see: twenty-three
        listeners send order, refund, wallet, OTP, verification, restock, referral and seller
        onboarding messages across three channels, and not one of them recorded an outcome. A shop
        whose SMS credentials expired sent no OTP, no customer could sign in, and every screen in the
        monitoring console stayed green.

        The counts lead with the last twenty-four hours rather than all time: an operator opening
        this page is asking whether something is broken now, and a lifetime failure total answers a
        different question in a way that looks like an alarm.
    --}}
    <div class="content container-fluid">
        <div class="mb-3">
            <h2 class="h1 mb-0 text-capitalize">{{ translate('notification_delivery_log') }}</h2>
            <p class="text-muted mb-0">{{ translate('every_transactional_email_sms_and_push_this_shop_tried_to_send') }}</p>
        </div>

        <div class="row g-2 mb-3">
            @foreach ([
                'sent' => ['label' => 'delivered', 'tone' => 'success'],
                'failed' => ['label' => 'failed', 'tone' => 'danger'],
                'pending' => ['label' => 'not_confirmed', 'tone' => 'warning'],
            ] as $key => $meta)
                <div class="col-6 col-md-4">
                    <div class="card h-100">
                        <div class="card-body py-3">
                            <div class="text-muted fs-12">{{ translate($meta['label']) }} — {{ translate('last_24_hours') }}</div>
                            <div class="h2 mb-0 text-{{ $meta['tone'] }}">{{ $counts[$key] }}</div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="card">
            <div class="card-body">
                <form method="get" class="row g-2 align-items-end mb-3">
                    <div class="col-md-3">
                        <label class="form-label" for="channel">{{ translate('channel') }}</label>
                        <select class="form-control" id="channel" name="channel">
                            <option value="">{{ translate('all') }}</option>
                            @foreach (['mail', 'sms', 'push'] as $option)
                                <option value="{{ $option }}" @selected($channel === $option)>{{ translate($option) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="status">{{ translate('status') }}</label>
                        <select class="form-control" id="status" name="status">
                            <option value="">{{ translate('all') }}</option>
                            @foreach (['sent' => 'delivered', 'failed' => 'failed', 'pending' => 'not_confirmed'] as $value => $label)
                                <option value="{{ $value }}" @selected($status === $value)>{{ translate($label) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="search">{{ translate('recipient') }}</label>
                        <input type="text" class="form-control" id="search" name="search" value="{{ $search }}"
                               placeholder="{{ translate('an_email_address_or_a_phone_number') }}">
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary w-100">{{ translate('filter') }}</button>
                    </div>
                </form>

                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                        <tr>
                            <th>{{ translate('when') }}</th>
                            <th>{{ translate('channel') }}</th>
                            <th>{{ translate('message') }}</th>
                            <th>{{ translate('recipient') }}</th>
                            <th>{{ translate('status') }}</th>
                            <th class="text-end">{{ translate('action') }}</th>
                        </tr>
                        </thead>
                        <tbody>
                        @forelse ($deliveries as $delivery)
                            <tr>
                                <td class="text-nowrap">{{ $delivery->created_at?->format('Y-m-d H:i') }}</td>
                                <td>{{ translate($delivery->channel) }}</td>
                                <td>
                                    <div>{{ $delivery->subject ?: translate($delivery->event ?: 'message') }}</div>
                                    @if ($delivery->resent_from_id)
                                        {{-- A resend is its own attempt pointing at the one it repeats, never an
                                             edit of the original: the first row stays true about what happened. --}}
                                        <small class="text-muted">{{ translate('resent_from') }} #{{ $delivery->resent_from_id }}</small>
                                    @endif
                                </td>
                                <td>{{ $delivery->recipient }}</td>
                                <td>
                                    @if ($delivery->status === 'sent')
                                        <span class="badge badge-soft-success">{{ translate('delivered') }}</span>
                                    @elseif ($delivery->status === 'failed')
                                        <span class="badge badge-soft-danger">{{ translate('failed') }}</span>
                                        @if ($delivery->error)
                                            <small class="d-block text-muted">{{ Str::limit($delivery->error, 90) }}</small>
                                        @endif
                                    @else
                                        <span class="badge badge-soft-warning">{{ translate('not_confirmed') }}</span>
                                    @endif
                                </td>
                                <td class="text-end">
                                    @if ($delivery->isResendable())
                                        <form method="post" action="{{ route('admin.notification.deliveries.resend', ['id' => $delivery->id]) }}">
                                            @csrf
                                            <button type="submit" class="btn btn-sm btn-outline-primary">{{ translate('send_again') }}</button>
                                        </form>
                                    @elseif ($delivery->channel === 'sms')
                                        {{-- Deliberate: re-sending an SMS minutes later delivers a one-time code
                                             that has already expired, and an operator who watches it succeed
                                             concludes the customer's problem is solved when it is not. --}}
                                        <small class="text-muted">{{ translate('a_one_time_code_cannot_be_sent_again') }}</small>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center text-muted py-4">
                                    {{ translate('no_message_has_been_sent_yet') }}
                                </td>
                            </tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="d-flex justify-content-end">
                    {!! $deliveries->links() !!}
                </div>
            </div>
        </div>
    </div>
@endsection
