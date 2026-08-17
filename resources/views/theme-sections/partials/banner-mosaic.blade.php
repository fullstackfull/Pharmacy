{{-- Asymmetric mosaic: each tile chooses its span (small / wide / tall / large), so one large
     campaign image can sit beside two smaller category tiles. --}}
@php
    $cards = array_values(array_filter($cards ?? [], fn ($card) => !empty($card['image']) || !empty($card['title'])));
    $rowHeight = max(120, (int) ($settings['height'] ?? 240));
    $gap = max(0, (int) ($gap ?? 16));
@endphp

@if (count($cards))
    <div class="ml-mosaic" style="grid-auto-rows:{{ $rowHeight }}px;gap:{{ $gap }}px">
        @foreach ($cards as $card)
            @php
                $span = in_array($card['span'] ?? 'small', ['small', 'wide', 'tall', 'large'], true) ? $card['span'] : 'small';
                $overlay = max(0, min(90, (int) ($card['overlay'] ?? 30))) / 100;
            @endphp
            <a class="ml-tile ml-tile--{{ $span }} ml-reveal" data-delay="{{ $loop->index % 6 }}"
               href="{{ $card['link'] ?: 'javascript:void(0)' }}"
               style="color:{{ $card['text_color'] ?: '#ffffff' }}">
                <img src="{{ $card['image'] ?: $placeholder }}" alt="{{ $card['title'] ?? '' }}" loading="lazy">
                <span class="ml-tile__scrim" style="background:linear-gradient(180deg,rgba(0,0,0,{{ $overlay / 3 }}),rgba(0,0,0,{{ $overlay }}))"></span>
                <span class="ml-tile__body">
                    @if (!empty($card['eyebrow']))<span class="ml-eyebrow" style="color:inherit;opacity:.85">{{ $card['eyebrow'] }}</span>@endif
                    @if (!empty($card['title']))<h4>{{ $card['title'] }}</h4>@endif
                    @if (!empty($card['button_text']))<span class="ml-btn ml-btn-light">{{ $card['button_text'] }}</span>@endif
                </span>
            </a>
        @endforeach
    </div>
@endif
