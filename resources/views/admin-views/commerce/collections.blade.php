@extends('layouts.admin.app')

@section('title', translate('Dynamic_Collections'))

@section('content')
    <div class="content container-fluid">
        <div class="d-flex align-items-center gap-2 mb-3">
            <h2 class="h1 mb-0">{{ translate('dynamic_collections') }}</h2>
            @if (!$enabled)
                <span class="badge badge-soft-danger">{{ translate('commerce_experience_is_switched_off') }}</span>
            @endif
        </div>

        @if (!$ready)
            <div class="alert alert-warning">
                {{ translate('the_collection_tables_have_not_been_migrated_yet') }} —
                <code dir="ltr">php artisan migrate</code>
            </div>
        @else
            <div class="row g-3">
                <div class="col-lg-7">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">{{ translate('collections') }}
                                <span class="badge badge-soft-primary">{{ $collections->count() }}</span>
                            </h5>
                            @if ($metricsAge)
                                <small class="text-muted">{{ translate('metrics_computed') }}: {{ $metricsAge }}</small>
                            @else
                                <small class="text-muted">
                                    {{ translate('metrics_never_computed_run') }} <code dir="ltr">php artisan commerce:metrics-refresh</code>
                                </small>
                            @endif
                        </div>
                        <div class="card-body p-0">
                            <div class="k-table-wrap">
                                <table class="k-table">
                                    <thead>
                                        <tr>
                                            <th>{{ translate('collection') }}</th>
                                            <th>{{ translate('rules') }}</th>
                                            <th class="text-center">{{ translate('ranking') }}</th>
                                            <th class="text-center">{{ translate('status') }}</th>
                                            <th class="text-center">{{ translate('action') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    @forelse ($collections as $collection)
                                        <tr>
                                            <td>
                                                <div class="fw-bold">{{ $collection->name }}</div>
                                                <small class="text-muted"><code dir="ltr">{{ $collection->slug }}</code></small>
                                            </td>
                                            <td>
                                                @forelse ($collection->ruleRows() as $rule)
                                                    <span class="badge badge-soft-secondary" dir="ltr">
                                                        {{ $rule['field'] ?? '' }} {{ str_replace('_', ' ', $rule['operator'] ?? '') }}
                                                        {{ is_array($rule['value'] ?? null) ? implode(',', $rule['value']) : ($rule['value'] ?? '') }}
                                                    </span>
                                                @empty
                                                    <small class="text-muted">{{ translate('every_active_product') }}</small>
                                                @endforelse
                                            </td>
                                            <td class="text-center"><span class="badge badge-soft-info">{{ translate($collection->sort_by) }}</span></td>
                                            <td class="text-center">
                                                @if ($collection->status)
                                                    <span class="badge badge-soft-success">{{ translate('live') }}</span>
                                                @else
                                                    <span class="badge badge-soft-warning">{{ translate('off') }}</span>
                                                @endif
                                            </td>
                                            <td class="text-center">
                                                <div class="d-flex flex-wrap gap-1 justify-content-center">
                                                    <button type="button" class="btn btn-sm btn-outline-primary cx-preview"
                                                            data-id="{{ $collection->id }}"
                                                            data-url="{{ route('admin.commerce.collections.preview') }}">
                                                        {{ translate('preview') }}
                                                    </button>
                                                    @if ($editable)
                                                        <form action="{{ route('admin.commerce.collections.update') }}" method="post">
                                                            @csrf
                                                            <input type="hidden" name="id" value="{{ $collection->id }}">
                                                            <input type="hidden" name="status" value="{{ $collection->status ? 0 : 1 }}">
                                                            <button type="submit" class="btn btn-sm btn-outline-warning">
                                                                {{ $collection->status ? translate('turn_off') : translate('turn_on') }}
                                                            </button>
                                                        </form>
                                                        <form action="{{ route('admin.commerce.collections.delete') }}" method="post"
                                                              onsubmit="return confirm('{{ translate('delete_this_collection_sections_using_it_will_render_nothing_until_repointed') }}?')">
                                                            @csrf
                                                            <input type="hidden" name="id" value="{{ $collection->id }}">
                                                            <button type="submit" class="btn btn-sm btn-outline-danger">{{ translate('delete') }}</button>
                                                        </form>
                                                    @endif
                                                </div>
                                                <div class="cx-preview-result text-start small mt-2" hidden></div>
                                            </td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="5" class="text-center py-4 text-muted">
                                            {{ translate('no_collections_yet_make_one_and_source_any_product_section_from_it') }}
                                        </td></tr>
                                    @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-lg-5">
                    <div class="card">
                        <div class="card-header"><h5 class="mb-0">{{ translate('new_collection') }}</h5></div>
                        <div class="card-body">
                            @if ($editable)
                                <form action="{{ route('admin.commerce.collections.store') }}" method="post" id="cx-form">
                                    @csrf
                                    <div class="mb-3">
                                        <label class="form-label">{{ translate('name') }}</label>
                                        <input type="text" name="name" class="form-control" required maxlength="120"
                                               placeholder="{{ translate('best_selling_vitamins') }}">
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">{{ translate('ranked_by') }}</label>
                                        <select name="sort_by" class="form-control">
                                            @foreach ($sorts as $sort)
                                                <option value="{{ $sort }}">{{ translate($sort) }}</option>
                                            @endforeach
                                        </select>
                                    </div>

                                    <label class="form-label">{{ translate('rules') }}</label>
                                    <p class="text-muted small mb-2">
                                        {{ translate('every_rule_must_hold_no_rules_means_every_active_product') }}
                                    </p>
                                    <div id="cx-rules" class="d-flex flex-column gap-2 mb-2"></div>
                                    <input type="hidden" name="rules" id="cx-rules-json" value="[]">
                                    <button type="button" class="btn btn-sm btn-outline-secondary mb-3" id="cx-add-rule">
                                        + {{ translate('add_a_rule') }}
                                    </button>

                                    <button type="submit" class="btn btn-primary w-100">{{ translate('create_collection') }}</button>
                                </form>
                            @else
                                <p class="text-muted mb-0">{{ translate('you_do_not_have_permission_to_edit_a_theme') }}</p>
                            @endif
                        </div>
                    </div>

                    <div class="alert alert-info mt-3 mb-0">
                        {{ translate('a_collection_does_nothing_by_itself_source_a_product_section_from_it_in_the_builder_and_publish') }}
                    </div>
                </div>
            </div>
        @endif
    </div>
@endsection

@push('script')
<script>
    (function () {
        'use strict';

        var FIELDS = @json($fields);
        var rulesHost = document.getElementById('cx-rules');
        var rulesJson = document.getElementById('cx-rules-json');
        var addButton = document.getElementById('cx-add-rule');

        // ---- rule builder: field -> operators for its type -> one value input -------------
        function ruleRow() {
            var row = document.createElement('div');
            row.className = 'd-flex gap-1 align-items-start';

            var field = document.createElement('select');
            field.className = 'form-control form-control-sm';
            Object.keys(FIELDS).forEach(function (name) {
                var option = document.createElement('option');
                option.value = name;
                option.textContent = name.replace(/_/g, ' ');
                field.appendChild(option);
            });

            var operator = document.createElement('select');
            operator.className = 'form-control form-control-sm';

            var value = document.createElement('input');
            value.type = 'text';
            value.className = 'form-control form-control-sm';
            value.dir = 'ltr';

            function refresh() {
                var definition = FIELDS[field.value];
                operator.innerHTML = '';
                (definition.operators || []).forEach(function (name) {
                    var option = document.createElement('option');
                    option.value = name;
                    option.textContent = name.replace(/_/g, ' ');
                    operator.appendChild(option);
                });
                value.placeholder = definition.type === 'set' ? '3,7,12'
                    : definition.type === 'boolean' ? '1'
                    : definition.type === 'days' ? '30'
                    : operator.value === 'between' ? '10,50' : '10';
            }
            field.addEventListener('change', function () { refresh(); collect(); });
            operator.addEventListener('change', function () { refresh(); collect(); });
            value.addEventListener('input', collect);
            refresh();

            var remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'btn btn-sm btn-outline-danger';
            remove.textContent = '×';
            remove.addEventListener('click', function () { row.remove(); collect(); });

            row.appendChild(field);
            row.appendChild(operator);
            row.appendChild(value);
            row.appendChild(remove);
            return row;
        }

        function collect() {
            var rules = [];
            rulesHost.querySelectorAll(':scope > div').forEach(function (row) {
                var parts = row.querySelectorAll('select, input');
                var raw = parts[2].value.trim();
                if (raw === '') return;
                var operator = parts[1].value;
                var needsList = operator === 'in' || operator === 'not_in' || operator === 'between';
                rules.push({
                    field: parts[0].value,
                    operator: operator,
                    value: needsList ? raw.split(',').map(function (piece) { return piece.trim(); }) : raw
                });
            });
            rulesJson.value = JSON.stringify(rules);
        }

        if (addButton) {
            addButton.addEventListener('click', function () {
                rulesHost.appendChild(ruleRow());
                collect();
            });
        }

        // ---- preview: what the collection resolves to right now ---------------------------
        document.querySelectorAll('.cx-preview').forEach(function (button) {
            button.addEventListener('click', function () {
                var host = button.closest('td').querySelector('.cx-preview-result');
                host.hidden = false;
                host.textContent = '…';
                fetch(button.dataset.url + '?id=' + encodeURIComponent(button.dataset.id),
                      {headers: {'X-Requested-With': 'XMLHttpRequest'}})
                    .then(function (response) { return response.json(); })
                    .then(function (body) {
                        var products = (body && body.products) || [];
                        host.textContent = products.length
                            ? products.map(function (product) { return product.name; }).join(' · ')
                            : @json(translate('no_products_match_right_now'));
                    })
                    .catch(function () { host.textContent = @json(translate('preview_failed')); });
            });
        });
    })();
</script>
@endpush
