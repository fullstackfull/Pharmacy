{{-- Storefront renderer for the visual Theme Builder's home sections (Phase 1 theme system).
     Renders the published version's sections when a merchant has composed any; when sectionsFor()
     returns null (no published theme, or no home sections) this outputs nothing and the theme's own
     hardcoded home is shown unchanged — the compatibility shim the builder was designed around.

     Every read goes through SectionDataResolver: a view that queries is a view that can 500 the
     shop, and this file already did once. Styling is a self-contained glossy "Minimal Luxury"
     system scoped to .ml-sections, so it upgrades these sections without touching the legacy
     storefront blades. --}}
@php
    $__sections = app(\App\Services\Theme\StorefrontThemeRenderer::class)->sectionsFor('home');
    $__data = app(\App\Services\Theme\SectionDataResolver::class);
    $__placeholder = asset('public/assets/front-end/img/image-place-holder.png');
    // Types this file can draw. A section whose type has no renderer here is skipped entirely
    // rather than emitting an empty padded <section>, which reads on the page as a broken gap.
    $__renderable = ['hero_banner', 'category_grid', 'product_slider', 'brand_slider', 'promotional_banner',
        'split_banner', 'banner_mosaic', 'banner_strip', 'store_banner', 'usp_strip', 'newsletter',
        'custom_html', 'spacer'];
@endphp

