<?php

namespace App\Services\Analytics;

/**
 * One thing a person did.
 *
 * Deliberately a value object rather than an array: every event in the system is built through
 * this constructor, so there is exactly one place where a name is normalised, a value is coerced
 * and properties are checked — and adding a new event anywhere in the codebase cannot invent a
 * seventh spelling of "add to cart".
 */
final class AnalyticsEvent
{
    // Page and navigation.
    public const PAGE_VIEWED = 'page_viewed';

    // Catalogue.
    public const PRODUCT_VIEWED = 'product_viewed';
    public const PRODUCT_LIST_VIEWED = 'product_list_viewed';
    public const CATEGORY_VIEWED = 'category_viewed';
    public const SHOP_VIEWED = 'shop_viewed';
    public const BRAND_VIEWED = 'brand_viewed';
    public const SEARCH_PERFORMED = 'search_performed';
    public const SEARCH_NO_RESULTS = 'search_no_results';

    // Cart and wishlist.
    public const CART_ADDED = 'cart_added';
    public const CART_REMOVED = 'cart_removed';
    public const CART_UPDATED = 'cart_updated';
    public const CART_VIEWED = 'cart_viewed';
    public const WISHLIST_ADDED = 'wishlist_added';
    public const COMPARE_ADDED = 'compare_added';

    // Checkout.
    public const CHECKOUT_STARTED = 'checkout_started';
    public const CHECKOUT_ADDRESS = 'checkout_address_set';
    public const CHECKOUT_SHIPPING = 'checkout_shipping_set';
    public const CHECKOUT_PAYMENT = 'checkout_payment_selected';
    public const COUPON_APPLIED = 'coupon_applied';
    public const COUPON_REJECTED = 'coupon_rejected';

    // Money.
    public const PAYMENT_STARTED = 'payment_started';
    public const PAYMENT_SUCCEEDED = 'payment_succeeded';
    public const PAYMENT_FAILED = 'payment_failed';
    public const ORDER_PLACED = 'order_placed';
    public const ORDER_CANCELLED = 'order_cancelled';
    public const ORDER_DELIVERED = 'order_delivered';
    public const REFUND_REQUESTED = 'refund_requested';

    // Account.
    public const SIGNED_UP = 'signed_up';
    public const SIGNED_IN = 'signed_in';
    public const REVIEW_SUBMITTED = 'review_submitted';

    // Campaign.
    public const CAMPAIGN_CLICKED = 'campaign_clicked';

    /** Which family each event belongs to, for the reports that group by area. */
    private const CATEGORIES = [
        self::PAGE_VIEWED => 'page',
        self::PRODUCT_VIEWED => 'catalogue',
        self::PRODUCT_LIST_VIEWED => 'catalogue',
        self::CATEGORY_VIEWED => 'catalogue',
        self::SHOP_VIEWED => 'catalogue',
        self::BRAND_VIEWED => 'catalogue',
        self::SEARCH_PERFORMED => 'search',
        self::SEARCH_NO_RESULTS => 'search',
        self::CART_ADDED => 'cart',
        self::CART_REMOVED => 'cart',
        self::CART_UPDATED => 'cart',
        self::CART_VIEWED => 'cart',
        self::WISHLIST_ADDED => 'cart',
        self::COMPARE_ADDED => 'cart',
        self::CHECKOUT_STARTED => 'checkout',
        self::CHECKOUT_ADDRESS => 'checkout',
        self::CHECKOUT_SHIPPING => 'checkout',
        self::CHECKOUT_PAYMENT => 'checkout',
        self::COUPON_APPLIED => 'checkout',
        self::COUPON_REJECTED => 'checkout',
        self::PAYMENT_STARTED => 'payment',
        self::PAYMENT_SUCCEEDED => 'payment',
        self::PAYMENT_FAILED => 'payment',
        self::ORDER_PLACED => 'order',
        self::ORDER_CANCELLED => 'order',
        self::ORDER_DELIVERED => 'order',
        self::REFUND_REQUESTED => 'order',
        self::SIGNED_UP => 'account',
        self::SIGNED_IN => 'account',
        self::REVIEW_SUBMITTED => 'account',
        self::CAMPAIGN_CLICKED => 'campaign',
    ];

    /**
     * The events a browser is allowed to send.
     *
     * Everything money-related is server-side only. A page that could post "order_placed" with a
     * value of its choosing would make revenue analytics a suggestion box.
     */
    private const CLIENT_ALLOWED = [
        self::PAGE_VIEWED,
        self::PRODUCT_VIEWED,
        self::PRODUCT_LIST_VIEWED,
        self::CATEGORY_VIEWED,
        self::SHOP_VIEWED,
        self::BRAND_VIEWED,
        self::CART_VIEWED,
        self::WISHLIST_ADDED,
        self::COMPARE_ADDED,
        self::CHECKOUT_STARTED,
    ];

    public function __construct(
        public readonly string $name,
        public readonly ?string $entityType = null,
        public readonly ?string $entityId = null,
        public readonly ?int $vendorId = null,
        public readonly ?float $value = null,
        public readonly ?string $currency = null,
        public readonly ?int $quantity = null,
        public readonly array $properties = [],
        public readonly ?string $dedupeKey = null,
        public readonly ?string $path = null,
    ) {
    }

    public function category(): string
    {
        return self::CATEGORIES[$this->name] ?? 'other';
    }

    public static function isKnown(string $name): bool
    {
        return isset(self::CATEGORIES[$name]);
    }

    public static function isClientAllowed(string $name): bool
    {
        return in_array($name, self::CLIENT_ALLOWED, true);
    }

    /** @return array<int, string> */
    public static function names(): array
    {
        return array_keys(self::CATEGORIES);
    }
}
