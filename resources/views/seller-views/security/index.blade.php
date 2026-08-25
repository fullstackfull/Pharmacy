@extends('layouts.seller.app')

@section('title', translate('nav_security'))

@php
    use App\Models\SellerApiKey;
    use App\Services\SellerCenter\Copy;

    $columns = [
        ['key' => 'when', 'label' => translate('when'), 'width' => 160],
        ['key' => 'action', 'label' => translate('action'), 'width' => 220],
        ['key' => 'actor', 'label' => translate('who'), 'width' => 180],
        ['key' => 'subject', 'label' => translate('on_what')],
        ['key' => 'ip', 'label' => translate('from'), 'width' => 140, 'priority' => 'lg'],
    ];

    $live = collect($holders)->where('signed_in', true);

    $trailTabs = collect($filters)
        ->map(fn (string $filter) => [
            'key' => $filter,
            'label' => translate('trail_' . str_replace(['.', '-'], '_', $filter)),
            'href' => route('seller.security.index', ['action' => $filter]),
        ])
        ->prepend(['key' => 'all', 'label' => translate('everything'), 'href' => route('seller.security.index')])
        ->all();
@endphp

@section('content')
    <x-sc.page-header :eyebrow="translate('nav_organization')" :title="translate('who_can_act_as_this_shop')"
                      :sub="translate('read_from_the_credentials_themselves_rather_than_from_a_list_of_accounts')" />

    <div class="sc-scroll">
        <div class="sc-page">
            <div class="sc-stats">
                <x-sc.stat :label="translate('people_who_can_sign_in')" :value="number_format(count($holders))"
                           :note="Copy::line('n_hold_a_live_session', ['count' => $live->count()])" />
                <x-sc.stat :label="translate('api_keys_that_still_work')" :value="number_format($keys->count())"
                           :note="translate('a_key_acts_as_the_whole_shop_within_its_scopes')" />
                <x-sc.stat :label="translate('recorded_actions')" :value="number_format($total)"
                           :note="translate('everything_done_in_this_shops_name')" />
            </div>

            <div class="sc-grid-two mt-3">
                <x-sc.card :title="translate('people')">
                    @foreach ($holders as $holder)
                        <x-sc.info :label="$holder['name'] ?? translate('unnamed')">
                            <x-sc.badge :status="$holder['type'] === 'owner' ? 'owner' : $holder['status']" />
                            <span class="sc-muted">
                                {{ $holder['role'] ?? ($holder['type'] === 'owner' ? translate('full_access') : translate('no_role_no_access')) }}
                            </span>
                            @if ($holder['signed_in'])
                                <x-sc.badge status="signed_in" :label="translate('signed_in_now')" />
                            @endif
                        </x-sc.info>
                    @endforeach
                </x-sc.card>

                <x-sc.card :title="translate('keys')">
                    <x-slot:context>
                        <a href="{{ route('seller.integrations.api') }}">{{ translate('manage') }}</a>
                    </x-slot:context>

                    @if ($keys->isEmpty())
                        <x-sc.empty glyph="key" :title="translate('no_key_can_act_as_this_shop')"
                                    :text="translate('revoked_and_expired_keys_are_left_out_a_key_that_cannot_act_is_not_an_answer_to_who_can')" />
                    @else
                        @foreach ($keys as $key)
                            <x-sc.info :label="$key->name">
                                <span class="sc-code">{{ $key->prefix }}</span>
                                <span class="sc-muted">
                                    {{-- Real traffic, not the creation date. It is what makes "is
                                         this key still needed" answerable. --}}
                                    {{ $key->last_used_at
                                        ? Copy::line('last_used_on_x', ['date' => $key->last_used_at->format('Y-m-d')])
                                        : translate('never_used') }}
                                </span>
                            </x-sc.info>
                        @endforeach
                    @endif
                </x-sc.card>
            </div>

            {{-- The trail is read by area, not in full: somebody asks "who changed the automation
                 rules", never "show me everything". --}}
            <x-sc.tabs class="mt-3" inline :current="$currentFilter ?? 'all'" :tabs="$trailTabs" />

            <x-sc.table :columns="$columns" :state="$state"
                        :note="Copy::line('showing_the_most_recent_n_of_m', ['shown' => count($entries), 'total' => number_format($total)])">
                <x-slot:empty>
                    <x-sc.empty glyph="clock-counter-clockwise" :title="translate('nothing_has_been_recorded_yet')"
                                :text="translate('actions_taken_by_you_or_your_staff_appear_here_as_they_happen')" />
                </x-slot:empty>
                <x-slot:noResults>
                    <x-sc.empty glyph="funnel" :title="translate('nothing_in_this_area')"
                                :text="translate('choose_everything_to_see_the_whole_trail')" />
                </x-slot:noResults>

                @foreach ($entries as $entry)
                    <x-sc.tr :id="$entry['id']">
                        <x-sc.td>{{ \Illuminate\Support\Carbon::parse($entry['created_at'])->format('Y-m-d H:i') }}</x-sc.td>
                        <x-sc.td><span class="sc-code">{{ $entry['action'] }}</span></x-sc.td>
                        <x-sc.td :sub="translate($entry['actor_type'] ?? 'system')">
                            {{-- Somebody who has since left still appears here. That is exactly when
                                 a seller wants to look. --}}
                            {{ $entry['actor_name'] ?? translate('the_platform') }}
                        </x-sc.td>
                        <x-sc.td>
                            {{ $entry['subject_type'] ? class_basename($entry['subject_type']) : '—' }}
                            @if ($entry['subject_id'])<span class="sc-muted">#{{ $entry['subject_id'] }}</span>@endif
                        </x-sc.td>
                        <x-sc.td>{{ $entry['ip_address'] ?? '—' }}</x-sc.td>
                    </x-sc.tr>
                @endforeach
            </x-sc.table>
        </div>
    </div>
@endsection
