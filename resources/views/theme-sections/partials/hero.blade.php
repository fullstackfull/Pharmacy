{{-- Hero / full-width carousel. Feeds on the normalized card shape, so it renders theme-builder
     slides and dashboard banners identically. --}}
@php
    $slides = array_values(array_filter($slides ?? [], fn ($slide) => !empty($slide['image']) || !empty($slide['title'])));
    $height = (int) ($settings['height'] ?? 520);
    $zoom = (bool) ($settings['ken_burns'] ?? true);
@endphp

@if (count($slides))
    <div class="ml-hero {{ $zoom ? 'is-zoom' : '' }} ml-reveal" data-ml-slider
         data-autoplay="{{ ($settings['autoplay'] ?? true) ? 1 : 0 }}"
         data-interval="{{ (int) ($settings['interval'] ?? 5000) }}"
         style="min-height:{{ $height }}px">
        <div class="ml-hero__track" style="height:{{ $height }}px">
            @foreach ($slides as $slide)
                @php
                    $overlay = max(0, min(90, (int) ($slide['overlay'] ?? 35))) / 100;
                    $align = in_array($slide['align'] ?? 'start', ['center', 'end'], true) ? $slide['align'] : 'start';
                    $textColor = $slide['text_color'] ?: '#ffffff';
                @endphp
                <div class="ml-hero__slide {{ $loop->first ? 'is-active' : '' }}" style="height:{{ $height }}px">
                    <div class="ml-hero__media">
                        <img src="{{ $slide['image'] ?: $placeholder }}" alt="{{ $slide['title'] ?? '' }}"
                             loading="{{ $loop->first ? 'eager' : 'lazy' }}">
                    </div>
                    <div class="ml-hero__scrim" style="background:linear-gradient(90deg,rgba(0,0,0,{{ $overlay }}) 0%,rgba(0,0,0,{{ $overlay / 3 }}) 55%,rgba(0,0,0,0) 100%)"></div>
                    @if (!empty($slide['title']) || !empty($slide['subtitle']) || !empty($slide['link']))
                        <div class="ml-hero__cap is-{{ $align }}" style="color:{{ $textColor }}">
                            @if (!empty($slide['eyebrow']))<span class="ml-eyebrow" style="color:inherit;opacity:.85">{{ $slide['eyebrow'] }}</span>@endif
                            @if (!empty($slide['title']))<h3>{{ $slide['title'] }}</h3>@endif
                            @if (!empty($slide['subtitle']))<p>{{ $slide['subtitle'] }}</p>@endif
                            @if (!empty($slide['link']))
                                <a href="{{ $slide['link'] }}" class="ml-btn ml-btn-light">{{ $slide['button_text'] ?: translate('shop_now') }}</a>
                            @endif
                        </div>
                    @endif
                </div>
            @endforeach
        </div>

        @if (count($slides) > 1)
            <button type="button" class="ml-hero__nav is-prev" data-ml-slide="prev" aria-label="{{ translate('previous') }}">&#8249;</button>
            <button type="button" class="ml-hero__nav is-next" data-ml-slide="next" aria-label="{{ translate('next') }}">&#8250;</button>
            <div class="ml-hero__dots">
                @foreach ($slides as $slide)
                    <button type="button" class="{{ $loop->first ? 'is-active' : '' }}" aria-label="{{ translate('go_to_slide') }} {{ $loop->iteration }}"></button>
                @endforeach
            </div>
        @endif
    </div>
@endif
