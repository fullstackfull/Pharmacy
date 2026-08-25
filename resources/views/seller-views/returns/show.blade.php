@extends('layouts.seller.app')

@section('title', $rma->reference)

@php
    use App\Models\ReturnShipment;
    use App\Services\SellerCenter\Copy;

    $open = in_array($rma->status, ['authorized', 'in_transit'], true);
    $arrived = $rma->status === 'received';
@endphp

@section('content')
    <x-sc.page-header :eyebrow="translate('nav_returns')" :title="$rma->reference"
                      :sub="Copy::line('return_for_order_n', ['order' => '#' . $rma->order_id])"
                      :back="route('seller.returns.index')" />

    <div class="sc-scroll">
        <div class="sc-page">
            <div class="sc-grid-two">
                <x-sc.card :title="translate('the_return')">
                    <x-sc.info :label="translate('status')">
                        <x-sc.badge :status="$rma->status" />
                    </x-sc.info>
                    <x-sc.info :label="translate('product')" :value="$name ?? translate('product_no_longer_listed')" />
                    <x-sc.info :label="translate('units')" :value="number_format((int) $rma->qty)" />
                    <x-sc.info :label="translate('reason')" :value="$rma->reason ? translate($rma->reason) : '—'" />
                    <x-sc.info :label="translate('carrier')" :value="$rma->carrier ?: '—'" />
                    <x-sc.info :label="translate('tracking_number')" :value="$rma->tracking_number ?: '—'" />
                    @if ($rma->note)
                        <x-sc.info :label="translate('note')" :value="$rma->note" />
                    @endif
                </x-sc.card>

                {{-- The ledger lines the refund produced, shown as two lines rather than one net
                     figure. The commission credit is the one sellers never see and most doubt
                     exists; netting it away is how it stays invisible. --}}
                <x-sc.card :title="translate('what_the_refund_did_to_your_balance')">
                    @if ($ledger->isEmpty())
                        <x-sc.empty glyph="receipt" :title="translate('no_ledger_lines_for_this_return')"
                                    :text="translate('lines_appear_once_the_refund_itself_is_settled')" />
                    @else
                        <x-sc.timeline>
                            @foreach ($ledger as $entry)
                                <x-sc.timeline-item :tone="$entry->credit > 0 ? 'good' : 'medium'"
                                                    :time="$entry->created_at?->format('Y-m-d')"
                                                    :meta="translate($entry->status)">
                                    <strong>{{ translate($entry->entry_type) }}</strong>
                                    —
                                    @if ($entry->credit > 0)
                                        + {{ number_format((float) $entry->credit, 2) }}
                                    @else
                                        − {{ number_format((float) $entry->debit, 2) }}
                                    @endif
                                    @if ($entry->description)
                                        <div class="sc-muted">{{ $entry->description }}</div>
                                    @endif
                                </x-sc.timeline-item>
                            @endforeach
                        </x-sc.timeline>
                    @endif
                </x-sc.card>
            </div>

            @if ($open || $arrived)
                <x-sc.card :title="translate('what_happens_next')" class="mt-3">
                    @if ($rma->status === 'authorized')
                        <form method="POST" action="{{ route('seller.returns.in-transit', ['rma' => $rma->id]) }}" class="sc-form-row">
                            @csrf
                            <x-sc.field :label="translate('carrier')">
                                <x-sc.input name="carrier" maxlength="120" :placeholder="translate('who_is_bringing_it_back')" />
                            </x-sc.field>
                            <x-sc.field :label="translate('tracking_number')">
                                <x-sc.input name="tracking_number" maxlength="120" />
                            </x-sc.field>
                            <x-sc.button type="submit" variant="primary" icon="truck">{{ translate('mark_on_its_way') }}</x-sc.button>
                        </form>
                    @endif

                    @if ($rma->status === 'in_transit' || $arrived)
                        {{-- The restock decision belongs at receipt, not at authorisation: nobody
                             knows whether goods are sellable until somebody has looked at them. --}}
                        <form method="POST" action="{{ route('seller.returns.receive', ['rma' => $rma->id]) }}" class="sc-form-row">
                            @csrf
                            <label class="sc-check">
                                <input type="checkbox" name="restock" value="1" checked>
                                <span>{{ translate('put_these_units_back_into_stock') }}</span>
                            </label>
                            <x-sc.button type="submit" variant="primary" icon="check">{{ translate('mark_received') }}</x-sc.button>
                        </form>
                    @endif

                    <form method="POST" action="{{ route('seller.returns.reject', ['rma' => $rma->id]) }}" class="sc-form-row">
                        @csrf
                        <x-sc.field :label="translate('reason_for_refusing')" required
                                    :help="translate('a_refusal_the_customer_cannot_be_told_the_grounds_for_is_not_a_decision')">
                            <x-sc.input name="reason" maxlength="255" required />
                        </x-sc.field>
                        <x-sc.button type="submit" variant="danger" icon="x">{{ translate('refuse_this_return') }}</x-sc.button>
                    </form>
                </x-sc.card>
            @endif
        </div>
    </div>
@endsection
