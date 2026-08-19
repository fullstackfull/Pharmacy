{{--
    Category landing header: the category's merchandising banner (inherited from
    the nearest ancestor when the open category has none) and its sub-categories
    as an entry strip. Both come from CategoryPageService; either half renders on
    its own, and the whole block disappears when the category has neither.
--}}
@php($categoryBanner = $categoryPageHeader['banner'] ?? null)
@php($categorySubCategories = $categoryPageHeader['subCategories'] ?? collect())

@if ($categoryBanner || $categorySubCategories->count() > 0)
    <div class="category-header mb-3 mb-md-4" dir="{{ session('direction') }}">
        @if ($categoryBanner)
            <a class="category-header__banner d-block"
               href="{{ $categoryBanner['url'] ?? 'javascript:' }}"
               @if (empty($categoryBanner['url'])) tabindex="-1" aria-hidden="true" @endif>
                <img loading="lazy"
                     src="{{ getStorageImages(path: $categoryBanner->photo_full_url, type: 'banner') }}"
                     alt="{{ $categoryBanner['title'] ?? $categoryPageHeader['category']['name'] }}">
                @if (!empty($categoryBanner['title']) || !empty($categoryBanner['sub_title']))
                    <span class="category-header__banner-copy">
                        @if (!empty($categoryBanner['title']))
                            <span class="category-header__banner-title">{{ $categoryBanner['title'] }}</span>
                        @endif
                        @if (!empty($categoryBanner['sub_title']))
                            <span class="category-header__banner-subtitle">{{ $categoryBanner['sub_title'] }}</span>
                        @endif
                    </span>
                @endif
            </a>
        @endif

        @if ($categorySubCategories->count() > 0)
            <nav class="category-header__subs" aria-label="{{ translate('sub_categories') }}">
                @foreach ($categorySubCategories as $subCategory)
                    <a class="category-header__sub" href="{{ route('category-products', ['slug' => $subCategory['slug']]) }}">
                        <span class="category-header__sub-img">
                            <img loading="lazy"
                                 src="{{ getStorageImages(path: $subCategory->icon_full_url, type: 'category') }}"
                                 alt="{{ $subCategory['name'] }}">
                        </span>
                        <span class="category-header__sub-name">{{ $subCategory['name'] }}</span>
                    </a>
                @endforeach
            </nav>
        @endif
    </div>
@endif
