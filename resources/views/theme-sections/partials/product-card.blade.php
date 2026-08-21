{{-- One themed product card, shared by the rail and the grid presentations of product_slider,
     the flash-deal strip and the category showcase.

     Price and discount come from the catalogue's own helper, so a themed home shows exactly the
     price the rest of the storefront charges. The add-to-cart button reuses the storefront's own
     cart flow (the `.addToCartDynamicForm` + `.product-add-to-cart-button` pair that custom.js
     already binds), so a themed card and a built-in card put the same row in the same cart. A
     product that needs a choice (colour, size, any variation) opens the quick view instead of
     guessing a variant. --}}
@php
    $__hasDiscount = getProductPriceByType(product: $product, type: 'discount', result: 'value') > 0;
    $__discountLabel = $__hasDiscount
        ? getProductPriceByType(product: $product, type: 'discount', result: 'string')
        : null;

    $__addToCart = $addToCart ?? false;
    $__soldOut = $product->product_type === 'physical' && $product->current_stock <= 0;
    $__needsChoice = count(json_decode($product->colors ?? '[]') ?: []) > 0
        || count(json_decode($product->choice_options ?? '[]') ?: []) > 0;
@endphp
<div class="ml-card product-cart-option-container">
    <a href="{{ route('product', $product->slug) }}" class="ml-card__link">
        <span class="ml-thumb">
            @if ($__discountLabel)
                <span class="ml-off">{{ $__discountLabel }}</span>
            @endif
            @if ($__soldOut)
                <span class="ml-soldout">{{ translate('out_of_stock') }}</span>
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

    @if ($__addToCart)
        <div class="ml-card__cart">
            @if ($__soldOut)
                <button type="button" class="ml-cart-btn" disabled>{{ translate('out_of_stock') }}</button>
            @elseif ($__needsChoice)
                <button type="button" class="ml-cart-btn action-product-quick-view" data-product-id="{{ $product->id }}">
                    {{ translate('choose_options') }}
                </button>
            @else
                <form class="addToCartDynamicForm d-none">
                    @csrf
                    <input type="hidden" name="id" value="{{ $product->id }}">
                    <input type="hidden" name="quantity" value="{{ max(1, (int) $product->minimum_order_qty) }}">
                </form>
                <button type="button" class="ml-cart-btn product-add-to-cart-button">
                    <i class="fi fi-rr-shopping-cart"></i>
                    {{ translate('add_to_cart') }}
                </button>
            @endif
        </div>
    @endif
</div>
