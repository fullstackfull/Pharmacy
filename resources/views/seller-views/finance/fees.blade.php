@extends('layouts.seller.app')

@section('title', translate('nav_fees'))

@section('content')
    <x-sc.page-header :eyebrow="translate('nav_finance')" :title="translate('what_does_the_marketplace_take')"
                      :sub="translate('work_out_the_commission_on_a_line_before_you_price_it')"
                      :back="route('seller.finance.index')" />

    <div class="sc-scroll">
        <div class="sc-page">
            <x-sc.card :title="translate('the_line_you_are_pricing')">
                <form method="GET" class="sc-form-row">
                    <x-sc.field :label="translate('product_id')" :help="translate('optional_leave_blank_to_price_a_hypothetical_line')">
                        <x-sc.input type="number" name="product_id" min="1" :value="$input['product_id']" />
                    </x-sc.field>
                    <x-sc.field :label="translate('unit_price')">
                        <x-sc.input type="number" step="0.01" min="0" name="unit_price" :value="$input['unit_price']" />
                    </x-sc.field>
                    <x-sc.field :label="translate('quantity')">
                        <x-sc.input type="number" min="1" name="quantity" :value="$input['quantity']" />
                    </x-sc.field>
                    <x-sc.field :label="translate('discount_per_unit')">
                        <x-sc.input type="number" step="0.01" min="0" name="discount" :value="$input['discount']" />
                    </x-sc.field>
                    <x-sc.button type="submit" variant="primary">{{ translate('work_it_out') }}</x-sc.button>
                </form>
            </x-sc.card>

            @if ($asked && $result)
                <div class="sc-stats mt-3">
                    <x-sc.stat :label="translate('gross')" :value="number_format($result['gross'], 2)" />
                    <x-sc.stat :label="translate('commission')" :value="number_format($result['commission_amount'], 2)" />
                    {{-- What the seller is really asking. Null rather than zero when there is nothing
                         to take a share of: a percentage of nothing is undefined, not nought. --}}
                    <x-sc.stat :label="translate('effective_rate')"
                               :value="$result['effective_rate_percent'] === null ? '—' : $result['effective_rate_percent'] . '%'"
                               :note="$result['effective_rate_percent'] === null ? translate('nothing_to_take_a_share_of') : null" />
                    <x-sc.stat :label="translate('you_receive')" :value="number_format($result['seller_receives'], 2)" tone="good" />
                </div>

                <x-sc.card :title="translate('the_rule_that_applied')" class="mt-3">
                    <div class="sc-info-grid">
                        <x-sc.info :label="translate('rule')" :value="$result['rule']['label'] ?? '—'" />
                        <x-sc.info :label="translate('scope')" :value="$result['rule']['scope'] ? translate($result['rule']['scope']) : '—'" />
                        <x-sc.info :label="translate('commissionable_amount')" :value="number_format($result['commissionable_amount'], 2)" />
                        <x-sc.info :label="translate('discount_per_unit')" :value="number_format($result['discount_per_unit'], 2)" />
                    </div>
                </x-sc.card>

                {{-- Named rather than omitted. A fee estimate that quietly leaves out tax and
                     shipping and does not say so is how a seller prices a product at a loss. --}}
                <x-sc.alert tone="info" compact class="mt-3" :title="translate('what_this_figure_does_not_cover')">
                    @foreach ($excludes as $excluded)
                        <span class="sc-chip">{{ translate($excluded) }}</span>
                    @endforeach
                </x-sc.alert>
            @else
                <x-sc.empty glyph="calculator" :title="translate('enter_a_price_to_see_what_the_marketplace_takes')"
                            :text="translate('the_commission_rules_are_the_marketplaces_this_shows_which_one_applies_to_your_line')"
                            class="mt-3" />
            @endif
        </div>
    </div>
@endsection
