@extends('layouts.admin.app')

@section('title', translate('feature_flags'))

@section('content')
    {{--
        Turning something on for some of the shop rather than all of it.

        Built because the only lever this platform had was publishing or unpublishing a whole addon
        module: every change went live for every seller and every shopper at the same moment, and the
        only way back was a deployment.

        The three controls are ordered the way they are decided. The master switch beats everything,
        because an off switch some people are exempt from is not an off switch. The pilot list beats
        the percentage, because it exists for the shops somebody is watching. And the percentage is
        deterministic per seller, so a shop stays on the same side of it across every request rather
        than seeing two versions of the product in one session.
    --}}
    <div class="content container-fluid">
        <div class="mb-3">
            <h2 class="h1 mb-0 d-flex align-items-center gap-2">
                <i class="fi fi-rr-toggle-on"></i> {{ translate('feature_flags') }}
            </h2>
            <p class="mb-0 fs-12">{{ translate('turn_a_change_on_for_some_of_the_marketplace_before_all_of_it') }}.</p>
        </div>

        <div class="row g-3">
            <div class="col-lg-4">
                <div class="card">
                    <div class="card-header"><h5 class="mb-0">{{ translate('add_or_update_a_flag') }}</h5></div>
                    <div class="card-body">
                        <form action="{{ route('admin.settings.feature-flags.update') }}" method="post">
                            @csrf
                            <div class="form-group">
                                <label class="form-label" for="key">{{ translate('flag_key') }}</label>
                                <input type="text" class="form-control" id="key" name="key" required
                                       placeholder="{{ translate('ex') . ': seller_center.new_orders_table' }}">
                                <small class="text-muted">{{ translate('this_must_match_exactly_what_the_code_asks_for') }}</small>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="description">{{ translate('description') }}</label>
                                <input type="text" class="form-control" id="description" name="description" maxlength="500">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="rollout_percent">{{ translate('rollout_percentage') }}</label>
                                <input type="number" class="form-control" id="rollout_percent" name="rollout_percent"
                                       min="0" max="100" value="0">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="seller_ids">{{ translate('always_on_for_these_sellers') }}</label>
                                <input type="text" class="form-control" id="seller_ids" name="seller_ids"
                                       placeholder="{{ translate('ex') . ': 12, 44, 91' }}">
                                <small class="text-muted">{{ translate('the_pilot_group_these_shops_are_in_whatever_the_percentage_says') }}</small>
                            </div>
                            <div class="form-group form-check">
                                <input type="checkbox" class="form-check-input" id="enabled" name="enabled" value="1">
                                <label class="form-check-label" for="enabled">{{ translate('switched_on') }}</label>
                                <small class="d-block text-muted">{{ translate('off_means_off_for_everyone_including_the_pilot_group') }}</small>
                            </div>
                            <button type="submit" class="btn btn-primary w-100">{{ translate('save') }}</button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-lg-8">
                <div class="card">
                    <div class="card-header"><h5 class="mb-0">{{ translate('flags_on_this_installation') }}</h5></div>
                    <div class="card-body">
                        @if ($flags === [])
                            <p class="text-muted mb-0">{{ translate('no_flag_has_been_created_yet_a_flag_that_does_not_exist_is_off') }}.</p>
                        @else
                            <div class="table-responsive">
                                <table class="table align-middle">
                                    <thead>
                                    <tr>
                                        <th>{{ translate('flag_key') }}</th>
                                        <th>{{ translate('status') }}</th>
                                        <th class="text-end">{{ translate('rollout') }}</th>
                                        <th>{{ translate('pilot_group') }}</th>
                                        <th class="text-end">{{ translate('action') }}</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    @foreach ($flags as $key => $flag)
                                        <tr>
                                            <td>
                                                <code>{{ $key }}</code>
                                                @if ($flag['description'])
                                                    <small class="d-block text-muted">{{ $flag['description'] }}</small>
                                                @endif
                                            </td>
                                            <td>
                                                @if ($flag['enabled'])
                                                    <span class="badge badge-soft-success">{{ translate('switched_on') }}</span>
                                                @else
                                                    <span class="badge badge-soft-secondary">{{ translate('off') }}</span>
                                                @endif
                                            </td>
                                            <td class="text-end">{{ $flag['rollout_percent'] }}%</td>
                                            <td>
                                                {{ $flag['seller_ids'] === [] ? '—' : implode(', ', array_slice($flag['seller_ids'], 0, 10)) }}
                                                @if (count($flag['seller_ids']) > 10)
                                                    + {{ count($flag['seller_ids']) - 10 }}
                                                @endif
                                            </td>
                                            <td class="text-end">
                                                <form method="post" action="{{ route('admin.settings.feature-flags.delete') }}">
                                                    @csrf
                                                    <input type="hidden" name="key" value="{{ $key }}">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger">{{ translate('remove') }}</button>
                                                </form>
                                            </td>
                                        </tr>
                                    @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
