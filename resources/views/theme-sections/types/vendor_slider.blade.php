{{-- The marketplace's sellers. A shop card carries what a buyer weighs before
     entering a store: its cover, its logo, its rating and how much it sells. --}}

@php
    $vendorStyle = $s['style'] ?? 'cards';
    $showStats = (bool) ($s['stats'] ?? true);
@endphp
<div class="ml-sec-head ml-reveal">
    <div>
        @if (!empty($s['eyebrow']))<span class="ml-eyebrow">{{ $s['eyebrow'] }}</span>@endif
        <h2>{{ $s['title'] ?: translate('our_vendors') }}</h2>
    </div>
    @if (($s['view_all'] ?? true) && $viewAllUrl)
        <a class="ml-viewall" href="{{ $viewAllUrl }}">{{ translate('view_all') }}</a>
    @endif
</div>

<div class="{{ $vendorStyle === 'rail' ? 'ml-rail ml-vendor-rail' : 'ml-grid' }} ml-reveal">
    @foreach ($vendors as $shop)
        @include('theme-sections.partials.vendor-card', [
            'shop' => $shop, 'compact' => $vendorStyle === 'compact', 'stats' => $showStats,
        ])
    @endforeach
</div>
