@extends('layouts.seller.app')

@section('title', translate('nav_bulk_jobs'))

@php
    use App\Services\SellerCenter\Copy;

    $columns = [
        ['key' => 'id', 'label' => translate('job'), 'width' => 90],
        ['key' => 'type', 'label' => translate('what_it_changed')],
        ['key' => 'status', 'label' => translate('status'), 'width' => 130],
        ['key' => 'done', 'label' => translate('applied'), 'width' => 100, 'num' => true],
        ['key' => 'refused', 'label' => translate('refused'), 'width' => 100, 'num' => true],
        ['key' => 'when', 'label' => translate('run'), 'width' => 140, 'priority' => 'md'],
        ['key' => 'action', 'label' => '', 'width' => 90],
    ];

    $tabs = collect(array_merge(['all'], $statuses))->map(fn ($key) => [
        'key' => $key,
        'label' => translate($key),
        'href' => $key === 'all' ? route('seller.bulk-jobs.index') : route('seller.bulk-jobs.index', ['status' => $key]),
        'tone' => $key === 'partial' || $key === 'failed' ? 'critical' : null,
    ])->values()->all();
@endphp

@section('content')
    <x-sc.page-header :eyebrow="translate('nav_catalog')" :title="translate('bulk_changes_you_have_run')"
                      :sub="translate('what_was_asked_for_what_happened_to_each_row_and_why_anything_was_refused')" />

    <x-sc.tabs :tabs="$tabs" :current="$status ?? 'all'" />

    <div class="sc-scroll">
        <x-sc.toolbar :count="Copy::line('n_jobs', ['count' => $jobs->total()])" />

        <x-sc.table :columns="$columns" :state="$state">
            <x-slot:empty>
                <x-sc.empty glyph="stack" :title="translate('you_have_not_run_a_bulk_change_yet')"
                            :text="translate('a_bulk_change_leaves_a_receipt_here_so_a_job_that_reports_done_can_still_be_checked')" />
            </x-slot:empty>
            <x-slot:noResults>
                <x-sc.empty glyph="funnel" :title="translate('no_jobs_match_these_filters')"
                            :text="translate('adjust_or_clear_the_filters_to_see_more')" />
            </x-slot:noResults>

            @foreach ($jobs as $job)
                <x-sc.tr :href="route('seller.bulk-jobs.show', ['job' => $job->id])" :id="$job->id">
                    <x-sc.td class="sc-code">#{{ $job->id }}</x-sc.td>
                    <x-sc.td>{{ translate($job->type) }}</x-sc.td>
                    <x-sc.td><x-sc.badge :status="$job->status" /></x-sc.td>
                    <x-sc.td num>{{ number_format((int) $job->succeeded) }}</x-sc.td>
                    {{-- Refusals are the reason this screen exists: a job that says "done" and
                         quietly refused four hundred rows is worse than one that fails outright. --}}
                    <x-sc.td num :tone="(int) $job->failed > 0 ? 'critical' : null">
                        {{ (int) $job->failed > 0 ? number_format((int) $job->failed) : '—' }}
                    </x-sc.td>
                    <x-sc.td drop="md" class="sc-muted">{{ $job->created_at?->format('Y-m-d H:i') }}</x-sc.td>
                    <x-sc.td action>
                        <a class="sc-btn sc-btn--ghost sc-btn--sm" href="{{ route('seller.bulk-jobs.show', ['job' => $job->id]) }}">
                            {{ translate('open') }}
                        </a>
                    </x-sc.td>
                </x-sc.tr>
            @endforeach

            <x-slot:mobile>
                @foreach ($jobs as $job)
                    <x-sc.entity-card :title="translate($job->type)"
                                      :href="route('seller.bulk-jobs.show', ['job' => $job->id])"
                                      :figure="number_format((int) $job->succeeded)"
                                      :meta="'#' . $job->id . ' · ' . $job->created_at?->format('Y-m-d')">
                        <div class="sc-row"><x-sc.badge :status="$job->status" /></div>
                    </x-sc.entity-card>
                @endforeach
            </x-slot:mobile>

            <x-slot:footer><x-sc.pager :paginator="$jobs" /></x-slot:footer>
        </x-sc.table>
    </div>
@endsection
