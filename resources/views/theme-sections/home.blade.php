{{-- Storefront renderer for the visual Theme Builder's home sections (Phase 1 theme system).
     Renders the published version's sections when a merchant has composed any; when sectionsFor()
     returns null (no published theme, or no home sections) this outputs nothing and the theme's own
     hardcoded home is shown unchanged — the compatibility shim the builder was designed around.

     Styling is a self-contained "Minimal Luxury" system scoped to .ml-sections, so it upgrades the
     look of these sections without touching (or risking) the legacy storefront blades. --}}
@php
    use Illuminate\Support\Str;
    $__sections = app(\App\Services\Theme\StorefrontThemeRenderer::class)->sectionsFor('home');
@endphp

@if (!empty($__sections))
<style>
    .ml-sections{
        --ml-ink:#1c1917; --ml-muted:#8a8079; --ml-line:#e7e1d8; --ml-sand:#f7f3ee; --ml-paper:#fff;
        --ml-gold:#b08d57; --ml-gold-dark:#8f7043;
        --ml-serif:"Cormorant Garamond","Playfair Display",Georgia,"Times New Roman",serif;
        color:var(--ml-ink);
    }
    .ml-sections .tbs{ overflow:hidden; }
    .ml-sections .tbs > .container,.ml-sections .tbs > .container-fluid{ max-width:1240px; }
    .ml-sec-head{ text-align:center; margin-bottom:2.25rem; }
    .ml-sec-head .ml-eyebrow{ display:inline-block; font-size:.72rem; letter-spacing:.28em; text-transform:uppercase;
        color:var(--ml-gold); margin-bottom:.6rem; }
    .ml-sec-head h2{ font-family:var(--ml-serif); font-weight:600; font-size:clamp(1.7rem,3.4vw,2.6rem);
        letter-spacing:.01em; margin:0; line-height:1.15; }
    .ml-sec-head .ml-rule{ width:56px; height:2px; background:var(--ml-gold); margin:1rem auto 0; }
    .ml-sec-head p{ color:var(--ml-muted); margin:.75rem auto 0; max-width:52ch; }

    /* hero */
    .ml-hero{ position:relative; }
    .ml-hero .carousel-item{ position:relative; }
    .ml-hero img{ width:100%; object-fit:cover; }
    .ml-hero .ml-hero-cap{ position:absolute; inset:0; display:flex; flex-direction:column; justify-content:center;
        align-items:flex-start; gap:1rem; padding:clamp(1.5rem,6vw,5rem); background:linear-gradient(90deg,rgba(0,0,0,.42),rgba(0,0,0,0) 65%); color:#fff; }
    .ml-hero .ml-hero-cap .ml-eyebrow{ color:#f0e6d6; }
    .ml-hero .ml-hero-cap h3{ font-family:var(--ml-serif); font-weight:600; font-size:clamp(1.8rem,4.5vw,3.4rem); margin:0; max-width:16ch; }
    .ml-hero .ml-hero-cap p{ max-width:40ch; font-size:1.05rem; opacity:.92; }
    .ml-btn{ display:inline-block; font-size:.76rem; letter-spacing:.18em; text-transform:uppercase; font-weight:600;
        padding:.85rem 2rem; border:1px solid currentColor; background:transparent; color:inherit; text-decoration:none; transition:.2s; }
    .ml-btn:hover{ background:var(--ml-ink); color:#fff; border-color:var(--ml-ink); }
    .ml-hero .ml-btn{ background:#fff; color:var(--ml-ink); border-color:#fff; }
    .ml-hero .ml-btn:hover{ background:var(--ml-gold); color:#fff; border-color:var(--ml-gold); }

    /* cards / grid */
    .ml-card{ display:block; text-decoration:none; color:inherit; background:var(--ml-paper); transition:.25s; }
    .ml-card:hover{ transform:translateY(-4px); }
    .ml-card .ml-thumb{ position:relative; overflow:hidden; background:var(--ml-sand); aspect-ratio:1/1; }
    .ml-card .ml-thumb img{ width:100%; height:100%; object-fit:cover; transition:transform .5s ease; }
    .ml-card:hover .ml-thumb img{ transform:scale(1.05); }
    .ml-card .ml-name{ display:block; margin-top:.75rem; font-size:.95rem; line-height:1.4; }
    .ml-card .ml-cat-name{ text-align:center; letter-spacing:.04em; }
    .ml-price{ display:block; margin-top:.25rem; color:var(--ml-gold-dark); font-weight:600; font-family:var(--ml-serif); font-size:1.05rem; }
    .ml-cat{ text-align:center; text-decoration:none; color:inherit; }
    .ml-cat .ml-cat-ring{ width:104px;height:104px;border-radius:50%; margin:0 auto; overflow:hidden; background:var(--ml-sand);
        border:1px solid var(--ml-line); display:flex; align-items:center; justify-content:center; transition:.25s; }
    .ml-cat:hover .ml-cat-ring{ border-color:var(--ml-gold); }
    .ml-cat .ml-cat-ring img{ width:60%;height:60%; object-fit:contain; }
    .ml-view-all{ text-align:center; margin-top:2rem; }
    .ml-promo img{ width:100%; height:100%; object-fit:cover; border-radius:2px; transition:.3s; }
    .ml-promo a{ display:block; overflow:hidden; }
    .ml-promo a:hover img{ transform:scale(1.03); }
    .ml-brand img{ height:54px; width:100%; object-fit:contain; filter:grayscale(1); opacity:.65; transition:.25s; }
    .ml-brand:hover img{ filter:none; opacity:1; }
    .ml-news{ background:var(--ml-ink); color:#fff; text-align:center; padding:clamp(2rem,5vw,3.5rem); }
    .ml-news h3{ font-family:var(--ml-serif); font-size:clamp(1.6rem,3vw,2.2rem); margin:0 0 .5rem; }
    .ml-news p{ opacity:.8; margin:0 auto 1.5rem; max-width:46ch; }
    .ml-news .ml-news-form{ display:flex; gap:.5rem; max-width:440px; margin:0 auto; }
    .ml-news input{ flex:1; padding:.85rem 1rem; border:1px solid rgba(255,255,255,.25); background:transparent; color:#fff; }
    .ml-news input::placeholder{ color:rgba(255,255,255,.55); }
    .ml-news .ml-btn{ background:var(--ml-gold); border-color:var(--ml-gold); color:#fff; }
    .ml-news .ml-btn:hover{ background:#fff; color:var(--ml-ink); border-color:#fff; }
</style>

<div class="theme-builder-sections ml-sections">
    @foreach ($__sections as $__section)
        @php
            $type = $__section['type'] ?? null;
            $s = $__section['settings'] ?? [];
            $blocks = $__section['blocks'] ?? [];
            $pt = (int) ($s['padding_top'] ?? 56);
            $pb = (int) ($s['padding_bottom'] ?? 56);
            $bg = $s['background'] ?? null;
            $full = ($s['width'] ?? 'container') === 'full';
            $wrapStyle = "padding-top:{$pt}px;padding-bottom:{$pb}px;" . ($bg ? "background:{$bg};" : '');
        @endphp

        @if (($s['visible'] ?? true) === false) @continue @endif

        <section class="tbs tbs-{{ $type }}" style="{{ $wrapStyle }}">
            <div class="{{ $full ? 'container-fluid px-0' : 'container' }}">
                @switch($type)

                    @case('hero_banner')
                        @if (count($blocks))
                            <div id="ml-hero-{{ $loop->index }}" class="ml-hero carousel slide" data-bs-ride="{{ ($s['autoplay'] ?? true) ? 'carousel' : 'false' }}"
                                 data-ride="{{ ($s['autoplay'] ?? true) ? 'carousel' : 'false' }}" data-bs-interval="{{ (int) ($s['interval'] ?? 5000) }}">
                                <div class="carousel-inner">
                                    @foreach ($blocks as $i => $block)
                                        @php $b = $block['settings'] ?? []; @endphp
                                        <div class="carousel-item {{ $i === 0 ? 'active' : '' }}">
                                            <img src="{{ $b['image'] ?? asset('public/assets/front-end/img/image-place-holder.png') }}"
                                                 style="height:{{ (int) ($s['height'] ?? 520) }}px" alt="{{ $b['title'] ?? '' }}">
                                            @if (!empty($b['title']) || !empty($b['subtitle']) || !empty($b['link']))
                                                <div class="ml-hero-cap">
                                                    @if (!empty($b['eyebrow']))<span class="ml-eyebrow">{{ $b['eyebrow'] }}</span>@endif
                                                    @if (!empty($b['title']))<h3>{{ $b['title'] }}</h3>@endif
                                                    @if (!empty($b['subtitle']))<p>{{ $b['subtitle'] }}</p>@endif
                                                    @if (!empty($b['link']))<a href="{{ $b['link'] }}" class="ml-btn">{{ $b['button_text'] ?? translate('shop_now') }}</a>@endif
                                                </div>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                                @if (count($blocks) > 1)
                                    <button class="carousel-control-prev" type="button" data-bs-target="#ml-hero-{{ $loop->index }}" data-bs-slide="prev"><span class="carousel-control-prev-icon"></span></button>
                                    <button class="carousel-control-next" type="button" data-bs-target="#ml-hero-{{ $loop->index }}" data-bs-slide="next"><span class="carousel-control-next-icon"></span></button>
                                @endif
                            </div>
                        @endif
                        @break

                    @case('category_grid')
                        @php $cats = \App\Models\Category::where('position', 0)->take((int) ($s['limit'] ?? 12))->get(); $cols = max(2,(int)($s['columns'] ?? 6)); @endphp
                        <div class="ml-sec-head">
                            <span class="ml-eyebrow">{{ $s['eyebrow'] ?? translate('shop_by_category') }}</span>
                            @if (!empty($s['title']))<h2>{{ $s['title'] }}</h2>@endif
                            <div class="ml-rule"></div>
                        </div>
                        <div class="row g-4 justify-content-center">
                            @foreach ($cats as $cat)
                                <div class="col-4 col-md-{{ max(2,(int)floor(12/$cols)) }}">
                                    <a href="{{ route('products', ['category_id' => $cat->id]) }}" class="ml-cat">
                                        <span class="ml-cat-ring">@if($cat->icon)<img src="{{ getStorageImages(path: $cat->icon, type: 'category') }}" alt="{{ $cat->name }}">@endif</span>
                                        <span class="ml-name ml-cat-name">{{ $cat->name }}</span>
                                    </a>
                                </div>
                            @endforeach
                        </div>
                        @break

                    @case('product_slider')
                        @php
                            $q = \App\Models\Product::active();
                            $q = match ($s['source'] ?? 'featured') {
                                'best_selling' => $q->orderByDesc('order_count'),
                                'new_arrival'  => $q->latest('id'),
                                'top_rated'    => $q->withAvg('rating','rating')->orderByDesc('rating_avg_rating'),
                                'category'     => $q->where('category_id', (int)($s['source_id'] ?? 0)),
                                'brand'        => $q->where('brand_id', (int)($s['source_id'] ?? 0)),
                                default        => $q->where('featured', 1),
                            };
                            $prods = $q->take((int)($s['limit'] ?? 8))->get();
                            $cols = max(2,(int)($s['columns'] ?? 4));
                        @endphp
                        <div class="ml-sec-head">
                            <span class="ml-eyebrow">{{ $s['eyebrow'] ?? translate('curated_for_you') }}</span>
                            @if (!empty($s['title']))<h2>{{ $s['title'] }}</h2>@endif
                            <div class="ml-rule"></div>
                            @if (!empty($s['subtitle']))<p>{{ $s['subtitle'] }}</p>@endif
                        </div>
                        <div class="row g-4">
                            @foreach ($prods as $p)
                                <div class="col-6 col-md-{{ max(2,(int)floor(12/$cols)) }}">
                                    <a href="{{ route('product', $p->slug) }}" class="ml-card">
                                        <span class="ml-thumb"><img src="{{ getStorageImages(path: $p->thumbnail, type: 'product') }}" alt="{{ $p->name }}"></span>
                                        <span class="ml-name text-truncate">{{ $p->name }}</span>
                                        <span class="ml-price">{{ webCurrencyConverter(amount: $p->unit_price) }}</span>
                                    </a>
                                </div>
                            @endforeach
                        </div>
                        @if (($s['view_all'] ?? true))<div class="ml-view-all"><a href="{{ route('products') }}" class="ml-btn" style="color:var(--ml-ink)">{{ translate('view_all') }}</a></div>@endif
                        @break

                    @case('brand_slider')
                        @php $brands = \App\Models\Brand::where('status',1)->take((int)($s['limit'] ?? 12))->get(); @endphp
                        <div class="ml-sec-head">@if (!empty($s['title']))<h2>{{ $s['title'] }}</h2>@endif<div class="ml-rule"></div></div>
                        <div class="row g-4 align-items-center justify-content-center">
                            @foreach ($brands as $brand)
                                <div class="col-4 col-md-2 ml-brand text-center">
                                    <a href="{{ route('products', ['brand_id' => $brand->id]) }}"><img src="{{ getStorageImages(path: $brand->image, type: 'brand') }}" alt="{{ $brand->name }}"></a>
                                </div>
                            @endforeach
                        </div>
                        @break

                    @case('promotional_banner')
                        @php $cols = max(1,(int)($s['columns'] ?? 2)); @endphp
                        <div class="row g-4 ml-promo">
                            @foreach ($blocks as $block)
                                @php $b = $block['settings'] ?? []; @endphp
                                <div class="col-md-{{ max(1,(int)floor(12/$cols)) }}">
                                    <a href="{{ $b['link'] ?? 'javascript:void(0)' }}"><img src="{{ $b['image'] ?? asset('public/assets/front-end/img/image-place-holder.png') }}" alt="{{ $b['title'] ?? '' }}"></a>
                                </div>
                            @endforeach
                        </div>
                        @break

                    @case('newsletter')
                        <div class="ml-news">
                            @if (!empty($s['title']))<h3>{{ $s['title'] }}</h3>@endif
                            @if (!empty($s['subtitle']))<p>{{ $s['subtitle'] }}</p>@endif
                            <form class="ml-news-form" onsubmit="return false;">
                                <input type="email" placeholder="{{ translate('your_email_address') }}" aria-label="{{ translate('email') }}">
                                <button type="submit" class="ml-btn">{{ translate('subscribe') }}</button>
                            </form>
                        </div>
                        @break

                    @case('custom_html')
                        <div>{{ $s['content'] ?? '' }}</div>
                        @break

                    @case('spacer')
                        <div style="height:{{ (int) ($s['height'] ?? 40) }}px"></div>
                        @break

                @endswitch
            </div>
        </section>
    @endforeach
</div>
@endif
