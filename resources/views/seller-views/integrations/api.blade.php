@extends('layouts.seller.app')

@section('title', translate('nav_api_keys'))

@php
    use App\Services\SellerCenter\Copy;

    $columns = [
        ['key' => 'name', 'label' => translate('name')],
        ['key' => 'prefix', 'label' => translate('key'), 'width' => 130],
        ['key' => 'scopes', 'label' => translate('what_it_may_do')],
        ['key' => 'last_used', 'label' => translate('last_used'), 'width' => 160],
        ['key' => 'state', 'label' => translate('status'), 'width' => 130],
        ['key' => 'action', 'label' => '', 'width' => 90],
    ];
@endphp

@section('content')
    <x-sc.page-header :eyebrow="translate('nav_platform')" :title="translate('nav_api_keys')"
                      :sub="translate('a_key_acts_as_the_whole_shop_within_its_scopes')"
                      :back="route('seller.integrations.index')" />

    <div class="sc-scroll">
        <div class="sc-page">
            @if ($issued)
                {{-- The one and only time this string exists on a screen. There is no endpoint and
                     no page that can return it again, and the marketplace cannot recover it. --}}
                <x-sc.alert tone="good" :title="translate('copy_this_key_now')">
                    <p>{{ translate('it_is_shown_once_and_stored_only_as_a_hash_if_you_lose_it_issue_another_and_revoke_this_one') }}</p>
                    <p class="sc-code sc-no-print">{{ $issued['plaintext'] }}</p>
                </x-sc.alert>
            @endif

            <x-sc.card class="mt-3" :title="translate('issue_a_key')">
                <form method="POST" action="{{ route('seller.integrations.api.store') }}">
                    @csrf
                    <div class="sc-form-row">
                        <x-sc.field :label="translate('name')" required
                                    :help="translate('what_this_key_is_for_so_it_can_be_recognised_later')"
                                    :error="$errors->first('name')">
                            <x-sc.input name="name" required maxlength="120" :invalid="$errors->has('name')" />
                        </x-sc.field>

                        <x-sc.field :label="translate('expires')" :help="translate('optional_a_key_with_no_expiry_works_until_it_is_revoked')"
                                    :error="$errors->first('expires_at')">
                            <x-sc.input type="date" name="expires_at" :invalid="$errors->has('expires_at')" />
                        </x-sc.field>
                    </div>

                    <x-sc.field :label="translate('what_it_may_do')"
                                :help="translate('a_key_can_never_be_given_more_than_the_person_issuing_it_holds')">
                        <div class="sc-row">
                            @foreach ($catalog as $group => $permissions)
                                @foreach ($permissions as $permission)
                                    @continue(!in_array($permission, $grantable, true))
                                    <label class="sc-check">
                                        <input type="checkbox" name="scopes[]" value="{{ $permission }}">
                                        <span>{{ translate($permission) }}</span>
                                    </label>
                                @endforeach
                            @endforeach
                        </div>
                    </x-sc.field>

                    <div class="sc-form-footer">
                        <x-sc.button variant="primary" type="submit" icon="key">{{ translate('issue_a_key') }}</x-sc.button>
                    </div>
                </form>
            </x-sc.card>

            <x-sc.table class="mt-3" :columns="$columns" :state="$state">
                <x-slot:empty>
                    <x-sc.empty glyph="key" :title="translate('no_keys_yet')"
                                :text="translate('a_key_lets_your_own_systems_read_and_write_here_without_a_person_signing_in')" />
                </x-slot:empty>

                @foreach ($keys as $key)
                    <x-sc.tr :id="$key->id">
                        <x-sc.td>{{ $key->name }}</x-sc.td>
                        {{-- The prefix identifies the key. The key itself is not stored. --}}
                        <x-sc.td><span class="sc-code">{{ $key->prefix }}</span></x-sc.td>
                        <x-sc.td>
                            @if (empty($key->scopes))
                                <span class="sc-muted">{{ translate('nothing_a_key_with_no_scopes_can_read_nothing') }}</span>
                            @else
                                {{ collect($key->scopes)->map(fn ($scope) => translate($scope))->join(translate('list_separator') . ' ') }}
                            @endif
                        </x-sc.td>
                        <x-sc.td :sub="$key->last_used_ip">
                            {{ $key->last_used_at?->format('Y-m-d H:i') ?? translate('never_used') }}
                        </x-sc.td>
                        <x-sc.td>
                            @if ($key->revoked_at)
                                <x-sc.badge status="revoked" />
                            @elseif ($key->expires_at && $key->expires_at->isPast())
                                <x-sc.badge status="expired" />
                            @else
                                <x-sc.badge status="active" />
                            @endif
                        </x-sc.td>
                        <x-sc.td action>
                            @unless ($key->revoked_at)
                                <form method="POST" action="{{ route('seller.integrations.api.revoke', ['key' => $key->id]) }}"
                                      data-sc-confirm="{{ translate('revoke_this_key_anything_using_it_stops_working_on_its_very_next_request') }}">
                                    @csrf
                                    @method('DELETE')
                                    <x-sc.button variant="ghost" size="sm" type="submit">{{ translate('revoke') }}</x-sc.button>
                                </form>
                            @endunless
                        </x-sc.td>
                    </x-sc.tr>
                @endforeach
            </x-sc.table>
        </div>
    </div>
@endsection
