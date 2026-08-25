@extends('layouts.seller.app')

@section('title', translate('nav_action_center'))

@php
    use App\Services\SellerCenter\Copy;

    $tabs = collect(array_merge(['all'], $severities))->map(fn ($key) => [
        'key' => $key,
        'label' => translate($key),
        'href' => $key === 'all' ? route('seller.actions') : route('seller.actions', ['severity' => $key]),
        'tone' => $key === 'critical' ? 'critical' : ($key === 'high' ? 'high' : null),
    ])->values()->all();
@endphp

@section('content')
    <x-sc.page-header :eyebrow="translate('nav_home')" :title="translate('everything_waiting_for_you')"
                      :sub="translate('worst_first_each_with_the_one_thing_to_do_about_it')" />

    <x-sc.tabs :tabs="$tabs" :current="$severity ?? 'all'" />

    <div class="sc-scroll">
        <div class="sc-page">
            @if ($insights->isEmpty())
                {{-- An empty Action Center is good news and is written as such. Entries are produced
                     from real records only — nothing is invented to fill the screen, which is why
                     this state can be trusted rather than read as a failed load. --}}
                <x-sc.empty glyph="check-circle"
                            :title="$severity ? translate('nothing_at_this_level') : translate('nothing_needs_your_attention')"
                            :text="$severity
                                ? translate('try_another_level_or_clear_the_filter')
                                : translate('this_screen_only_ever_shows_things_drawn_from_your_real_records')" />
            @else
                <div class="sc-stats">
                    @foreach ($severities as $level)
                        <x-sc.stat :label="translate($level)" :value="number_format($counts[$level] ?? 0)"
                                   :tone="($counts[$level] ?? 0) > 0 && in_array($level, ['critical', 'high'], true) ? $level : null" />
                    @endforeach
                </div>

                <div class="sc-stack mt-3">
                    @foreach ($insights as $insight)
                        <x-sc.card :label="translate($insight->type)">
                            <x-slot:context><x-sc.badge :severity="$insight->severity" /></x-slot:context>

                            <h4 class="sc-card__title">{{ $insight->title }}</h4>
                            <p class="sc-muted">{{ $insight->body }}</p>

                            @if ($insight->impact)
                                <x-sc.info :label="translate('what_it_is_costing')" :value="$insight->impact" />
                            @endif

                            <div class="sc-row mt-2">
                                @if ($insight->entity_type === 'order' && $insight->entity_id)
                                    <x-sc.button variant="primary" size="sm"
                                                 :href="route('seller.orders.show', ['order' => $insight->entity_id])">
                                        {{ translate('open_the_order') }}
                                    </x-sc.button>
                                @elseif ($insight->entity_type === 'product' && $insight->entity_id)
                                    <x-sc.button variant="primary" size="sm"
                                                 :href="url('vendor/products/update/' . $insight->entity_id)">
                                        {{ translate('open_the_product') }}
                                    </x-sc.button>
                                @endif

                                {{-- Critical standing cannot be hidden. A seller may choose not to
                                     act on a suggestion, but not to hide that their account is at
                                     risk — so the control is absent rather than disabled. --}}
                                @if ($insight->severity !== 'critical')
                                    <form method="POST" action="{{ route('seller.actions.dismiss', ['insight' => $insight->id]) }}">
                                        @csrf
                                        <button type="submit" class="sc-btn sc-btn--ghost sc-btn--sm">{{ translate('dismiss') }}</button>
                                    </form>
                                @endif
                            </div>
                        </x-sc.card>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
@endsection
