{{-- One shop card for the vendor sections.

     What a buyer weighs before walking into a store, in that order: its cover, its logo and name,
     what other buyers rate it, and how much it sells. Rating and product count come from the same
     ShopService the storefront's own /vendors page uses, so the two never disagree. --}}
@php
    $__shopUrl = \Illuminate\Support\Facades\Route::has('vendor-shop') && $shop->slug
        ? route('vendor-shop', ['slug' => $shop->slug])
        : route('products', ['seller_id' => $shop->seller_id]);
    $__closed = ($shop->is_vacation_mode_now ?? false) || ($shop->temporary_close ?? false);
    $__stats = $stats ?? true;
@endphp
<a class="ml-vendor {{ ($compact ?? false) ? 'is-compact' : '' }}" href="{{ $__shopUrl }}">
    @unless ($compact ?? false)
        <span class="ml-vendor__cover">
            <img src="{{ getStorageImages(path: $shop->banner_full_url, type: 'shop-banner') }}"
                 alt="{{ $shop->name }}" loading="lazy">
        </span>
    @endunless

    <span class="ml-vendor__body">
        <span class="ml-vendor__logo">
            <img src="{{ getStorageImages(path: $shop->image_full_url, type: 'shop') }}" alt="{{ $shop->name }}" loading="lazy">
        </span>
        <span class="ml-vendor__id">
            <b>{{ $shop->name }}</b>
            @if ($__stats)
                <span class="ml-vendor__stats">
                    @if ($shop->average_rating > 0)
                        <span class="ml-vendor__rating"><i class="fa fa-star"></i>{{ number_format($shop->average_rating, 1) }}</span>
                    @endif
                    <span>{{ $shop->products_count }} {{ translate('products') }}</span>
                </span>
            @endif
        </span>
        @if ($__closed)
            <span class="ml-vendor__closed">{{ translate('closed_now') }}</span>
        @endif
    </span>
</a>
