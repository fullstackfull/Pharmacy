{{-- One themed product card, shared by the rail and the grid presentations of product_slider.
     Price and discount come from the catalogue's own helper, so a themed home shows exactly the
     price the rest of the storefront charges. --}}
@php
    $__hasDiscount = getProductPriceByType(product: $product, type: 'discount', result: 'value') > 0;
    $__discountLabel = $__hasDiscount
        ? getProductPriceByType(product: $product, type: 'discount', result: 'string')
        : null;
@endphp
<a href="{{ route('product', $product->slug) }}" class="ml-card">
    <span class="ml-thumb">
        @if ($__discountLabel)
            <span class="ml-off">{{ $__discountLabel }}</span>
        @endif
        <img src="{{ getStorageImages(path: $product->thumbnail_full_url, type: 'product') }}"
             alt="{{ $product->name }}" loading="lazy">
    </span>
    <span class="ml-card__body">
        <span class="ml-name">{{ Str::limit($product->name, 55) }}</span>
        <span class="ml-price">
            {{ getProductPriceByType(product: $product, type: 'discounted_unit_price', result: 'string') }}
            @if ($__hasDiscount)
                <del>{{ webCurrencyConverter(amount: $product->unit_price) }}</del>
            @endif
        </span>
    </span>
</a>
