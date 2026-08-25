@extends('layouts.seller.app')

@section('title', translate('nav_reconciliation'))

@php
    use App\Services\SellerCenter\Copy;
@endphp

@section('content')
    <x-sc.page-header :eyebrow="translate('nav_finance')" :title="translate('does_it_add_up')"
                      :sub="Copy::line('delivered_lines_against_credits_between_x_and_y', ['from' => $report['from'], 'to' => $report['to']])"
                      :back="route('seller.finance.index')" />

    <div class="sc-scroll">
        <div class="sc-page">
            <form method="GET" class="sc-form-row">
                <x-sc.field :label="translate('from')"><x-sc.input type="date" name="from" :value="$report['from']" /></x-sc.field>
                <x-sc.field :label="translate('to')"><x-sc.input type="date" name="to" :value="$report['to']" /></x-sc.field>
                <x-sc.button type="submit" variant="primary">{{ translate('run_the_check') }}</x-sc.button>
            </form>

            {{-- "Reconciles" is deliberately not "the totals are close enough". A shop can have a
                 matching total and still be missing one line's earning while carrying an extra
                 credit that cancels it out — which is exactly the error this screen exists to find,
                 and exactly the one a totals comparison hides. --}}
            <x-sc.alert :tone="$report['reconciles'] ? 'good' : 'critical'" class="mt-3"
                        :title="$report['reconciles'] ? translate('your_books_reconcile') : translate('something_did_not_carry_through')">
                {{ $report['reconciles']
                    ? translate('every_delivered_line_produced_an_earning_and_every_earning_reached_your_ledger')
                    : translate('a_matching_total_is_not_enough_a_missing_earning_and_an_extra_credit_can_cancel_each_other_out') }}
            </x-sc.alert>

            <div class="sc-stats mt-3">
                <x-sc.stat :label="translate('delivered')" :value="number_format($report['delivered']['lines'])"
                           :note="Copy::line('n_orders_worth_x', ['count' => $report['delivered']['orders'], 'value' => number_format($report['delivered']['gross'], 2)])" />
                <x-sc.stat :label="translate('earnings_recorded')" :value="number_format($report['recorded']['net'], 2)"
                           :note="Copy::line('after_n_commission', ['value' => number_format($report['recorded']['commission'], 2)])" />
                <x-sc.stat :label="translate('credited_to_your_ledger')" :value="number_format($report['credited']['amount'], 2)"
                           :note="Copy::line('n_entries', ['count' => $report['credited']['entries']])" />
            </div>

            <div class="sc-grid-two mt-3">
                <x-sc.card :title="translate('delivered_lines_with_no_earning')">
                    @if (($report['gaps']['lines_without_earning']['count'] ?? 0) === 0)
                        <x-sc.empty glyph="check-circle" :title="translate('none')"
                                    :text="translate('every_delivered_line_produced_an_earning')" />
                    @else
                        <p class="sc-muted">
                            {{ Copy::line('n_lines_worth_x_completed_and_nothing_was_recorded_as_owed_to_you', [
                                'count' => $report['gaps']['lines_without_earning']['count'],
                                'value' => number_format($report['gaps']['lines_without_earning']['amount'], 2),
                            ]) }}
                        </p>
                        {{-- A sample, and said to be one: the check counts every line and shows a
                             handful, and printing the handful as though it were the whole list is
                             how a seller concludes the problem is smaller than it is. --}}
                        @foreach (($report['gaps']['lines_without_earning']['sample'] ?? []) as $line)
                            <x-sc.info :label="'#' . $line['order_id']"
                                       :value="Copy::line('n_units_at_x', ['count' => $line['qty'], 'value' => number_format($line['price'], 2)])" />
                        @endforeach
                    @endif
                </x-sc.card>

                <x-sc.card :title="translate('earnings_that_never_reached_the_ledger')">
                    @if (($report['gaps']['earnings_without_credit']['count'] ?? 0) === 0)
                        <x-sc.empty glyph="check-circle" :title="translate('none')"
                                    :text="translate('every_earning_was_credited')" />
                    @else
                        <p class="sc-muted">
                            {{ Copy::line('n_earnings_worth_x_were_recorded_and_never_credited_to_your_balance', [
                                'count' => $report['gaps']['earnings_without_credit']['count'],
                                'value' => number_format($report['gaps']['earnings_without_credit']['amount'], 2),
                            ]) }}
                        </p>
                        @foreach (($report['gaps']['earnings_without_credit']['sample'] ?? []) as $earning)
                            <x-sc.info :label="'#' . $earning['order_id']" :value="number_format($earning['amount'], 2)" />
                        @endforeach
                    @endif
                </x-sc.card>
            </div>
        </div>
    </div>
@endsection