@if (!empty($__sections))
<style>
    .ml-sections{
        --ml-ink:#191512; --ml-muted:#8a8079; --ml-line:#ece5db; --ml-sand:#f8f4ef; --ml-paper:#fff;
        --ml-gold:#b08d57; --ml-gold-soft:#d9c3a1; --ml-gold-dark:#8f7043;
        --ml-serif:"Cormorant Garamond","Playfair Display",Georgia,"Times New Roman",serif;
        --ml-gloss:linear-gradient(135deg,rgba(255,255,255,.85) 0%,rgba(255,255,255,0) 42%);
        --ml-shadow:0 18px 48px -24px rgba(25,21,18,.35);
        --ml-shadow-lg:0 34px 70px -30px rgba(25,21,18,.45);
        --ml-ease:cubic-bezier(.22,.61,.36,1);
        color:var(--ml-ink); position:relative;
    }
    .ml-sections *{ box-sizing:border-box; }
    .ml-sections .tbs{ position:relative; overflow:hidden; }
    .ml-sections .tbs > .container,.ml-sections .tbs > .container-fluid{ max-width:1280px; position:relative; z-index:1; }

    /* ---- scroll reveal ---------------------------------------------------------------- */
    .ml-reveal{ opacity:0; transform:translateY(26px); transition:opacity .8s var(--ml-ease), transform .8s var(--ml-ease); }
    .ml-reveal.is-in{ opacity:1; transform:none; }
    .ml-reveal[data-delay="1"]{ transition-delay:.08s } .ml-reveal[data-delay="2"]{ transition-delay:.16s }
    .ml-reveal[data-delay="3"]{ transition-delay:.24s } .ml-reveal[data-delay="4"]{ transition-delay:.32s }
    .ml-reveal[data-delay="5"]{ transition-delay:.4s }  .ml-reveal[data-delay="6"]{ transition-delay:.48s }

    /* ---- headings --------------------------------------------------------------------- */
    .ml-sec-head{ text-align:center; margin-bottom:2.5rem; }
    .ml-eyebrow{ display:inline-block; font-size:.7rem; letter-spacing:.32em; text-transform:uppercase;
        color:var(--ml-gold); margin-bottom:.65rem; }
    .ml-sec-head h2{ font-family:var(--ml-serif); font-weight:600; font-size:clamp(1.8rem,3.6vw,2.9rem);
        letter-spacing:.01em; margin:0; line-height:1.12;
        background:linear-gradient(92deg,var(--ml-ink) 20%,var(--ml-gold-dark) 55%,var(--ml-ink) 88%);
        -webkit-background-clip:text; background-clip:text; -webkit-text-fill-color:transparent; }
    .ml-sec-head .ml-rule{ width:64px; height:1px; margin:1.1rem auto 0; background:linear-gradient(90deg,transparent,var(--ml-gold),transparent); }
    .ml-sec-head p{ color:var(--ml-muted); margin:.85rem auto 0; max-width:54ch; }

    /* ---- buttons ---------------------------------------------------------------------- */
    .ml-btn{ position:relative; display:inline-block; overflow:hidden; font-size:.74rem; letter-spacing:.2em;
        text-transform:uppercase; font-weight:600; padding:.9rem 2.1rem; border:1px solid currentColor;
        background:transparent; color:inherit; text-decoration:none; transition:.35s var(--ml-ease); }
    .ml-btn::after{ content:""; position:absolute; top:0; left:-140%; width:60%; height:100%; transform:skewX(-22deg);
        background:linear-gradient(90deg,transparent,rgba(255,255,255,.55),transparent); transition:left .7s var(--ml-ease); }
    .ml-btn:hover{ background:var(--ml-ink); color:#fff; border-color:var(--ml-ink); text-decoration:none; }
    .ml-btn:hover::after{ left:140%; }
    .ml-btn-gold{ background:linear-gradient(120deg,var(--ml-gold),var(--ml-gold-dark)); border-color:transparent; color:#fff; }
    .ml-btn-gold:hover{ background:var(--ml-ink); }
    .ml-btn-light{ background:rgba(255,255,255,.92); color:var(--ml-ink); border-color:transparent; backdrop-filter:blur(6px); }
    .ml-btn-light:hover{ background:var(--ml-gold); color:#fff; }

    /* ---- hero slider (self-contained, no slider dependency) ---------------------------- */
    .ml-hero{ position:relative; overflow:hidden; }
    .ml-hero__track{ position:relative; }
    .ml-hero__slide{ position:absolute; inset:0; opacity:0; transition:opacity 1s var(--ml-ease); pointer-events:none; }
    .ml-hero__slide.is-active{ position:relative; opacity:1; pointer-events:auto; }
    .ml-hero__media{ position:absolute; inset:0; overflow:hidden; }
    .ml-hero__media img{ width:100%; height:100%; object-fit:cover; display:block; }
    .ml-hero.is-zoom .ml-hero__slide.is-active .ml-hero__media img{ animation:mlKenBurns 14s ease-out forwards; }
    @keyframes mlKenBurns{ from{ transform:scale(1) } to{ transform:scale(1.09) } }
    .ml-hero__scrim{ position:absolute; inset:0; }
    .ml-hero__cap{ position:relative; z-index:2; height:100%; display:flex; flex-direction:column; gap:1.1rem;
        justify-content:center; padding:clamp(1.5rem,6vw,5.5rem); max-width:min(680px,92%); }
    .ml-hero__cap.is-center{ margin:0 auto; text-align:center; align-items:center; }
    .ml-hero__cap.is-end{ margin-inline-start:auto; text-align:end; align-items:flex-end; }
    .ml-hero__cap h3{ font-family:var(--ml-serif); font-weight:600; letter-spacing:.01em;
        font-size:clamp(2rem,4.8vw,3.7rem); line-height:1.08; margin:0; }
    .ml-hero__cap p{ font-size:clamp(.95rem,1.6vw,1.1rem); opacity:.92; margin:0; max-width:46ch; }
    .ml-hero__slide.is-active .ml-hero__cap > *{ animation:mlRise .9s var(--ml-ease) both; }
    .ml-hero__slide.is-active .ml-hero__cap > *:nth-child(2){ animation-delay:.12s }
    .ml-hero__slide.is-active .ml-hero__cap > *:nth-child(3){ animation-delay:.24s }
    .ml-hero__slide.is-active .ml-hero__cap > *:nth-child(4){ animation-delay:.36s }
    @keyframes mlRise{ from{ opacity:0; transform:translateY(22px) } to{ opacity:1; transform:none } }
    .ml-hero__nav{ position:absolute; top:50%; transform:translateY(-50%); z-index:3; width:46px; height:46px;
        border-radius:50%; border:1px solid rgba(255,255,255,.5); background:rgba(255,255,255,.14);
        backdrop-filter:blur(8px); color:#fff; font-size:1.1rem; line-height:1; cursor:pointer; transition:.3s var(--ml-ease); }
    .ml-hero__nav:hover{ background:#fff; color:var(--ml-ink); }
    .ml-hero__nav.is-prev{ inset-inline-start:22px } .ml-hero__nav.is-next{ inset-inline-end:22px }
    .ml-hero__dots{ position:absolute; z-index:3; bottom:22px; inset-inline:0; display:flex; gap:.5rem; justify-content:center; }
    .ml-hero__dots button{ width:28px; height:3px; border:0; padding:0; cursor:pointer; background:rgba(255,255,255,.45); transition:.3s; }
    .ml-hero__dots button.is-active{ background:#fff; width:44px; }

    /* ---- glossy cards ------------------------------------------------------------------ */
    .ml-card{ display:block; text-decoration:none; color:inherit; background:var(--ml-paper);
        transition:transform .45s var(--ml-ease), box-shadow .45s var(--ml-ease); }
    .ml-card:hover{ transform:translateY(-6px); text-decoration:none; color:inherit; }
    .ml-thumb{ position:relative; display:block; overflow:hidden; background:var(--ml-sand); aspect-ratio:1/1;
        box-shadow:var(--ml-shadow); }
    .ml-thumb img{ width:100%; height:100%; object-fit:cover; transition:transform .8s var(--ml-ease); }
    .ml-card:hover .ml-thumb img{ transform:scale(1.07); }
    .ml-thumb::before{ content:""; position:absolute; inset:0; z-index:2; background:var(--ml-gloss); opacity:.5;
        mix-blend-mode:screen; pointer-events:none; }
    .ml-thumb::after{ content:""; position:absolute; top:0; left:-120%; z-index:3; width:55%; height:100%;
        transform:skewX(-20deg); background:linear-gradient(90deg,transparent,rgba(255,255,255,.55),transparent);
        transition:left .85s var(--ml-ease); pointer-events:none; }
    .ml-card:hover .ml-thumb::after{ left:130%; }
    .ml-name{ display:block; margin-top:.85rem; font-size:.95rem; line-height:1.45; }
    .ml-price{ display:block; margin-top:.3rem; color:var(--ml-gold-dark); font-weight:600;
        font-family:var(--ml-serif); font-size:1.1rem; }

    /* ---- category rings ----------------------------------------------------------------- */
    .ml-cat{ display:block; text-align:center; text-decoration:none; color:inherit; }
    .ml-cat:hover{ text-decoration:none; color:inherit; }
    .ml-cat-ring{ position:relative; width:112px; height:112px; border-radius:50%; margin:0 auto; overflow:hidden;
        display:flex; align-items:center; justify-content:center;
        background:radial-gradient(120% 120% at 30% 20%,#fff 0%,var(--ml-sand) 70%);
        border:1px solid var(--ml-line); box-shadow:var(--ml-shadow); transition:.45s var(--ml-ease); }
    .ml-cat-ring::before{ content:""; position:absolute; inset:0; background:var(--ml-gloss); opacity:.75; }
    .ml-cat:hover .ml-cat-ring{ border-color:var(--ml-gold-soft); transform:translateY(-6px); box-shadow:var(--ml-shadow-lg); }
    .ml-cat-ring img{ position:relative; z-index:1; width:58%; height:58%; object-fit:contain; }
    .ml-cat-name{ text-align:center; letter-spacing:.05em; }

    /* ---- promo tiles / mosaic / split ---------------------------------------------------- */
    .ml-tile{ position:relative; display:block; overflow:hidden; text-decoration:none; color:#fff;
        box-shadow:var(--ml-shadow); background:var(--ml-sand); }
    .ml-tile:hover{ text-decoration:none; color:#fff; }
    .ml-tile img{ width:100%; height:100%; object-fit:cover; display:block; transition:transform .9s var(--ml-ease); }
    .ml-tile:hover img{ transform:scale(1.06); }
    .ml-tile__scrim{ position:absolute; inset:0; background:linear-gradient(180deg,rgba(0,0,0,.05),rgba(0,0,0,.55)); }
    .ml-tile__body{ position:absolute; inset:auto 0 0 0; z-index:2; padding:clamp(1rem,2.4vw,1.9rem);
        display:flex; flex-direction:column; gap:.55rem; align-items:flex-start; }
    .ml-tile__body h4{ font-family:var(--ml-serif); font-size:clamp(1.15rem,2.2vw,1.8rem); margin:0; }
    .ml-tile__body p{ margin:0; font-size:.9rem; opacity:.9; }
    .ml-tile__badge{ position:absolute; z-index:2; top:14px; inset-inline-start:14px; padding:.35rem .8rem;
        font-size:.66rem; letter-spacing:.18em; text-transform:uppercase; color:var(--ml-ink);
        background:rgba(255,255,255,.9); backdrop-filter:blur(6px); }
    .ml-tile--wide{ grid-column:span 2 } .ml-tile--tall{ grid-row:span 2 }
    .ml-tile--large{ grid-column:span 2; grid-row:span 2 }
    .ml-mosaic{ display:grid; grid-template-columns:repeat(4,1fr); }
    @media (max-width:767px){ .ml-mosaic{ grid-template-columns:repeat(2,1fr) } .ml-tile--large,.ml-tile--wide{ grid-column:span 2 } }

    .ml-split{ display:grid; grid-template-columns:repeat(2,1fr); align-items:stretch; overflow:hidden; box-shadow:var(--ml-shadow); }
    .ml-split.is-reversed .ml-split__media{ order:2 }
    .ml-split__media{ position:relative; overflow:hidden; }
    .ml-split__media img{ width:100%; height:100%; object-fit:cover; transition:transform 1s var(--ml-ease); }
    .ml-split:hover .ml-split__media img{ transform:scale(1.05); }
    .ml-split__body{ display:flex; flex-direction:column; justify-content:center; gap:1rem;
        padding:clamp(1.5rem,4vw,3.5rem); background:var(--ml-sand); }
    .ml-split__body h3{ font-family:var(--ml-serif); font-size:clamp(1.5rem,3vw,2.4rem); margin:0; }
    .ml-split__body p{ color:var(--ml-muted); margin:0; max-width:46ch; }
    .ml-split__body .ml-btn{ align-self:flex-start; }
    @media (max-width:767px){ .ml-split{ grid-template-columns:1fr } .ml-split.is-reversed .ml-split__media{ order:0 } }

    /* ---- full-width strip ---------------------------------------------------------------- */
    .ml-strip{ position:relative; display:flex; align-items:center; justify-content:center; text-align:center;
        overflow:hidden; }
    .ml-strip__bg{ position:absolute; inset:-12% 0; background-size:cover; background-position:center; }
    .ml-strip.is-parallax .ml-strip__bg{ will-change:transform; }
    .ml-strip__scrim{ position:absolute; inset:0; background:#000; }
    .ml-strip__body{ position:relative; z-index:2; display:flex; flex-direction:column; align-items:center;
        gap:1rem; padding:clamp(1.5rem,5vw,3rem); }
    .ml-strip__body h3{ font-family:var(--ml-serif); font-size:clamp(1.7rem,4vw,3rem); margin:0; }
    .ml-strip__body p{ margin:0; opacity:.9; max-width:52ch; }

    /* ---- brands marquee ------------------------------------------------------------------ */
    .ml-marquee{ position:relative; overflow:hidden; -webkit-mask-image:linear-gradient(90deg,transparent,#000 8%,#000 92%,transparent);
        mask-image:linear-gradient(90deg,transparent,#000 8%,#000 92%,transparent); }
    .ml-marquee__track{ display:flex; align-items:center; gap:3.5rem; width:max-content; animation:mlMarquee 34s linear infinite; }
    .ml-marquee:hover .ml-marquee__track{ animation-play-state:paused; }
    @keyframes mlMarquee{ from{ transform:translateX(0) } to{ transform:translateX(-50%) } }
    .ml-brand img{ height:56px; width:auto; max-width:150px; object-fit:contain; filter:grayscale(1); opacity:.6; transition:.35s var(--ml-ease); }
    .ml-brand:hover img{ filter:none; opacity:1; transform:translateY(-3px); }

    /* ---- highlights ----------------------------------------------------------------------- */
    .ml-usp{ display:flex; align-items:center; gap:1rem; text-decoration:none; color:inherit; height:100%; }
    .ml-usp.is-boxed{ padding:1.25rem 1.35rem; background:rgba(255,255,255,.72); border:1px solid var(--ml-line);
        backdrop-filter:blur(10px); box-shadow:var(--ml-shadow); transition:.4s var(--ml-ease); }
    .ml-usp.is-boxed:hover{ transform:translateY(-4px); border-color:var(--ml-gold-soft); }
    .ml-usp__icon{ flex:0 0 auto; width:46px; height:46px; display:flex; align-items:center; justify-content:center;
        border-radius:50%; color:var(--ml-gold-dark);
        background:radial-gradient(120% 120% at 30% 20%,#fff,var(--ml-sand)); border:1px solid var(--ml-line); }
    .ml-usp__icon svg{ width:22px; height:22px; }
    .ml-usp strong{ display:block; font-size:.95rem; }
    .ml-usp span{ display:block; font-size:.82rem; color:var(--ml-muted); }

    /* ---- newsletter -------------------------------------------------------------------------- */
    .ml-news{ position:relative; overflow:hidden; text-align:center; color:#fff;
        background:radial-gradient(120% 160% at 15% 0%,#2b2622 0%,var(--ml-ink) 55%);
        padding:clamp(2.25rem,5vw,4rem); }
    .ml-news::before{ content:""; position:absolute; inset:0;
        background:linear-gradient(120deg,rgba(176,141,87,.35),transparent 45%); }
    .ml-news > *{ position:relative; z-index:1; }
    .ml-news h3{ font-family:var(--ml-serif); font-size:clamp(1.7rem,3vw,2.4rem); margin:0 0 .5rem; }
    .ml-news p{ opacity:.78; margin:0 auto 1.6rem; max-width:48ch; }
    .ml-news-form{ display:flex; gap:.5rem; max-width:460px; margin:0 auto; }
    .ml-news-form input{ flex:1; padding:.9rem 1.1rem; color:#fff; border:1px solid rgba(255,255,255,.25);
        background:rgba(255,255,255,.06); backdrop-filter:blur(8px); }
    .ml-news-form input::placeholder{ color:rgba(255,255,255,.55); }

    @media (prefers-reduced-motion: reduce){
        .ml-sections *{ animation:none !important; transition-duration:.01ms !important; }
        .ml-reveal{ opacity:1; transform:none; }
    }
</style>

<div class="theme-builder-sections ml-sections">
    @foreach ($__sections as $__section)
        @php
            $type = $__section['type'] ?? null;
            $s = $__section['settings'] ?? [];
            $blocks = $__data->blockCards($__section['blocks'] ?? []);
            $pt = (int) ($s['padding_top'] ?? 56);
            $pb = (int) ($s['padding_bottom'] ?? 56);
            $bg = $s['background'] ?? null;
            $full = ($s['width'] ?? 'container') === 'full';
            $wrapStyle = "padding-top:{$pt}px;padding-bottom:{$pb}px;" . ($bg ? "background:{$bg};" : '');
            $gap = (int) ($s['gap'] ?? 16);
        @endphp

        @continue(($s['visible'] ?? true) === false || !in_array($type, $__renderable, true))

        <section class="tbs tbs-{{ $type }}" style="{{ $wrapStyle }}" data-tb-section="{{ $__section['id'] ?? '' }}">
            <div class="{{ $full ? 'container-fluid px-0' : 'container' }}">
                @switch($type)

                    @case('hero_banner')
                        @include('theme-sections.partials.hero', ['slides' => $blocks, 'settings' => $s, 'index' => $loop->index, 'placeholder' => $__placeholder])
                        @break

                    @case('category_grid')
                        @php $cats = $__data->categories((int) ($s['limit'] ?? 12)); $cols = max(2, (int) ($s['columns'] ?? 6)); @endphp
                        @if ($cats->isNotEmpty())
                            <div class="ml-sec-head ml-reveal">
                                <span class="ml-eyebrow">{{ $s['eyebrow'] ?: translate('shop_by_category') }}</span>
                                @if (!empty($s['title']))<h2>{{ $s['title'] }}</h2>@endif
                                <div class="ml-rule"></div>
                            </div>
                            <div class="row g-4 justify-content-center">
                                @foreach ($cats as $cat)
                                    <div class="col-4 col-md-{{ max(2, (int) floor(12 / $cols)) }} ml-reveal" data-delay="{{ $loop->index % 6 }}">
                                        <a href="{{ route('products', ['category_id' => $cat->id]) }}" class="ml-cat">
                                            <span class="ml-cat-ring">
                                                @if ($cat->icon)
                                                    <img src="{{ getStorageImages(path: $cat->icon, type: 'category') }}" alt="{{ $cat->name }}" loading="lazy">
                                                @endif
                                            </span>
                                            <span class="ml-name ml-cat-name">{{ $cat->name }}</span>
                                        </a>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                        @break

                    @case('product_slider')
                        @php $products = $__data->products($s); $cols = max(2, (int) ($s['columns'] ?? 4)); @endphp
                        @if ($products->isNotEmpty())
                            <div class="ml-sec-head ml-reveal">
                                <span class="ml-eyebrow">{{ $s['eyebrow'] ?: translate('curated_for_you') }}</span>
                                @if (!empty($s['title']))<h2>{{ $s['title'] }}</h2>@endif
                                <div class="ml-rule"></div>
                                @if (!empty($s['subtitle']))<p>{{ $s['subtitle'] }}</p>@endif
                            </div>
                            <div class="row g-4">
                                @foreach ($products as $product)
                                    <div class="col-6 col-md-{{ max(2, (int) floor(12 / $cols)) }} ml-reveal" data-delay="{{ $loop->index % 6 }}">
                                        <a href="{{ route('product', $product->slug) }}" class="ml-card">
                                            <span class="ml-thumb">
                                                <img src="{{ getStorageImages(path: $product->thumbnail, type: 'product') }}" alt="{{ $product->name }}" loading="lazy">
                                            </span>
                                            <span class="ml-name text-truncate">{{ $product->name }}</span>
                                            <span class="ml-price">{{ webCurrencyConverter(amount: $product->unit_price) }}</span>
                                        </a>
                                    </div>
                                @endforeach
                            </div>
                            @if ($s['view_all'] ?? true)
                                <div class="text-center mt-4 ml-reveal">
                                    <a href="{{ route('products') }}" class="ml-btn">{{ translate('view_all') }}</a>
                                </div>
                            @endif
                        @endif
                        @break

                    @case('brand_slider')
                        @php $brands = $__data->brands((int) ($s['limit'] ?? 12)); @endphp
                        @if ($brands->isNotEmpty())
                            <div class="ml-sec-head ml-reveal">
                                @if (!empty($s['eyebrow']))<span class="ml-eyebrow">{{ $s['eyebrow'] }}</span>@endif
                                @if (!empty($s['title']))<h2>{{ $s['title'] }}</h2>@endif
                                <div class="ml-rule"></div>
                            </div>
                            @if ($s['marquee'] ?? true)
                                <div class="ml-marquee ml-reveal">
                                    <div class="ml-marquee__track">
                                        @foreach ($brands->concat($brands) as $brand)
                                            <a href="{{ route('products', ['brand_id' => $brand->id]) }}" class="ml-brand" aria-hidden="{{ $loop->index >= $brands->count() ? 'true' : 'false' }}">
                                                <img src="{{ getStorageImages(path: $brand->image, type: 'brand') }}" alt="{{ $brand->name }}" loading="lazy">
                                            </a>
                                        @endforeach
                                    </div>
                                </div>
                            @else
                                <div class="row g-4 align-items-center justify-content-center">
                                    @foreach ($brands as $brand)
                                        <div class="col-4 col-md-2 text-center ml-brand ml-reveal" data-delay="{{ $loop->index % 6 }}">
                                            <a href="{{ route('products', ['brand_id' => $brand->id]) }}">
                                                <img src="{{ getStorageImages(path: $brand->image, type: 'brand') }}" alt="{{ $brand->name }}" loading="lazy">
                                            </a>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        @endif
                        @break

                    @case('promotional_banner')
                        @include('theme-sections.partials.banner-grid', [
                            'cards' => $blocks, 'settings' => $s, 'placeholder' => $__placeholder,
                            'columns' => max(1, (int) ($s['columns'] ?? 2)), 'gap' => $gap,
                        ])
                        @break

                    @case('split_banner')
                        @include('theme-sections.partials.banner-split', [
                            'cards' => $blocks, 'settings' => $s, 'placeholder' => $__placeholder, 'gap' => $gap,
                        ])
                        @break

                    @case('banner_mosaic')
                        @include('theme-sections.partials.banner-mosaic', [
                            'cards' => $blocks, 'settings' => $s, 'placeholder' => $__placeholder, 'gap' => $gap,
                        ])
                        @break

                    @case('banner_strip')
                        @include('theme-sections.partials.banner-strip', [
                            'card' => [
                                'image' => $s['image'] ?? null, 'eyebrow' => $s['eyebrow'] ?? null,
                                'title' => $s['title'] ?? null, 'subtitle' => $s['subtitle'] ?? null,
                                'link' => $s['link'] ?? null, 'button_text' => $s['button_text'] ?? null,
                            ],
                            'settings' => $s, 'placeholder' => $__placeholder,
                        ])
                        @break

                    {{-- Banners created in Promotion -> Banners, rendered in whichever presentation
                         the merchant picked. This is what makes a dashboard banner show up in the theme. --}}
                    @case('store_banner')
                        @php
                            $cards = $__data->dashboardBanners((string) ($s['banner_type'] ?? 'Main Banner'), (int) ($s['limit'] ?? 6));
                            $layout = (string) ($s['layout'] ?? 'carousel');
                        @endphp
                        @if (count($cards))
                            @if (!empty($s['title']) || !empty($s['subtitle']))
                                <div class="ml-sec-head ml-reveal">
                                    @if (!empty($s['title']))<h2>{{ $s['title'] }}</h2>@endif
                                    <div class="ml-rule"></div>
                                    @if (!empty($s['subtitle']))<p>{{ $s['subtitle'] }}</p>@endif
                                </div>
                            @endif

                            @if ($layout === 'carousel')
                                @include('theme-sections.partials.hero', ['slides' => $cards, 'settings' => $s, 'index' => 'sb' . $loop->index, 'placeholder' => $__placeholder])
                            @elseif ($layout === 'mosaic')
                                @include('theme-sections.partials.banner-mosaic', ['cards' => $cards, 'settings' => $s, 'placeholder' => $__placeholder, 'gap' => $gap])
                            @elseif ($layout === 'split')
                                @include('theme-sections.partials.banner-split', ['cards' => $cards, 'settings' => $s, 'placeholder' => $__placeholder, 'gap' => $gap])
                            @elseif ($layout === 'strip')
                                @include('theme-sections.partials.banner-strip', ['card' => $cards[0], 'settings' => $s, 'placeholder' => $__placeholder])
                            @else
                                @include('theme-sections.partials.banner-grid', [
                                    'cards' => $cards, 'settings' => $s, 'placeholder' => $__placeholder,
                                    'columns' => max(1, (int) ($s['columns'] ?? 3)), 'gap' => $gap,
                                ])
                            @endif
                        @endif
                        @break

                    @case('usp_strip')
                        @php $cols = max(1, (int) ($s['columns'] ?? 4)); $boxed = (bool) ($s['boxed'] ?? true); @endphp
                        @if (count($blocks))
                            <div class="row g-3">
                                @foreach ($blocks as $card)
                                    <div class="col-6 col-md-{{ max(2, (int) floor(12 / $cols)) }} ml-reveal" data-delay="{{ $loop->index % 6 }}">
                                        <a class="ml-usp {{ $boxed ? 'is-boxed' : '' }}" href="{{ $card['link'] ?: 'javascript:void(0)' }}">
                                            <span class="ml-usp__icon">
                                                @if (!empty($card['image']))
                                                    <img src="{{ $card['image'] }}" alt="" width="22" height="22" loading="lazy">
                                                @else
                                                    @include('theme-sections.partials.usp-icon', ['icon' => $card['icon'] ?? 'shipping'])
                                                @endif
                                            </span>
                                            <span>
                                                <strong>{{ $card['title'] }}</strong>
                                                <span>{{ $card['subtitle'] }}</span>
                                            </span>
                                        </a>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                        @break

                    @case('newsletter')
                        <div class="ml-news ml-reveal">
                            @if (!empty($s['title']))<h3>{{ $s['title'] }}</h3>@endif
                            @if (!empty($s['subtitle']))<p>{{ $s['subtitle'] }}</p>@endif
                            <form class="ml-news-form" onsubmit="return false;">
                                <input type="email" placeholder="{{ translate('your_email_address') }}" aria-label="{{ translate('email') }}">
                                <button type="submit" class="ml-btn ml-btn-gold">{{ translate('subscribe') }}</button>
                            </form>
                        </div>
                        @break

                    @case('custom_html')
                        <div class="ml-reveal">{{ $s['content'] ?? '' }}</div>
                        @break

                    @case('spacer')
                        <div style="height:{{ (int) ($s['height'] ?? 40) }}px"></div>
                        @break

                @endswitch
            </div>
        </section>
    @endforeach
</div>

<script>
    "use strict";
    (function () {
        var root = document.querySelector('.ml-sections');
        if (!root) return;
        var calm = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        // Reveal on scroll. Without IntersectionObserver everything is shown immediately, so the
        // content is never hidden behind a capability the browser lacks.
        var revealables = root.querySelectorAll('.ml-reveal');
        if (calm || !('IntersectionObserver' in window)) {
            revealables.forEach(function (el) { el.classList.add('is-in'); });
        } else {
            var observer = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    if (!entry.isIntersecting) return;
                    entry.target.classList.add('is-in');
                    observer.unobserve(entry.target);
                });
            }, {rootMargin: '0px 0px -8% 0px', threshold: 0.08});
            revealables.forEach(function (el) { observer.observe(el); });
        }

        // Hero sliders — self-contained so the section does not depend on the theme's slider library.
        root.querySelectorAll('[data-ml-slider]').forEach(function (slider) {
            var slides = Array.prototype.slice.call(slider.querySelectorAll('.ml-hero__slide'));
            if (slides.length < 2) return;

            var dots = Array.prototype.slice.call(slider.querySelectorAll('.ml-hero__dots button'));
            var interval = parseInt(slider.dataset.interval, 10) || 5000;
            var autoplay = slider.dataset.autoplay === '1' && !calm;
            var current = 0;
            var timer = null;

            function show(next) {
                current = (next + slides.length) % slides.length;
                slides.forEach(function (slide, i) { slide.classList.toggle('is-active', i === current); });
                dots.forEach(function (dot, i) { dot.classList.toggle('is-active', i === current); });
            }
            function play() { if (autoplay) { stop(); timer = setInterval(function () { show(current + 1); }, Math.max(2000, interval)); } }
            function stop() { if (timer) { clearInterval(timer); timer = null; } }

            slider.querySelectorAll('[data-ml-slide]').forEach(function (button) {
                button.addEventListener('click', function () {
                    show(current + (button.dataset.mlSlide === 'next' ? 1 : -1));
                    play();
                });
            });
            dots.forEach(function (dot, i) { dot.addEventListener('click', function () { show(i); play(); }); });
            slider.addEventListener('mouseenter', stop);
            slider.addEventListener('mouseleave', play);

            var startX = null;
            slider.addEventListener('touchstart', function (e) { startX = e.touches[0].clientX; }, {passive: true});
            slider.addEventListener('touchend', function (e) {
                if (startX === null) return;
                var delta = e.changedTouches[0].clientX - startX;
                if (Math.abs(delta) > 45) show(current + (delta < 0 ? 1 : -1));
                startX = null;
            });

            show(0);
            play();
        });

        // Parallax strips: transform only, driven by rAF, and skipped entirely for reduced motion.
        var strips = Array.prototype.slice.call(root.querySelectorAll('.ml-strip.is-parallax'));
        if (strips.length && !calm) {
            var ticking = false;
            var move = function () {
                strips.forEach(function (strip) {
                    var box = strip.getBoundingClientRect();
                    if (box.bottom < 0 || box.top > window.innerHeight) return;
                    var progress = (box.top + box.height / 2 - window.innerHeight / 2) / window.innerHeight;
                    var layer = strip.querySelector('.ml-strip__bg');
                    if (layer) layer.style.transform = 'translate3d(0,' + (progress * -28).toFixed(2) + 'px,0)';
                });
                ticking = false;
            };
            window.addEventListener('scroll', function () {
                if (ticking) return;
                ticking = true;
                window.requestAnimationFrame(move);
            }, {passive: true});
            move();
        }
    })();
</script>
@endif
