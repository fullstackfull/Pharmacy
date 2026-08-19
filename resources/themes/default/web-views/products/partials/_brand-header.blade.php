{{--
    Brand landing header: the brand's banner and the categories its products
    actually live in, as a filter strip. The categories come from the catalogue
    (BrandPageService), so a chip can never lead to an empty result. Selecting
    one narrows the list below through the page's own category_ids filter.
--}}
@php($brandBanner = $brandPageHeader['banner'] ?? null)
@php($brandCategories = $brandPageHeader['categories'] ?? collect())
@php($brandOfPage = $brandPageHeader['brand'] ?? null)
@php($activeBrandCategoryId = $brandPageHeader['activeCategoryId'] ?? null)

@if ($brandOfPage && ($brandBanner || $brandCategories->count() > 0))
    <div class="category-header mb-3 mb-md-4" dir="{{ session('direction') }}">
        @if ($brandBanner)
            <a class="category-header__banner d-block"
               href="{{ $brandBanner['url'] ?: route('brand-products', ['slug' => $brandOfPage['slug']]) }}">
                <img loading="lazy"
                     src="{{ getStorageImages(path: $brandBanner->photo_full_url, type: 'banner') }}"
                     alt="{{ $brandBanner['title'] ?: $brandOfPage['name'] }}">
            </a>
        @endif

        @if ($brandCategories->count() > 0)
            <nav class="brand-header__filters" aria-label="{{ translate('categories') }}">
                <a class="brand-header__chip {{ $activeBrandCategoryId ? '' : 'brand-header__chip--active' }}"
                   href="{{ route('brand-products', ['slug' => $brandOfPage['slug']]) }}">
                    {{ translate('all') }}
                </a>
                @foreach ($brandCategories as $brandCategory)
                    <a class="brand-header__chip {{ $activeBrandCategoryId === (int) $brandCategory['id'] ? 'brand-header__chip--active' : '' }}"
                       href="{{ route('brand-products', ['slug' => $brandOfPage['slug'], 'category_ids' => [$brandCategory['id']]]) }}">
                        {{ $brandCategory['name'] }}
                        <span class="brand-header__chip-count">{{ $brandCategory['products_count'] }}</span>
                    </a>
                @endforeach
            </nav>
        @endif
    </div>
@endif
