@extends('layouts.seller.app')

@section('title', translate('nav_bulk_jobs') . ' #' . $job->id)

@php
    use App\Services\SellerCenter\Copy;

    $failures = collect($job->failures ?? []);
@endphp

@section('content')
    <x-sc.page-header :eyebrow="translate('nav_bulk_jobs')" :title="translate($job->type)"
                      :sub="Copy::line('job_n_run_on', ['id' => '#' . $job->id, 'when' => $job->created_at?->format('Y-m-d H:i')])"
                      :back="route('seller.bulk-jobs.index')" />

    <div class="sc-scroll">
        <div class="sc-page">
            <div class="sc-stats">
                <x-sc.stat :label="translate('rows_asked_for')" :value="number_format((int) $job->total)" />
                <x-sc.stat :label="translate('applied')" :value="number_format((int) $job->succeeded)"
                           :tone="(int) $job->succeeded > 0 ? 'good' : null" />
                <x-sc.stat :label="translate('refused')" :value="number_format((int) $job->failed)"
                           :tone="(int) $job->failed > 0 ? 'critical' : null"
                           :note="translate('each_one_with_its_reason_below')" />
                <x-sc.stat :label="translate('status')" :value="translate($job->status)" />
            </div>

            @if ($job->status === 'partial')
                {{-- The state the whole receipt exists for. "Partial" means the job ran to the end
                     and some rows were refused — which reads as success in every summary that only
                     counts completion. --}}
                <x-sc.alert tone="high" class="mt-3"
                            :title="Copy::line('n_rows_were_refused', ['count' => (int) $job->failed])">
                    {{ translate('the_job_ran_to_the_end_these_rows_did_not_do_what_was_asked_and_the_reason_is_beside_each_one') }}
                </x-sc.alert>
            @endif

            <x-sc.card :title="translate('what_was_asked_for')" class="mt-3">
                <div class="sc-info-grid">
                    @foreach ((array) ($job->payload ?? []) as $key => $value)
                        <x-sc.info :label="translate($key)"
                                   :value="is_array($value) ? implode(', ', array_slice($value, 0, 20)) : (string) $value" />
                    @endforeach
                </div>
            </x-sc.card>

            <x-sc.card :title="translate('rows_that_were_refused')" class="mt-3">
                @if ($failures->isEmpty())
                    <x-sc.empty glyph="check-circle" :title="translate('nothing_was_refused')"
                                :text="translate('every_row_this_job_touched_did_what_was_asked')" />
                @else
                    <div class="sc-table-wrap">
                        <table class="sc-table">
                            <thead><tr>
                                <th>{{ translate('row') }}</th>
                                <th>{{ translate('reason') }}</th>
                            </tr></thead>
                            <tbody>
                            @foreach ($failures as $failure)
                                <tr>
                                    <td class="sc-code">{{ $failure['product_id'] ?? $failure['id'] ?? '—' }}</td>
                                    <td>{{ translate($failure['reason'] ?? 'refused') }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-sc.card>
        </div>
    </div>
@endsection
