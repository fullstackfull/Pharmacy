{{-- Storefront renderer for the visual Theme Builder's home sections (Phase 1 theme system).
     Renders the published version's sections when a merchant has composed any; when sectionsFor()
     returns null (no published theme, or no home sections) this outputs nothing and the theme's own
     hardcoded home is shown unchanged — the compatibility shim the builder was designed around. --}}
@php
    use Illuminate\Support\Str;
    $__sections = app(\App\Services\Theme\StorefrontThemeRenderer::class)->sectionsFor('home');
@endphp

@if (!empty($__sections))
    <div class="theme-builder-sections">
        @foreach ($__sections as $__section)
            @php
                $type = $__section['type'] ?? null;
                $s = $__section['settings'] ?? [];
                $blocks = $__section['blocks'] ?? [];
                $pt = (int) ($s['padding_top'] ?? 40);
                $pb = (int) ($s['padding_bottom'] ?? 40);
                $bg = $s['background'] ?? null;
                $full = ($s['width'] ?? 'container') === 'full';
                $align = $s['alignment'] ?? 'start';
                $wrapStyle = "padding-top:{$pt}px;padding-bottom:{$pb}px;" . ($bg ? "background:{$bg};" : '');
            @endphp

            @if (($s['visible'] ?? true) === false)
                @continue
            @endif

            <section class="tbs tbs-{{ $type }}" style="{{ $wrapStyle }}">
                <div class="{{ $full ? 'container-fluid' : 'container' }} text-{{ $align }}">
                    @switch($type)

                        @case('hero_banner')
                            @if (count($blocks))
                                <div id="tbs-hero-{{ $loop->index }}" class="carousel slide" data-bs-ride="{{ ($s['autoplay'] ?? true) ? 'carousel' : 'false' }}"
                                     data-bs-interval="{{ (int) ($s['interval'] ?? 5000) }}">
                                    <div class="carousel-inner rounded overflow-hidden">
                                        @foreach ($blocks as $i => $block)
                                            @php $b = $block['settings'] ?? []; @endphp
                                            <div class="carousel-item {{ $i === 0 ? 'active' : '' }}">
                                                @if (!empty($b['link']))<a href="{{ $b['link'] }}">@endif
                                                    <img src="{{ $b['image'] ?? asset('public/assets/front-end/img/image-place-holder.png') }}"
                                                         class="d-block w-100" style="height:{{ (int) ($s['height'] ?? 420) }}px;object-fit:cover"
                                                         alt="{{ $b['title'] ?? '' }}">
                                                    @if (!empty($b['title']) || !empty($b['subtitle']))
                                                        <div class="carousel-caption d-none d-md-block">
                                                            @if (!empty($b['title']))<h3>{{ $b['title'] }}</h3>@endif
                                                            @if (!empty($b['subtitle']))<p>{{ $b['subtitle'] }}</p>@endif
                                                        </div>
                                                    @endif
                                                @if (!empty($b['link']))</a>@endif
                                            </div>
                                        @endforeach
                                    </div>
                                    @if (count($blocks) > 1)
                                        <button class="carousel-control-prev" type="button" data-bs-target="#tbs-hero-{{ $loop->index }}" data-bs-slide="prev"><span class="carousel-control-prev-icon"></span></button>
                                        <button class="carousel-control-next" type="button" data-bs-target="#tbs-hero-{{ $loop->index }}" data-bs-slide="next"><span class="carousel-control-next-icon"></span></button>
                                    @endif
                                </div>
                            @endif
                            @break

                        @case('category_grid')
                            @php
                                $cats = \App\Models\Category::where('position', 0)->where('status', 1)
                                    ->take((int) ($s['limit'] ?? 12))->get(['id', 'name', 'slug', 'icon']);
                                $cols = max(1, (int) ($s['columns'] ?? 6));
                            @endphp
                            @if (!empty($s['title']))<h2 class="mb-4">{{ $s['title'] }}</h2>@endif
                            <div class="row g-3">
                                @foreach ($cats as $cat)
                                    <div class="col-6 col-md-{{ max(1, (int) floor(12 / $cols)) }}">
                                        <a href="{{ route('products', ['category_id' => $cat->id]) }}" class="d-block text-center p-3 border rounded h-100 text-decoration-none">
                                            @if ($cat->icon)<img src="{{ getStorageImages(path: $cat->icon, type: 'category') }}" alt="{{ $cat->name }}" style="height:56px;object-fit:contain">@endif
                                            <span class="d-block mt-2 fs-14">{{ $cat->name }}</span>
                                        </a>
                                    </div>
                                @endforeach
                            </div>
                            @break

                        @case('product_slider')
                            @php
                                $q = \App\Models\Product::active()->with(['rating']);
                                $q = match ($s['source'] ?? 'featured') {
                                    'best_selling' => $q->orderByDesc('order_count'),
                                    'new_arrival'  => $q->latest('id'),
                                    'top_rated'    => $q->withAvg('rating', 'rating')->orderByDesc('rating_avg_rating'),
                                    'category'     => $q->where('category_id', (int) ($s['source_id'] ?? 0)),
                                    'brand'        => $q->where('brand_id', (int) ($s['source_id'] ?? 0)),
                                    default        => $q->where('featured', 1),
                                };
                                $prods = $q->take((int) ($s['limit'] ?? 10))->get();
                                $cols = max(1, (int) ($s['columns'] ?? 5));
                            @endphp
                            @if (!empty($s['title']))<h2 class="mb-1">{{ $s['title'] }}</h2>@endif
                            @if (!empty($s['subtitle']))<p class="text-muted mb-4">{{ $s['subtitle'] }}</p>@endif
                            <div class="row g-3">
                                @foreach ($prods as $p)
                                    <div class="col-6 col-md-{{ max(1, (int) floor(12 / $cols)) }}">
                                        <a href="{{ route('product', $p->slug) }}" class="d-block text-center border rounded p-2 h-100 text-decoration-none">
                                            <img src="{{ getStorageImages(path: $p->thumbnail, type: 'product') }}" alt="{{ $p->name }}" class="w-100" style="height:150px;object-fit:contain">
                                            <span class="d-block mt-2 fs-13 text-truncate">{{ $p->name }}</span>
                                        </a>
                                    </div>
                                @endforeach
                            </div>
                            @if (($s['view_all'] ?? true))
                                <div class="mt-3"><a href="{{ route('products') }}" class="btn btn-outline-primary btn-sm">{{ translate('view_all') }}</a></div>
                            @endif
                            @break

                        @case('brand_slider')
                            @php $brands = \App\Models\Brand::where('status', 1)->take((int) ($s['limit'] ?? 12))->get(['id', 'name', 'slug', 'image']); @endphp
                            @if (!empty($s['title']))<h2 class="mb-4">{{ $s['title'] }}</h2>@endif
                            <div class="row g-3 align-items-center">
                                @foreach ($brands as $brand)
                                    <div class="col-4 col-md-2 text-center">
                                        <a href="{{ route('products', ['brand_id' => $brand->id]) }}">
                                            <img src="{{ getStorageImages(path: $brand->image, type: 'brand') }}" alt="{{ $brand->name }}" style="height:60px;object-fit:contain">
                                        </a>
                                    </div>
                                @endforeach
                            </div>
                            @break

                        @case('promotional_banner')
                            @php $cols = max(1, (int) ($s['columns'] ?? 2)); @endphp
                            <div class="row g-3">
                                @foreach ($blocks as $block)
                                    @php $b = $block['settings'] ?? []; @endphp
                                    <div class="col-md-{{ max(1, (int) floor(12 / $cols)) }}">
                                        @if (!empty($b['link']))<a href="{{ $b['link'] }}">@endif
                                            <img src="{{ $b['image'] ?? asset('public/assets/front-end/img/image-place-holder.png') }}" class="w-100 rounded" alt="{{ $b['title'] ?? '' }}">
                                        @if (!empty($b['link']))</a>@endif
                                    </div>
                                @endforeach
                            </div>
                            @break

                        @case('newsletter')
                            <div class="text-center py-3">
                                @if (!empty($s['title']))<h3>{{ $s['title'] }}</h3>@endif
                                @if (!empty($s['subtitle']))<p class="text-muted">{{ $s['subtitle'] }}</p>@endif
                                <form class="d-flex justify-content-center gap-2 flex-wrap" onsubmit="return false;">
                                    <input type="email" class="form-control" style="max-width:320px" placeholder="{{ translate('your_email_address') }}">
                                    <button type="submit" class="btn btn-primary">{{ translate('subscribe') }}</button>
                                </form>
                            </div>
                            @break

                        @case('custom_html')
                            {{-- Builder content is admin-authored but stored as raw HTML; escape it so a
                                 compromised builder input can't inject script into the storefront. --}}
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
