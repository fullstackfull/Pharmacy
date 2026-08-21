{{-- Equal tiles in a responsive grid — the classic promotional row, with an optional text overlay. --}}
@php
    $cards = array_values(array_filter($cards ?? [], fn ($card) => !empty($card['image']) || !empty($card['title'])));
    $columns = max(1, (int) ($columns ?? 2));
    $gap = max(0, (int) ($gap ?? 24));
    $ratio = $settings['ratio'] ?? 'wide';
    $showText = (bool) ($settings['overlay'] ?? true);
    $aspect = match ($ratio) {
        'square'   => '1 / 1',
        'portrait' => '3 / 4',
        'auto'     => 'auto',
        default    => '16 / 9',
    };
@endphp

@if (count($cards))
    <div class="ml-mosaic" style="grid-template-columns:repeat({{ $columns }},1fr);gap:{{ $gap }}px">
        @foreach ($cards as $card)
            <a class="ml-tile ml-reveal" data-delay="{{ $loop->index % 6 }}"
               href="{{ ($card['link'] ?? null) ?: 'javascript:void(0)' }}"
               style="{{ $aspect !== 'auto' ? 'aspect-ratio:' . $aspect . ';' : '' }}">
                <img src="{{ ($card['image'] ?? null) ?: $placeholder }}" alt="{{ $card['title'] ?? '' }}" loading="lazy">
                @if (!empty($card['badge']))<span class="ml-tile__badge">{{ $card['badge'] }}</span>@endif
                @if ($showText && (!empty($card['title']) || !empty($card['subtitle'])))
                    <span class="ml-tile__scrim"></span>
                    <span class="ml-tile__body">
                        @if (!empty($card['title']))<h4>{{ $card['title'] }}</h4>@endif
                        @if (!empty($card['subtitle']))<p>{{ $card['subtitle'] }}</p>@endif
                        @if (!empty($card['button_text']))<span class="ml-btn ml-btn-light">{{ $card['button_text'] }}</span>@endif
                    </span>
                @endif
            </a>
        @endforeach
    </div>
@endif
