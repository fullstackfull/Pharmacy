@extends('layouts.seller.app')

@section('title', translate('nav_account_health'))

@php
    use App\Services\SellerCenter\Copy;

    $pct = fn ($value) => number_format((float) $value * 100, 1) . '%';

    /*
     | Every line, including the ones comfortably inside. A tier with no marking scheme is a grade
     | with no explanation, and showing only the breached lines tells a seller what is wrong without
     | telling them what "right" looks like.
     */
    $lines = [
        ['metric' => 'cancellation_rate', 'kind' => 'ceiling'],
        ['metric' => 'return_rate', 'kind' => 'ceiling'],
        ['metric' => 'refund_rate', 'kind' => 'ceiling'],
        ['metric' => 'avg_rating', 'kind' => 'floor'],
    ];
@endphp

@section('content')
    <x-sc.page-header :eyebrow="translate('nav_trust')" :title="translate('account_health')"
                      :sub="translate('what_the_marketplace_concludes_and_what_it_would_take_to_change_it')"
                      :back="route('seller.performance.index')" />

    <div class="sc-scroll">
        <div class="sc-page">
            <x-sc.alert :tone="['good' => 'good', 'watch' => 'medium', 'at_risk' => 'critical'][$scorecard['tier']] ?? 'info'"
                        :title="translate('tier_' . $scorecard['tier'])">
                {{ translate('tier_' . $scorecard['tier'] . '_explained') }}
            </x-sc.alert>

            <x-sc.card :title="translate('every_line_you_are_measured_against')" class="mt-3">
                <div class="sc-table-wrap">
                    <table class="sc-table">
                        <thead><tr>
                            <th>{{ translate('measure') }}</th>
                            <th class="sc-cell--num">{{ translate('you') }}</th>
                            <th class="sc-cell--num">{{ translate('the_line') }}</th>
                            <th>{{ translate('standing') }}</th>
                        </tr></thead>
                        <tbody>
                        @foreach ($lines as $line)
                            @php($metric = $line['metric'])
                            @php($actual = $scorecard[$metric] ?? null)
                            @php($limit = $thresholds[$metric])
                            @php($breached = $breaches->has($metric))
                            <tr>
                                <td>{{ translate($metric) }}</td>
                                <td class="sc-cell--num">
                                    @if ($actual === null)
                                        —
                                    @else
                                        {{ $line['kind'] === 'ceiling' ? $pct($actual) : number_format((float) $actual, 2) }}
                                    @endif
                                </td>
                                <td class="sc-cell--num">
                                    {{ $line['kind'] === 'ceiling' ? $pct($limit) : number_format((float) $limit, 2) }}
                                </td>
                                <td>
                                    @if ($actual === null)
                                        {{-- Not measured is not "met". A rating with too few reviews
                                             behind it is a line this seller is not yet judged on. --}}
                                        <span class="sc-muted">{{ translate('not_measured_yet') }}</span>
                                    @else
                                        <x-sc.badge :status="$breached ? 'breached' : 'met'" />
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </x-sc.card>

            <div class="sc-grid-two mt-3">
                <x-sc.card :title="translate('identity_verification')">
                    <x-sc.info :label="translate('status')">
                        <x-sc.badge :status="$verification" />
                    </x-sc.info>
                    <p class="sc-muted">{{ translate('verification_gates_payouts_it_does_not_gate_selling') }}</p>
                    <x-sc.button variant="secondary" size="sm" :href="route('seller.compliance.index')">
                        {{ translate('nav_compliance') }}
                    </x-sc.button>
                </x-sc.card>

                <x-sc.card :title="translate('how_long_you_have_to_get_an_order_moving')">
                    <x-sc.info :label="translate('processing_window')"
                               :value="$processingWindowHours === null ? translate('not_set') : Copy::line('n_hours', ['count' => $processingWindowHours])" />
                    {{-- A promise made to customers and held against sellers, so the number comes
                         from whoever runs the market rather than from this screen. --}}
                    <p class="sc-muted">{{ translate('the_clock_runs_only_while_the_order_still_needs_something_from_you') }}</p>
                </x-sc.card>
            </div>
        </div>
    </div>
@endsection
