@extends('layouts.seller.app')

@section('title', translate('nav_seller_performance'))

@php
    use App\Services\SellerCenter\Copy;

    $pct = fn ($value) => number_format((float) $value * 100, 1) . '%';
@endphp

@section('content')
    <x-sc.page-header :eyebrow="translate('nav_trust')" :title="translate('how_this_shop_is_performing')"
                      :sub="translate('the_same_metrics_the_marketplace_reads_derived_by_the_same_code')">
        <x-slot:actions>
            <x-sc.button variant="secondary" :href="route('seller.performance.health')">{{ translate('nav_account_health') }}</x-sc.button>
            <x-sc.button variant="secondary" :href="route('seller.performance.sla')">{{ translate('nav_sla') }}</x-sc.button>
        </x-slot:actions>
    </x-sc.page-header>

    <div class="sc-scroll">
        <div class="sc-page">
            {{-- A shop with no orders and no reviews is "new", never good or at risk: judging a
                 seller who has not traded yet would be noise presented as a verdict. --}}
            <x-sc.alert :tone="['good' => 'good', 'watch' => 'medium', 'at_risk' => 'critical'][$scorecard['tier']] ?? 'info'"
                        :title="translate('tier_' . $scorecard['tier'])">
                {{ translate('tier_' . $scorecard['tier'] . '_explained') }}
            </x-sc.alert>

            <div class="sc-stats mt-3">
                <x-sc.stat :label="translate('orders')" :value="number_format($scorecard['orders_total'])"
                           :note="Copy::line('n_delivered', ['count' => number_format($scorecard['delivered'])])" />
                <x-sc.stat :label="translate('fulfilment_rate')" :value="$pct($scorecard['fulfillment_rate'])"
                           :note="translate('delivered_out_of_everything_ordered')" />
                <x-sc.stat :label="translate('cancellation_rate')" :value="$pct($scorecard['cancellation_rate'])"
                           :tone="$scorecard['cancellation_rate'] > $thresholds['cancellation_rate'] ? 'critical' : null"
                           :note="Copy::line('ceiling_is_x', ['value' => $pct($thresholds['cancellation_rate'])])" />
                <x-sc.stat :label="translate('return_rate')" :value="$pct($scorecard['return_rate'])"
                           :tone="$scorecard['return_rate'] > $thresholds['return_rate'] ? 'critical' : null"
                           :note="Copy::line('ceiling_is_x', ['value' => $pct($thresholds['return_rate'])])" />
                <x-sc.stat :label="translate('refund_rate')" :value="$pct($scorecard['refund_rate'])"
                           :tone="$scorecard['refund_rate'] > $thresholds['refund_rate'] ? 'critical' : null"
                           :note="Copy::line('ceiling_is_x', ['value' => $pct($thresholds['refund_rate'])])" />
                {{-- Null rather than zero when nobody has reviewed: an unrated shop is not a
                     zero-star shop, and rendering it as one is a verdict nobody reached. --}}
                <x-sc.stat :label="translate('average_rating')"
                           :value="$scorecard['avg_rating'] === null ? '—' : number_format((float) $scorecard['avg_rating'], 2)"
                           :note="Copy::line('from_n_reviews', ['count' => number_format($scorecard['review_count'])])" />
            </div>

            @if ($openBreaches->isNotEmpty())
                <x-sc.card :title="translate('lines_you_are_currently_over')" class="mt-3">
                    @foreach ($openBreaches as $breach)
                        <x-sc.info :label="translate($breach->metric)"
                                   :value="Copy::line('x_against_a_limit_of_y', [
                                       'actual' => number_format((float) $breach->actual_value, 4),
                                       'limit' => number_format((float) $breach->threshold, 4),
                                   ])" tone="critical" />
                    @endforeach
                    <x-sc.button variant="secondary" size="sm" class="mt-2" :href="route('seller.performance.health')">
                        {{ translate('what_would_clear_this') }}
                    </x-sc.button>
                </x-sc.card>
            @endif
        </div>
    </div>
@endsection
