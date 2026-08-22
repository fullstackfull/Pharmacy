{{-- The portal grading itself. An endpoint the portal cannot describe is listed with the specific
     reason, because "improve the documentation" is not a task and "these 264 endpoints have no
     response schema" is. --}}
<div class="dev-grid dev-grid--2">
    <x-k.card :title="translate('score')">
        <div class="dev-score" data-score="{{ $data['score']['score'] }}">
            <span class="k-num">{{ $data['score']['score'] }}</span><i>/100</i>
        </div>
        <ul class="dev-list">
            <li><span>{{ translate('described_by_hand') }}</span><strong>{{ $data['score']['documented_pct'] }}%</strong></li>
            <li><span>{{ translate('with_a_request_schema') }}</span><strong>{{ $data['score']['schema_pct'] }}%</strong></li>
            <li><span>{{ translate('classified') }}</span><strong>{{ $data['score']['classified_pct'] }}%</strong></li>
        </ul>
    </x-k.card>

    <x-k.card :title="translate('what_is_missing')">
        <ul class="dev-list dev-list--bars">
            @foreach ($data['by_reason'] as $reason => $count)
                <li>
                    <span>{{ $reason }}</span>
                    <span class="dev-bar"><i style="width: {{ round(100 * $count / max(1, $data['total_flagged'])) }}%"></i></span>
                    <strong>{{ $count }}</strong>
                </li>
            @endforeach
        </ul>
        <p class="dev-note">
            {{ translate('add_an_apidoc_attribute_to_a_controller_method_to_fix_the_first_two_the_attribute_lives_with_the_code_so_it_is_reviewed_in_the_same_diff_and_disappears_when_the_method_does') }}
        </p>
    </x-k.card>
</div>

<x-k.card :title="$data['total_flagged'] . ' ' . translate('endpoints_with_gaps')">
    <table class="dev-table">
        <thead><tr><th>{{ translate('endpoint') }}</th><th>{{ translate('client') }}</th><th>{{ translate('missing') }}</th></tr></thead>
        <tbody>
        @foreach ($data['endpoints'] as $warning)
            <tr>
                <td><a href="{{ route('admin.developer.endpoint', ['id' => $warning['id']]) }}"><code>{{ $warning['endpoint'] }}</code></a></td>
                <td class="dev-muted">{{ translate($warning['audience']) }}</td>
                <td class="dev-muted">{{ implode(' · ', $warning['missing']) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
</x-k.card>
