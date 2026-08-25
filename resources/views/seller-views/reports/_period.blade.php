{{-- One period, chosen once, carried into every report and every download beneath it. --}}
<form method="GET" class="sc-form-row" action="{{ $action }}">
    @foreach (request()->except(['date_type', 'from', 'to', 'page']) as $name => $value)
        @if (!is_array($value))<input type="hidden" name="{{ $name }}" value="{{ $value }}">@endif
    @endforeach

    <x-sc.field :label="translate('period')" for="sc-date-type">
        <x-sc.select id="sc-date-type" name="date_type" :value="$window->type"
                     :options="collect($periods)->map(fn ($type) => ['value' => $type, 'label' => translate($type)])->all()" />
    </x-sc.field>

    <x-sc.field :label="translate('from')" :help="translate('used_only_with_a_custom_period')">
        <x-sc.input type="date" name="from" :value="$window->from->toDateString()" />
    </x-sc.field>

    <x-sc.field :label="translate('to')">
        <x-sc.input type="date" name="to" :value="$window->to->toDateString()" />
    </x-sc.field>

    <div class="sc-form-footer">
        <x-sc.button variant="secondary" type="submit">{{ translate('apply') }}</x-sc.button>
    </div>
</form>
