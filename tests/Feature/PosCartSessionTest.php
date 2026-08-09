<?php

namespace Tests\Feature;

use App\Enums\SessionKey;
use App\Services\CartService;
use Tests\TestCase;

/**
 * The POS cart session on a fresh admin login (Phase 2.5).
 *
 * Only `POSController@index` ever established `SessionKey::CURRENT_USER`. Every other POS endpoint
 * assumed a page load had already happened — which is exactly what stops being true when the panel's
 * own AJAX fires after a session expires, or when an admin lands on the POS by URL. Two failures
 * followed, both reproduced against the running panel on a fresh admin login:
 *
 *   GET /admin/pos/get-cart-ids   ->  500  count(): Argument #1 must be of type Countable|array,
 *                                          Illuminate\Session\SessionManager given
 *   GET /admin/pos/quick-view     ->  500  getCartData(): Argument #1 ($cartName) must be of type
 *                                          string, null given
 *
 * The cause is a PHP-level trap rather than a logic slip: `session($key)` with a **null** key
 * returns the SessionManager itself, not null. So `count(session(null))` is a TypeError, and the
 * null flows onward into typed parameters.
 *
 * The same CartService backs the vendor POS, so both panels were affected and both are fixed here.
 */
class PosCartSessionTest extends TestCase
{
    private CartService $cartService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cartService = app(CartService::class);
        session()->forget(SessionKey::CURRENT_USER);
        session()->forget(SessionKey::CART_NAME);
    }

    public function test_a_fresh_session_gets_a_walk_in_cart_rather_than_null(): void
    {
        $this->assertNull(session(SessionKey::CURRENT_USER), 'precondition: no POS cart yet');

        $cartId = $this->cartService->ensureCartSession();

        $this->assertIsString($cartId);
        $this->assertStringContainsString('walk-in-customer', $cartId, 'a POS with no cart is a walk-in customer');
        $this->assertSame($cartId, session(SessionKey::CURRENT_USER));
    }

    /** Calling it again must not silently start a second cart under the admin's feet. */
    public function test_an_existing_cart_is_returned_unchanged(): void
    {
        $first = $this->cartService->ensureCartSession();
        $second = $this->cartService->ensureCartSession();

        $this->assertSame($first, $second);
        $this->assertCount(1, session(SessionKey::CART_NAME) ?? []);
    }

    /**
     * The exact call that fataled: getCartKeeper() ran `count(session($cartId))` where $cartId was
     * null, so session() handed it the SessionManager.
     */
    public function test_the_cart_keeper_survives_a_session_with_no_cart(): void
    {
        $this->cartService->getCartKeeper();

        $this->assertTrue(true, 'getCartKeeper() returned instead of fataling');
        $this->assertNull(session(SessionKey::CURRENT_USER), 'and it did not invent a cart as a side effect');
    }

    public function test_the_cart_keeper_flattens_an_existing_cart(): void
    {
        $cartId = $this->cartService->ensureCartSession();
        session()->put($cartId, [
            ['id' => 1, 'quantity' => 2],
            ['id' => 2, 'quantity' => 1],
        ]);

        $this->cartService->getCartKeeper();

        $this->assertCount(2, session($cartId));
        $this->assertSame(1, session($cartId)[0]['id']);
    }

    /**
     * Before the fix the keeper wrote its result with `session()->put(session(CURRENT_USER), ...)`,
     * re-reading a key it had just proven unreliable. It now writes to the id it actually read.
     */
    public function test_the_cart_keeper_writes_back_to_the_cart_it_read(): void
    {
        $cartId = $this->cartService->ensureCartSession();
        session()->put($cartId, [['id' => 7, 'quantity' => 3]]);

        $this->cartService->getCartKeeper();

        $this->assertSame(7, session($cartId)[0]['id']);
        $this->assertSame($cartId, session(SessionKey::CURRENT_USER), 'the current cart id must not move');
    }
}
