@extends('layouts.seller.app')

@section('title', translate('nav_roles'))

@php
    use App\Models\SellerRole;
    use App\Services\SellerCenter\Copy;

    $permissionKeys = collect($catalog)->flatten()->values();
    $holders = $staff->groupBy('seller_role_id');
@endphp

@section('content')
    <x-sc.page-header :eyebrow="translate('nav_organization')" :title="translate('what_each_role_actually_grants')"
                      :sub="translate('a_grid_is_the_only_form_in_which_two_roles_that_are_the_same_role_are_visible')"
                      :back="route('seller.team.index')">
        <x-slot:actions>
            <x-sc.button variant="primary" :href="url('vendor/business-settings/staff')">{{ translate('manage_roles') }}</x-sc.button>
        </x-slot:actions>
    </x-sc.page-header>

    <div class="sc-scroll">
        <div class="sc-page">
            @if ($roles->isEmpty())
                <x-sc.empty glyph="shield-check" :title="translate('no_roles_defined')"
                            :text="translate('until_a_role_exists_only_you_can_act_as_this_shop')">
                    <x-slot:actions>
                        <x-sc.button variant="primary" :href="url('vendor/business-settings/staff')">
                            {{ translate('create_a_role') }}
                        </x-sc.button>
                    </x-slot:actions>
                </x-sc.empty>
            @else
                <div class="sc-table-wrap">
                    <table class="sc-table">
                        <thead>
                            <tr>
                                <th scope="col">{{ translate('permission') }}</th>
                                @foreach ($roles as $role)
                                    <th scope="col" class="sc-cell--num">
                                        {{ $role->name }}
                                        <div class="sc-subline">{{ Copy::line('n_people', ['count' => $holders->get($role->id)?->count() ?? 0]) }}</div>
                                    </th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($catalog as $group => $permissions)
                                <tr>
                                    <td colspan="{{ $roles->count() + 1 }}" class="sc-muted">{{ translate($group) }}</td>
                                </tr>
                                @foreach ($permissions as $permission)
                                    <tr>
                                        <td>{{ translate($permission) }}</td>
                                        @foreach ($roles as $role)
                                            <td class="sc-cell--num">
                                                @if ($role->grants($permission))
                                                    <x-sc.icon name="check" :size="14" />
                                                @else
                                                    <span class="sc-muted">—</span>
                                                @endif
                                            </td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @php($unused = $roles->filter(fn (SellerRole $role) => ($holders->get($role->id)?->count() ?? 0) === 0))
                @if ($unused->isNotEmpty())
                    {{-- Not a fault, but the thing an access review is looking for: a role nobody
                         holds is a permission set nobody is checking. --}}
                    <x-sc.alert tone="info" class="mt-3"
                                :title="Copy::choice('one_role_is_held_by_nobody', 'n_roles_are_held_by_nobody', $unused->count())">
                        {{ $unused->pluck('name')->join(translate('list_separator') . ' ') }}
                    </x-sc.alert>
                @endif
            @endif
        </div>
    </div>
@endsection
