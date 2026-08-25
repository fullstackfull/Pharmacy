@props(['title' => null, 'label' => null, 'context' => null, 'side' => false, 'flush' => false])
<section {{ $attributes->merge(['class' => 'sc-card' . ($side ? ' sc-card--side' : '') . ($flush ? ' sc-card--flush' : '')]) }}>
    @if ($title || isset($actions))
        <header class="sc-card__head">
            <div style="min-width:0">
                <h5 class="sc-card__title">{{ $title }}</h5>
                @if ($context)<div class="sc-card__context">{{ $context }}</div>@endif
            </div>
            <div class="sc-spacer"></div>
            @isset($actions){{ $actions }}@endisset
        </header>
    @endif
    <div class="sc-card__body">
        @if ($label)<div class="sc-card__label" style="margin-bottom:8px">{{ $label }}</div>@endif
        {{ $slot }}
    </div>
</section>
