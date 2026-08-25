@extends('layouts.seller.app')

@section('title', translate('nav_payouts'))

@php
    $columns = [
        ['key' => 'reference', 'label' => translate('reference'), 'width' => 150],
        ['key' => 'amount', 'label' => translate('amount'), 'width' => 130, 'num' => true],
        ['key' => 'method', 'label' => translate('method'), 'width' => 150, 'priority' => 'md'],
        ['key' => 'status', 'label' => translate('status'), 'width' => 130],
        ['key' => 'asked', 'label' => translate('requested'), 'width' => 130, 'priority' => 'lg'],
    ];
@endphp

@section('content')
    <x-sc.page-header :eyebrow="translate('nav_finance')" :title="translate('payouts')"
                      :sub="translate('what_you_have_asked_for_and_where_each_request_has_got_to')"
                      :back="route('seller.finance.index')">
        <x-slot:actions>
            {{-- Requesting still happens on the classic page, which is a working screen with a form
                 this one deliberately does not duplicate: two forms writing the same reservation is
                 how a seller ends up with two requests for one balance. --}}
            <x-sc.button variant="primary" icon="bank" :href="url('vendor/business-settings/payouts')">
                {{ translate('request_a_payout') }}
            </x-sc.button>
        </x-slot:actions>
    </x-sc.page-header>

    <div class="sc-scroll">
        <div class="sc-page" style="padding-bottom:0">
            <div class="sc-stats">
                <x-sc.stat :label="translate('you_can_withdraw')" :value="number_format($withdrawable, 2)"
                           :tone="$withdrawable > 0 ? 'good' : null"
                           :note="translate('net_of_anything_already_in_flight')" />
            </div>

            @if ($inCoolingPeriod)
                <x-sc.alert tone="medium" class="mt-3" :title="translate('a_cooling_period_is_in_force')">
                    {{ translate('your_bank_details_changed_recently_so_payouts_are_paused_until_the_marketplaces_window_has_passed') }}
                </x-sc.alert>
            @endif
        </div>

        <x-sc.table :columns="$columns" :state="$requests->total() > 0 ? 'normal' : 'empty'">
            <x-slot:empty>
                <x-sc.empty glyph="bank" :title="translate('you_have_not_asked_for_a_payout_yet')"
                            :text="translate('a_request_reserves_the_amount_so_it_cannot_be_spent_twice')" />
            </x-slot:empty>

            @foreach ($requests as $payout)
                <x-sc.tr :id="$payout->id">
                    <x-sc.td class="sc-code">{{ $payout->reference }}</x-sc.td>
                    <x-sc.td num>{{ number_format((float) $payout->amount, 2) }}</x-sc.td>
                    <x-sc.td drop="md" class="sc-muted">{{ $payout->method ? translate($payout->method) : '—' }}</x-sc.td>
                    <x-sc.td><x-sc.badge :status="$payout->status" /></x-sc.td>
                    <x-sc.td drop="lg" class="sc-muted">{{ $payout->created_at?->format('Y-m-d') }}</x-sc.td>
                </x-sc.tr>
            @endforeach

            <x-slot:mobile>
                @foreach ($requests as $payout)
                    <x-sc.entity-card :title="$payout->reference" :figure="number_format((float) $payout->amount, 2)"
                                      :meta="$payout->created_at?->format('Y-m-d')">
                        <div class="sc-row"><x-sc.badge :status="$payout->status" /></div>
                    </x-sc.entity-card>
                @endforeach
            </x-slot:mobile>

            <x-slot:footer><x-sc.pager :paginator="$requests" /></x-slot:footer>
        </x-sc.table>
    </div>
@endsection
