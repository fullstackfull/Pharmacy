@extends('layouts.seller.app')

@section('title', translate('nav_sla'))

@php
    use App\Services\SellerCenter\Copy;

    $columns = [
        ['key' => 'metric', 'label' => translate('measure')],
        ['key' => 'actual', 'label' => translate('you_were_at'), 'width' => 130, 'num' => true],
        ['key' => 'threshold', 'label' => translate('the_line'), 'width' => 120, 'num' => true],
        ['key' => 'opened', 'label' => translate('opened'), 'width' => 130],
        ['key' => 'cleared', 'label' => translate('cleared'), 'width' => 130, 'priority' => 'md'],
        ['key' => 'status', 'label' => translate('status'), 'width' => 120],
    ];
@endphp

@section('content')
    <x-sc.page-header :eyebrow="translate('nav_trust')" :title="translate('every_line_crossed_and_every_line_cleared')"
                      :sub="translate('this_is_the_record_a_suspension_would_have_to_rest_on')"
                      :back="route('seller.performance.index')" />

    @if (!$available)
        <div class="sc-scroll"><div class="sc-page">
            <x-sc.empty glyph="warning" :title="translate('sla_tracking_is_not_available_on_this_installation')"
                        :text="translate('the_breach_table_has_not_been_created_ask_the_marketplace_to_run_its_migrations')" />
        </div></div>
    @else
        <div class="sc-scroll">
            <div class="sc-page" style="padding-bottom:0">
                <div class="sc-stats">
                    <x-sc.stat :label="translate('currently_over_a_line')" :value="number_format($open)"
                               :tone="$open > 0 ? 'critical' : 'good'"
                               :note="$open > 0 ? translate('act_on_these_before_the_marketplace_does') : translate('you_are_inside_every_line')" />
                    @foreach (['cancellation_rate', 'return_rate', 'refund_rate'] as $metric)
                        <x-sc.stat :label="translate($metric)" :value="number_format((float) $thresholds[$metric] * 100, 1) . '%'"
                                   :note="translate('the_marketplaces_ceiling')" />
                    @endforeach
                </div>
            </div>

            <x-sc.table :columns="$columns" :state="$state">
                <x-slot:empty>
                    <x-sc.empty glyph="seal-check" :title="translate('you_have_never_crossed_a_line')"
                                :text="translate('a_breach_is_opened_when_a_measure_goes_past_the_marketplaces_limit_and_cleared_when_it_comes_back')" />
                </x-slot:empty>

                @foreach ($breaches as $breach)
                    <x-sc.tr :id="$breach->id">
                        <x-sc.td>{{ translate($breach->metric) }}</x-sc.td>
                        <x-sc.td num tone="critical">{{ number_format((float) $breach->actual_value, 4) }}</x-sc.td>
                        <x-sc.td num class="sc-muted">{{ number_format((float) $breach->threshold, 4) }}</x-sc.td>
                        <x-sc.td class="sc-muted">{{ $breach->created_at?->format('Y-m-d') }}</x-sc.td>
                        <x-sc.td drop="md" class="sc-muted">{{ $breach->cleared_at?->format('Y-m-d') ?? '—' }}</x-sc.td>
                        <x-sc.td><x-sc.badge :status="$breach->status" /></x-sc.td>
                    </x-sc.tr>
                @endforeach

                <x-slot:mobile>
                    @foreach ($breaches as $breach)
                        <x-sc.entity-card :title="translate($breach->metric)"
                                          :figure="number_format((float) $breach->actual_value, 4)"
                                          :meta="$breach->created_at?->format('Y-m-d')">
                            <div class="sc-row"><x-sc.badge :status="$breach->status" /></div>
                        </x-sc.entity-card>
                    @endforeach
                </x-slot:mobile>

                <x-slot:footer><x-sc.pager :paginator="$breaches" /></x-slot:footer>
            </x-sc.table>
        </div>
    @endif
@endsection
