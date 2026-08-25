@extends('layouts.seller.app')

@section('title', translate('nav_finance'))

@php
    use App\Services\SellerCenter\Copy;
    $buckets = $summary['buckets'];
@endphp

@section('content')
    <x-sc.page-header :eyebrow="translate('nav_finance')" :title="translate('your_balance_and_what_it_is_made_of')"
                      :sub="translate('one_ledger_read_six_ways_every_figure_here_is_the_same_number_the_app_reads')">
        <x-slot:actions>
            <x-sc.button variant="secondary" icon="list" :href="route('seller.finance.transactions')">{{ translate('every_movement') }}</x-sc.button>
            <x-sc.button variant="primary" icon="bank" :href="route('seller.finance.payouts')">{{ translate('nav_payouts') }}</x-sc.button>
        </x-slot:actions>
    </x-sc.page-header>

    <div class="sc-scroll">
        <div class="sc-page">
            <div class="sc-stats">
                {{-- The only figure that answers "how much can I ask for right now". It nets
                     settlements and holds rather than reading a single bucket, which is why it is
                     first and why it is not simply "available". --}}
                <x-sc.stat :label="translate('you_can_withdraw')" :value="number_format($summary['withdrawable'], 2)"
                           :tone="$summary['withdrawable'] > 0 ? 'good' : null"
                           :note="$summary['currency'] ?? ''" />
                <x-sc.stat :label="translate('pending')" :value="number_format($buckets['pending'], 2)"
                           :note="translate('earned_and_still_inside_the_return_window')" />
                <x-sc.stat :label="translate('available')" :value="number_format($buckets['available'], 2)"
                           :note="translate('matured_and_not_yet_claimed')" />
                <x-sc.stat :label="translate('reserved')" :value="number_format($buckets['reserved'], 2)"
                           :note="translate('held_against_a_payout_you_have_asked_for')" />
                <x-sc.stat :label="translate('paid_out')" :value="number_format($buckets['paid'], 2)"
                           :note="translate('money_that_has_reached_you')" />
            </div>

            @if ($inCoolingPeriod)
                {{-- Said before the seller tries and is refused. A cooling period a seller only
                     discovers by submitting a request is a rule that feels like a fault. --}}
                <x-sc.alert tone="medium" class="mt-3" :title="translate('a_cooling_period_is_in_force')">
                    {{ translate('your_bank_details_changed_recently_so_payouts_are_paused_until_the_marketplaces_window_has_passed') }}
                </x-sc.alert>
            @endif

            <x-sc.card :title="translate('the_last_few_movements')" class="mt-3">
                <x-slot:context>
                    <a href="{{ route('seller.finance.transactions') }}">{{ translate('see_all') }}</a>
                </x-slot:context>

                @if ($recent === [])
                    <x-sc.empty glyph="receipt" :title="translate('your_ledger_is_empty')"
                                :text="translate('the_first_entry_appears_when_an_order_of_yours_is_delivered')" />
                @else
                    <x-sc.timeline>
                        @foreach ($recent as $row)
                            <x-sc.timeline-item :tone="$row['credit'] > 0 ? 'good' : 'medium'"
                                                :time="optional($row['created_at'])->format('Y-m-d')"
                                                :meta="translate($row['status'])">
                                <strong>{{ translate($row['entry_type']) }}</strong>
                                —
                                {{ $row['credit'] > 0 ? '+ ' . number_format($row['credit'], 2) : '− ' . number_format($row['debit'], 2) }}
                                @if ($row['order_id'])
                                    <a href="{{ route('seller.orders.show', ['order' => $row['order_id']]) }}">#{{ $row['order_id'] }}</a>
                                @endif
                            </x-sc.timeline-item>
                        @endforeach
                    </x-sc.timeline>
                @endif
            </x-sc.card>

            <div class="sc-grid-two mt-3">
                <x-sc.card :title="translate('does_it_add_up')">
                    <p class="sc-muted">{{ translate('check_your_delivered_lines_against_what_was_credited_to_you') }}</p>
                    <x-sc.button variant="secondary" :href="route('seller.finance.reconciliation')">{{ translate('run_the_check') }}</x-sc.button>
                </x-sc.card>
                <x-sc.card :title="translate('what_does_the_marketplace_take')">
                    <p class="sc-muted">{{ translate('work_out_the_commission_on_a_line_before_you_price_it') }}</p>
                    <x-sc.button variant="secondary" :href="route('seller.finance.fees')">{{ translate('open_the_fee_calculator') }}</x-sc.button>
                </x-sc.card>
            </div>
        </div>
    </div>
@endsection
