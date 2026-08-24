@extends('layouts.admin.app')

@section('title', translate('seller_staff'))

@php
    $shopName = fn ($id) => isset($sellers[$id])
        ? (trim(($sellers[$id]->f_name ?? '') . ' ' . ($sellers[$id]->l_name ?? '')) ?: ('#' . $id))
        : ('#' . $id);
@endphp

@section('content')
    <div class="content container-fluid">
        <div class="mb-3">
            <h2 class="h1 mb-0">{{ translate('seller_staff') }}</h2>
            <p class="mb-0 fs-12">{{ translate('who_can_act_as_a_shop_besides_its_owner') }}.</p>
        </div>

        @include('admin-views.marketplace.seller-operations._nav')
        @include('admin-views.marketplace.seller-operations._seller-filter')

        <div class="card">
            <div class="card-body p-0">
                @if ($staff === null)
                    <p class="text-muted p-4 mb-0">{{ translate('not_installed') }}</p>
                @else
                    <div class="k-table-wrap">
                        <table class="k-table">
                            <thead>
                                <tr>
                                    <th>{{ translate('seller') }}</th>
                                    <th>{{ translate('name') }}</th>
                                    <th>{{ translate('email') }}</th>
                                    <th>{{ translate('role') }}</th>
                                    <th>{{ translate('status') }}</th>
                                    <th>{{ translate('session') }}</th>
                                    <th>{{ translate('last_login') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                            @forelse ($staff as $member)
                                <tr>
                                    <td>{{ $shopName($member->seller_id) }}</td>
                                    <td>{{ $member->name }}</td>
                                    <td class="fs-12">{{ $member->email }}</td>
                                    <td>
                                        @if ($member->role)
                                            {{ $member->role->name }}
                                        @else
                                            <span class="k-badge k-badge--warning">{{ translate('no_role') }}</span>
                                        @endif
                                    </td>
                                    <td>
                                        <span class="k-badge k-badge--{{ $member->status === 'active' ? 'success' : 'secondary' }}">
                                            {{ translate($member->status) }}
                                        </span>
                                    </td>
                                    <td class="fs-12">
                                        {{-- Whether a token exists, never its value. --}}
                                        {{ translate(!empty($member->auth_token) ? 'signed_in' : 'not_signed_in') }}
                                    </td>
                                    <td class="fs-12">{{ $member->last_login_at ?? '—' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="text-center text-muted py-4">{{ translate('no_seller_has_added_staff_yet') }}</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                    <div class="p-3">{{ $staff->links() }}</div>
                @endif
            </div>
        </div>
    </div>
@endsection
