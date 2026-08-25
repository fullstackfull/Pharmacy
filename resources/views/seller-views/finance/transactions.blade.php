@extends('layouts.seller.app')

@section('title', translate('nav_transactions'))

@php
    use App\Services\SellerCenter\Copy;

    $columns = [
        ['key' => 'when', 'label' => translate('when'), 'width' => 120],
        ['key' => 'type', 'label' => translate('what_it_was')],
        ['key' => 'in', 'label' => translate('in'), 'width' => 110, 'num' => true],
        ['key' => 'out', 'label' => translate('out'), 'width' => 110, 'num' => true],
        ['key' => 'balance', 'label' => translate('balance_after'), 'width' => 130, 'num' => true],
        ['key' => 'status', 'label' => translate('status'), 'width' => 120],
        ['key' => 'link', 'label' => translate('traces_to'), 'width' => 150, 'priority' => 'md'],
    ];
@endphp

@section('content')
    <x-sc.page-header :eyebrow="translate('nav_finance')" :title="translate('every_movement')"
                      :sub="translate('each_line_carries_the_balance_it_left_behind_so_the_account_can_be_followed_in_both_directions')"
                      :back="route('seller.finance.index')" />

    <div class="sc-scroll">
        <div class="sc-page" style="padding-bottom:0">
            {{-- Deliberately NOT filtered with the table below. A seller narrowing to last week
                 still needs to know what they can withdraw today, and an "available" figure that
                 silently meant "available, of last week's entries" would be worse than none. --}}
            <div class="sc-stats">
                <x-sc.stat :label="translate('you_can_withdraw')" :value="number_format($summary['withdrawable'], 2)"
                           :note="translate('the_whole_account_not_this_filter')" />
                <x-sc.stat :label="translate('in_this_range')" :value="number_format($summary['range']['net'], 2)"
                           :note="Copy::line('n_entries', ['count' => $summary['range']['entries']])" />
                <x-sc.stat :label="translate('credited')" :value="number_format($summary['range']['credits'], 2)" tone="good" />
                <x-sc.stat :label="translate('debited')" :value="number_format($summary['range']['debits'], 2)" />
            </div>

            <form method="GET" class="sc-form-row">
                <x-sc.field :label="translate('what_it_was')">
                    <x-sc.select name="entry_type">
                        <option value="">{{ translate('all') }}</option>
                        @foreach ($entryTypes as $type)
                            <option value="{{ $type }}" @selected(($filters['entry_type'] ?? null) === $type)>{{ translate($type) }}</option>
                        @endforeach
                    </x-sc.select>
                </x-sc.field>
                <x-sc.field :label="translate('status')">
                    <x-sc.select name="status">
                        <option value="">{{ translate('all') }}</option>
                        @foreach ($statuses as $status)
                            <option value="{{ $status }}" @selected(($filters['status'] ?? null) === $status)>{{ translate($status) }}</option>
                        @endforeach
                    </x-sc.select>
                </x-sc.field>
                <x-sc.field :label="translate('from')">
                    <x-sc.input type="date" name="from" :value="$filters['from'] ?? ''" />
                </x-sc.field>
                <x-sc.field :label="translate('to')">
                    <x-sc.input type="date" name="to" :value="$filters['to'] ?? ''" />
                </x-sc.field>
                <x-sc.button type="submit" variant="primary">{{ translate('apply') }}</x-sc.button>
                <x-sc.button variant="ghost" :href="route('seller.finance.transactions')">{{ translate('clear') }}</x-sc.button>
            </form>
        </div>

        <x-sc.table :columns="$columns" :state="$state">
            <x-slot:empty>
                <x-sc.empty glyph="receipt" :title="translate('your_ledger_is_empty')"
                            :text="translate('the_first_entry_appears_when_an_order_of_yours_is_delivered')" />
            </x-slot:empty>
            <x-slot:noResults>
                <x-sc.empty glyph="funnel" :title="translate('no_movements_match_these_filters')"
                            :text="translate('adjust_or_clear_the_filters_to_see_more')" />
            </x-slot:noResults>

            @foreach ($rows as $row)
                <x-sc.tr :id="$row['id']">
                    <x-sc.td class="sc-muted">{{ optional($row['created_at'])->format('Y-m-d') }}</x-sc.td>
                    <x-sc.td>
                        {{ translate($row['entry_type']) }}
                        @if ($row['description'])
                            <small class="sc-muted" style="display:block">{{ $row['description'] }}</small>
                        @endif
                    </x-sc.td>
                    <x-sc.td num :tone="$row['credit'] > 0 ? 'good' : null">{{ $row['credit'] > 0 ? number_format($row['credit'], 2) : '—' }}</x-sc.td>
                    <x-sc.td num>{{ $row['debit'] > 0 ? number_format($row['debit'], 2) : '—' }}</x-sc.td>
                    {{-- Read, never recomputed: this is what the balance actually was when the line
                         was written, which is the only version of it a dispute can be settled on. --}}
                    <x-sc.td num>{{ number_format($row['balance_after'], 2) }}</x-sc.td>
                    <x-sc.td><x-sc.badge :status="$row['status']" /></x-sc.td>
                    <x-sc.td drop="md" class="sc-muted">
                        @if ($row['order_id'])
                            <a href="{{ route('seller.orders.show', ['order' => $row['order_id']]) }}">#{{ $row['order_id'] }}</a>
                        @elseif ($row['payout_reference'])
                            {{ $row['payout_reference'] }}
                        @elseif ($row['settlement_reference'])
                            {{ $row['settlement_reference'] }}
                        @else
                            —
                        @endif
                    </x-sc.td>
                </x-sc.tr>
            @endforeach

            <x-slot:mobile>
                @foreach ($rows as $row)
                    <x-sc.entity-card :title="translate($row['entry_type'])"
                                      :figure="$row['credit'] > 0 ? '+ ' . number_format($row['credit'], 2) : '− ' . number_format($row['debit'], 2)"
                                      :meta="optional($row['created_at'])->format('Y-m-d')">
                        <div class="sc-row"><x-sc.badge :status="$row['status']" /></div>
                    </x-sc.entity-card>
                @endforeach
            </x-slot:mobile>

            <x-slot:footer><x-sc.pager :paginator="$entries" /></x-slot:footer>
        </x-sc.table>
    </div>
@endsection
