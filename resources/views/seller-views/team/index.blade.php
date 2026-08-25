@extends('layouts.seller.app')

@section('title', translate('nav_team'))

@php
    use App\Services\SellerCenter\Copy;

    $columns = [
        ['key' => 'name', 'label' => translate('name')],
        ['key' => 'email', 'label' => translate('email'), 'priority' => 'md'],
        ['key' => 'role', 'label' => translate('role'), 'width' => 170],
        ['key' => 'status', 'label' => translate('status'), 'width' => 130],
        ['key' => 'signed_in', 'label' => translate('signed_in'), 'width' => 120],
        ['key' => 'last_login', 'label' => translate('last_signed_in'), 'width' => 160, 'priority' => 'lg'],
    ];

    $active = $staff->where('status', 'active');
@endphp

@section('content')
    <x-sc.page-header :eyebrow="translate('nav_organization')" :title="translate('who_works_in_this_shop')"
                      :sub="translate('and_what_each_of_them_may_do')">
        <x-slot:actions>
            <x-sc.button variant="secondary" :href="route('seller.team.roles')">{{ translate('nav_roles') }}</x-sc.button>
            {{-- Adding and editing stay on the classic forms. They work and they are audited, and a
                 second form writing the same role is how two people end up disagreeing about what a
                 permission means. --}}
            <x-sc.button variant="primary" icon="user-plus" :href="url('vendor/business-settings/staff')">
                {{ translate('manage_team') }}
            </x-sc.button>
        </x-slot:actions>
    </x-sc.page-header>

    <div class="sc-scroll">
        <div class="sc-page">
            <div class="sc-stats">
                <x-sc.stat :label="translate('people_with_access')" :value="number_format($active->count())"
                           :note="Copy::line('n_accounts_in_total', ['count' => number_format($staff->count())])" />
                <x-sc.stat :label="translate('roles_defined')" :value="number_format($roles->count())"
                           :note="translate('a_role_is_a_set_of_permissions_a_person_is_given')" />
                <x-sc.stat :label="translate('permissions_available')" :value="number_format(collect($catalog)->flatten()->count())"
                           :note="translate('set_by_the_marketplace_not_by_the_shop')" />
            </div>

            <x-sc.table class="mt-3" :columns="$columns" :state="$state">
                <x-slot:empty>
                    <x-sc.empty glyph="users" :title="translate('you_are_the_only_person_here')"
                                :text="translate('staff_sign_in_with_their_own_credentials_and_see_only_what_their_role_allows')">
                        <x-slot:actions>
                            <x-sc.button variant="primary" :href="url('vendor/business-settings/staff')">
                                {{ translate('add_someone') }}
                            </x-sc.button>
                        </x-slot:actions>
                    </x-sc.empty>
                </x-slot:empty>

                @foreach ($staff as $person)
                    <x-sc.tr :id="$person->id">
                        <x-sc.td>{{ $person->name }}</x-sc.td>
                        <x-sc.td>{{ $person->email }}</x-sc.td>
                        <x-sc.td>
                            @if ($person->role)
                                {{ $person->role->name }}
                            @else
                                {{-- A person with no role holds no permissions at all. Saying so
                                     beats a blank cell that reads as "not loaded". --}}
                                <span class="sc-muted">{{ translate('no_role_no_access') }}</span>
                            @endif
                        </x-sc.td>
                        <x-sc.td><x-sc.badge :status="$person->status" /></x-sc.td>
                        <x-sc.td>
                            {{-- Whether a live credential exists, never the credential. --}}
                            {{ empty($person->auth_token) ? translate('no') : translate('yes') }}
                        </x-sc.td>
                        <x-sc.td>{{ $person->last_login_at?->format('Y-m-d H:i') ?? translate('never') }}</x-sc.td>
                    </x-sc.tr>
                @endforeach
            </x-sc.table>
        </div>
    </div>
@endsection
