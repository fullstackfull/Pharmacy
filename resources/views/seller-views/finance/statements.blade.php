@extends('layouts.seller.app')

@section('title', translate('nav_statements'))

@php
    use App\Services\SellerCenter\Copy;
    $range = $summary['range'];
@endphp

@section('content')
    <x-sc.page-header :eyebrow="translate('nav_finance')" :title="translate('statement')"
                      :sub="translate('the_same_ledger_read_as_a_document_rather_than_as_a_list')"
                      :back="route('seller.finance.index')">
        <x-slot:actions>
            {{-- Printing rather than a generated PDF: the browser already renders this correctly and
                 a second rendering path is a second thing that can disagree with the ledger. --}}
            <x-sc.button variant="secondary" icon="printer" onclick="window.print()">{{ translate('print') }}</x-sc.button>
        </x-slot:actions>
    </x-sc.page-header>

    <div class="sc-scroll">
        <div class="sc-page">
            <form method="GET" class="sc-form-row sc-no-print">
                <x-sc.field :label="translate('from')"><x-sc.input type="date" name="from" :value="$filters['from'] ?? ''" /></x-sc.field>
                <x-sc.field :label="translate('to')"><x-sc.input type="date" name="to" :value="$filters['to'] ?? ''" /></x-sc.field>
                <x-sc.button type="submit" variant="primary">{{ translate('apply') }}</x-sc.button>
            </form>

            <x-sc.card :title="translate('summary')" class="mt-3">
                <div class="sc-info-grid">
                    <x-sc.info :label="translate('entries')" :value="number_format($range['entries'])" />
                    <x-sc.info :label="translate('credited')" :value="number_format($range['credits'], 2)" />
                    <x-sc.info :label="translate('debited')" :value="number_format($range['debits'], 2)" />
                    <x-sc.info :label="translate('net')" :value="number_format($range['net'], 2)" />
                    <x-sc.info :label="translate('you_can_withdraw')" :value="number_format($summary['withdrawable'], 2)" />
                    <x-sc.info :label="translate('currency')" :value="$summary['currency'] ?? '—'" />
                </div>
            </x-sc.card>

            <x-sc.card :title="translate('entries')" class="mt-3" flush>
                @if ($rows === [])
                    <x-sc.empty glyph="receipt" :title="translate('nothing_in_this_range')"
                                :text="translate('widen_the_dates_to_see_more')" />
                @else
                    <div class="sc-table-wrap">
                        <table class="sc-table">
                            <thead><tr>
                                <th>{{ translate('when') }}</th>
                                <th>{{ translate('what_it_was') }}</th>
                                <th class="sc-cell--num">{{ translate('in') }}</th>
                                <th class="sc-cell--num">{{ translate('out') }}</th>
                                <th class="sc-cell--num">{{ translate('balance_after') }}</th>
                                <th>{{ translate('status') }}</th>
                            </tr></thead>
                            <tbody>
                            @foreach ($rows as $row)
                                <tr>
                                    <td>{{ optional($row['created_at'])->format('Y-m-d') }}</td>
                                    <td>
                                        {{ translate($row['entry_type']) }}
                                        @if ($row['description'])<small class="sc-muted" style="display:block">{{ $row['description'] }}</small>@endif
                                    </td>
                                    <td class="sc-cell--num">{{ $row['credit'] > 0 ? number_format($row['credit'], 2) : '—' }}</td>
                                    <td class="sc-cell--num">{{ $row['debit'] > 0 ? number_format($row['debit'], 2) : '—' }}</td>
                                    <td class="sc-cell--num">{{ number_format($row['balance_after'], 2) }}</td>
                                    <td>{{ translate($row['status']) }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                    {{-- Said rather than silently truncated: a statement that stops at two hundred
                         lines without saying so is a statement somebody will reconcile against. --}}
                    <p class="sc-muted" style="padding:8px 12px">
                        {{ Copy::line('showing_the_most_recent_n_entries_in_this_range', ['count' => count($rows)]) }}
                    </p>
                @endif
            </x-sc.card>
        </div>
    </div>
@endsection
