@extends('layouts.seller.app')

@section('title', translate('nav_incidents'))

@php
    use App\Services\SellerCenter\Copy;

    $columns = [
        ['key' => 'title', 'label' => translate('issue')],
        ['key' => 'category', 'label' => translate('category'), 'width' => 150, 'priority' => 'md'],
        ['key' => 'severity', 'label' => translate('severity'), 'width' => 120],
        ['key' => 'escalation', 'label' => translate('escalated_to'), 'width' => 130, 'num' => true],
        ['key' => 'open_for', 'label' => translate('open_for'), 'width' => 140],
        ['key' => 'due', 'label' => translate('due'), 'width' => 130, 'priority' => 'lg'],
    ];
@endphp

@section('content')
    <x-sc.page-header :eyebrow="translate('nav_trust')" :title="translate('issues_that_were_left_long_enough_to_climb')"
                      :sub="translate('escalation_only_ever_climbs_and_one_step_at_a_time_so_a_row_here_measures_silence_not_severity')" />

    <div class="sc-scroll">
        <div class="sc-page">
            @unless ($available)
                <x-sc.alert tone="info" :title="translate('issue_detection_is_not_running_on_this_marketplace')">
                    {{ translate('nothing_is_being_withheld_there_is_no_issue_store_to_read') }}
                </x-sc.alert>
            @else
                <x-sc.table :columns="$columns" :state="$state">
                    <x-slot:empty>
                        <x-sc.empty glyph="check-circle" :title="translate('nothing_has_escalated')"
                                    :text="translate('every_issue_this_shop_has_had_was_answered_before_the_platform_promoted_it')" />
                    </x-slot:empty>

                    @foreach ($incidents as $incident)
                        <x-sc.tr :href="route('seller.issues.show', ['issue' => $incident->id])" :id="$incident->id">
                            <x-sc.td :sub="$incident->body">{{ $incident->title }}</x-sc.td>
                            <x-sc.td>{{ translate($incident->category) }}</x-sc.td>
                            <x-sc.td><x-sc.badge :severity="$incident->severity" /></x-sc.td>
                            {{-- The level is the whole record: it says how many times this went
                                 unanswered long enough for the platform to raise it again. --}}
                            <x-sc.td num>{{ Copy::line('level_n', ['level' => $incident->escalation_level]) }}</x-sc.td>
                            <x-sc.td>{{ Copy::duration((int) round($incident->openForHours() * 60)) }}</x-sc.td>
                            <x-sc.td :tone="$incident->isOverdue() ? 'critical' : null">
                                {{ $incident->due_at?->format('Y-m-d H:i') ?? '—' }}
                            </x-sc.td>
                        </x-sc.tr>
                    @endforeach
                </x-sc.table>

                <x-sc.pager :paginator="$incidents" />
            @endunless
        </div>
    </div>
@endsection
