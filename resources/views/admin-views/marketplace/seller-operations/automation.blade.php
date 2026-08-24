@extends('layouts.admin.app')

@section('title', translate('automation'))

@php
    $shopName = fn ($id) => isset($sellers[$id])
        ? (trim(($sellers[$id]->f_name ?? '') . ' ' . ($sellers[$id]->l_name ?? '')) ?: ('#' . $id))
        : ('#' . $id);
    $statusTone = ['active' => 'success', 'paused' => 'secondary', 'suspended' => 'danger'];
@endphp

@section('content')
    <div class="content container-fluid">
        <div class="mb-3">
            <h2 class="h1 mb-0">{{ translate('seller_automation') }}</h2>
            <p class="mb-0 fs-12">{{ translate('rules_that_change_catalogues_unattended_and_what_they_have_done') }}.</p>
        </div>

        @include('admin-views.marketplace.seller-operations._nav')
        @include('admin-views.marketplace.seller-operations._seller-filter')

        <div class="card mb-4">
            <div class="card-header"><h5 class="mb-0">{{ translate('rules') }}</h5></div>
            <div class="card-body p-0">
                @if ($rules === null)
                    <p class="text-muted p-4 mb-0">{{ translate('not_installed') }}</p>
                @else
                    <div class="k-table-wrap">
                        <table class="k-table">
                            <thead>
                                <tr>
                                    <th>{{ translate('seller') }}</th>
                                    <th>{{ translate('rule') }}</th>
                                    <th>{{ translate('trigger') }}</th>
                                    <th>{{ translate('action') }}</th>
                                    <th>{{ translate('status') }}</th>
                                    <th class="text-end">{{ translate('runs') }}</th>
                                    <th class="text-end">{{ translate('changes') }}</th>
                                    <th>{{ translate('last_run') }}</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                            @forelse ($rules as $rule)
                                <tr>
                                    <td>{{ $shopName($rule->seller_id) }}</td>
                                    <td>{{ $rule->name }}</td>
                                    <td class="fs-12">{{ $rule->trigger }}</td>
                                    <td class="fs-12">{{ $rule->action }}</td>
                                    <td>
                                        <span class="k-badge k-badge--{{ $statusTone[$rule->status] ?? 'secondary' }}">
                                            {{ translate($rule->status) }}
                                        </span>
                                        @if ($rule->suspension_reason)
                                            <p class="fs-10 text-muted mb-0">{{ translate($rule->suspension_reason) }}</p>
                                        @endif
                                    </td>
                                    <td class="text-end">{{ $rule->run_count }}</td>
                                    <td class="text-end">{{ $rule->applied_count }}</td>
                                    <td class="fs-12">{{ $rule->last_run_at ?? '—' }}</td>
                                    <td class="text-end">
                                        @if ($rule->status !== 'suspended')
                                            <form method="POST" action="{{ route('admin.marketplace.seller-operations.suspend-rule') }}"
                                                  onsubmit="return confirm('{{ translate('stop_this_rule_the_seller_will_have_to_restart_it') }}')">
                                                @csrf
                                                <input type="hidden" name="id" value="{{ $rule->id }}">
                                                <button class="btn btn--sm btn-outline-danger" type="submit">{{ translate('stop') }}</button>
                                            </form>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td colspan="9" class="text-center text-muted py-4">{{ translate('no_seller_has_written_a_rule_yet') }}</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                    <div class="p-3">{{ $rules->links() }}</div>
                @endif
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">{{ translate('what_automation_has_changed') }}</h5>
                <p class="fs-12 text-muted mb-0">{{ translate('the_answer_to_who_changed_this_when_it_is_not_a_person') }}.</p>
            </div>
            <div class="card-body p-0">
                @if ($activity === null)
                    <p class="text-muted p-4 mb-0">{{ translate('not_installed') }}</p>
                @else
                    <div class="k-table-wrap">
                        <table class="k-table">
                            <thead>
                                <tr>
                                    <th>{{ translate('seller') }}</th>
                                    <th>{{ translate('action') }}</th>
                                    <th>{{ translate('subject') }}</th>
                                    <th>{{ translate('status') }}</th>
                                    <th>{{ translate('before') }}</th>
                                    <th>{{ translate('after') }}</th>
                                    <th>{{ translate('date') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                            @forelse ($activity as $row)
                                <tr>
                                    <td>{{ $shopName($row->seller_id) }}</td>
                                    <td class="fs-12">{{ $row->action }}</td>
                                    <td class="fs-12">{{ $row->subject_label ?? ($row->subject_type . ' #' . $row->subject_id) }}</td>
                                    <td>
                                        <span class="k-badge k-badge--{{ $row->status === 'applied' ? 'success' : ($row->status === 'failed' ? 'danger' : 'secondary') }}">
                                            {{ translate($row->status) }}
                                        </span>
                                        @if ($row->reason)
                                            <p class="fs-10 text-muted mb-0">{{ translate($row->reason) }}</p>
                                        @endif
                                    </td>
                                    <td class="fs-10">{{ $row->before ? json_encode($row->before) : '—' }}</td>
                                    <td class="fs-10">{{ $row->after ? json_encode($row->after) : '—' }}</td>
                                    <td class="fs-12">{{ $row->created_at }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="text-center text-muted py-4">{{ translate('automation_has_not_changed_anything_yet') }}</td></tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                    <div class="p-3">{{ $activity->links() }}</div>
                @endif
            </div>
        </div>
    </div>
@endsection
