@extends('layouts.seller.app')

@section('title', translate('nav_approvals'))

@php
    use App\Models\ApprovalRequest;
    use App\Services\SellerCenter\Copy;

    $columns = [
        ['key' => 'reference', 'label' => translate('reference'), 'width' => 150],
        ['key' => 'subject', 'label' => translate('what_is_waiting')],
        ['key' => 'amount', 'label' => translate('amount'), 'width' => 130, 'num' => true],
        ['key' => 'status', 'label' => translate('status'), 'width' => 140],
        ['key' => 'progress', 'label' => translate('approvals_collected'), 'width' => 160, 'num' => true],
        ['key' => 'decided', 'label' => translate('decided'), 'width' => 150, 'priority' => 'md'],
    ];

    $pending = $approvals->filter(fn (ApprovalRequest $approval) => $approval->isPending());
@endphp

@section('content')
    <x-sc.page-header :eyebrow="translate('nav_trust')" :title="translate('your_requests_waiting_on_the_marketplace')"
                      :sub="translate('read_only_by_design_the_approver_is_by_definition_not_the_requester')" />

    <div class="sc-scroll">
        <div class="sc-page">
            @unless ($available)
                <x-sc.alert tone="info" :title="translate('dual_control_is_not_running_on_this_marketplace')">
                    {{ translate('nothing_is_being_withheld_there_is_no_approval_queue_to_read') }}
                </x-sc.alert>
            @else
                @if ($pending->isNotEmpty())
                    {{-- Why a payout has sat at "pending" longer than usual. A rule the seller
                         cannot see is indistinguishable from a system that has stopped working. --}}
                    <x-sc.alert tone="info"
                                :title="Copy::choice('one_request_is_waiting_on_a_second_approver', 'n_requests_are_waiting_on_a_second_approver', $pending->count())">
                        {{ translate('a_payout_above_the_marketplaces_threshold_needs_a_second_person_to_release_it') }}
                        <x-slot:action>
                            <x-sc.button variant="secondary" size="sm" :href="route('seller.finance.payouts')">
                                {{ translate('nav_payouts') }}
                            </x-sc.button>
                        </x-slot:action>
                    </x-sc.alert>
                @endif

                <x-sc.table class="mt-3" :columns="$columns" :state="$state">
                    <x-slot:empty>
                        <x-sc.empty glyph="check-circle" :title="translate('nothing_of_yours_is_waiting_on_an_approval')"
                                    :text="translate('only_a_payout_above_the_marketplaces_threshold_opens_one')" />
                    </x-slot:empty>

                    @foreach ($approvals as $approval)
                        @php($payout = $payouts->get($approval->subject_id))
                        <x-sc.tr :id="$approval->id">
                            <x-sc.td><span class="sc-code">{{ $approval->reference }}</span></x-sc.td>
                            <x-sc.td :sub="translate($approval->workflow)">
                                {{ $payout?->reference
                                    ? Copy::line('payout_x', ['reference' => $payout->reference])
                                    : Copy::line('payout_n', ['id' => $approval->subject_id]) }}
                            </x-sc.td>
                            <x-sc.td num>{{ number_format((float) $approval->amount, 2) }}</x-sc.td>
                            <x-sc.td><x-sc.badge :status="$approval->status" /></x-sc.td>
                            {{-- Two of two, not "in progress": the seller can see exactly how far
                                 from released the request is. --}}
                            <x-sc.td num>{{ Copy::line('x_of_y', [
                                'collected' => $approval->approvals_count,
                                'required' => $approval->required_approvals,
                            ]) }}</x-sc.td>
                            <x-sc.td>{{ $approval->decided_at?->format('Y-m-d H:i') ?? '—' }}</x-sc.td>
                        </x-sc.tr>
                    @endforeach
                </x-sc.table>
            @endunless
        </div>
    </div>
@endsection
