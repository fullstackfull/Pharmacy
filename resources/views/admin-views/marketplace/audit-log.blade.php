@extends('layouts.admin.app')

@section('title', translate('audit_log'))

@php
    $actorColours = ['admin' => 'primary', 'seller' => 'info', 'customer' => 'secondary', 'system' => 'dark'];
@endphp

@section('content')
    <div class="content container-fluid">
        <div class="mb-3">
            <h2 class="h1 mb-0 d-flex align-items-center gap-2">
                <i class="fi fi-rr-time-past"></i>
                {{ translate('audit_log') }}
            </h2>
            <p class="mb-0 fs-12">{{ translate('who_did_what_to_which_record_and_when_append_only') }}</p>
        </div>

        <div class="card mb-3">
            <div class="card-body">
                <form method="get" class="row g-2 align-items-end">
                    <div class="col-sm-3">
                        <label class="form-label fs-12" for="module">{{ translate('module') }}</label>
                        <select class="form-control" id="module" name="module" onchange="this.form.submit()">
                            <option value="">{{ translate('all') }}</option>
                            @foreach ($modules as $module)
                                <option value="{{ $module }}" {{ ($filters['module'] ?? '') === $module ? 'selected' : '' }}>
                                    {{ translate($module) }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-sm-3">
                        <label class="form-label fs-12" for="actor_type">{{ translate('actor') }}</label>
                        <select class="form-control" id="actor_type" name="actor_type" onchange="this.form.submit()">
                            <option value="">{{ translate('all') }}</option>
                            @foreach (['admin', 'seller', 'customer', 'system'] as $actor)
                                <option value="{{ $actor }}" {{ ($filters['actor_type'] ?? '') === $actor ? 'selected' : '' }}>
                                    {{ translate($actor) }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-sm-4">
                        <label class="form-label fs-12" for="search">{{ translate('search') }}</label>
                        <input type="text" class="form-control" id="search" name="search"
                               value="{{ $filters['search'] ?? '' }}"
                               placeholder="{{ translate('actor_action_or_record') }}">
                    </div>
                    <div class="col-sm-2">
                        <button class="btn btn-primary w-100">{{ translate('filter') }}</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-body">
                <div class="k-table-wrap">
                    <table class="k-table">
                        <thead>
                            <tr>
                                <th>{{ translate('when') }}</th>
                                <th>{{ translate('actor') }}</th>
                                <th>{{ translate('action') }}</th>
                                <th>{{ translate('record') }}</th>
                                <th>{{ translate('details') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($entries as $entry)
                                <tr>
                                    <td class="fs-12 text-nowrap">{{ $entry->created_at?->format('d M Y H:i:s') }}</td>
                                    <td>
                                        <span class="badge bg-{{ $actorColours[$entry->actor_type] ?? 'secondary' }}">
                                            {{ translate($entry->actor_type ?? 'system') }}
                                        </span>
                                        <div class="fs-12">{{ $entry->actor_name }}@if ($entry->actor_id) #{{ $entry->actor_id }}@endif</div>
                                    </td>
                                    <td><code class="fs-12">{{ $entry->action }}</code></td>
                                    <td class="fs-12">
                                        @if ($entry->subject_type)
                                            {{ class_basename($entry->subject_type) }} #{{ $entry->subject_id }}
                                        @else
                                            <span class="text-muted">—</span>
                                        @endif
                                    </td>
                                    <td class="fs-12">
                                        @if ($entry->context)
                                            @foreach ($entry->context as $key => $value)
                                                @if (!is_null($value) && $value !== '')
                                                    <span class="badge bg-light text-dark">{{ $key }}: {{ is_scalar($value) ? $value : json_encode($value) }}</span>
                                                @endif
                                            @endforeach
                                        @endif
                                        @if ($entry->before || $entry->after)
                                            <span class="badge bg-warning text-dark">{{ translate('changed') }}</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="text-center text-muted py-4">{{ translate('no_activity_recorded_yet') }}</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if (method_exists($entries, 'links')) {!! $entries->links() !!} @endif
            </div>
        </div>
    </div>
@endsection
