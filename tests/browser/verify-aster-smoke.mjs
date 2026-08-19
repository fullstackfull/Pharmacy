/**
 * Aster runtime smoke test — the one surface the 51-agent audit flagged as
 * untested at runtime. The branch's aster changes are mandated-logic only
 * (captcha teardown, checkout-complete forms GET→POST + @csrf, shared theme
 * tokens + SEO supplement includes); this walk proves them live:
 * - home renders (tokens include does not fatal)
 * - login modal submits without any captcha field and authenticates
 * - PDP renders with the Product JSON-LD from seo.product-supplement
 * - COD + wallet checkout forms are POST with a _token, and a real COD
 *   submit completes an order (the frozen GET forms would 405)
 * Run with WEB_THEME=theme_aster in .env; restore it afterwards.
 */
import { chromium } from '@playwright/test';
import { BASE, watch, hasServerError } from './_env.mjs';

const PDP = process.env.PDP_SLUG || 'medee-hydra-50-ml-zYT13P';
const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
const page = await browser.newPage({ viewport: { width: 1440, height: 1100 } });
const problems = watch(page);
const failures = [];
const ok = (label, cond) => {
    console.log((cond ? 'PASS ' : 'FAIL ') + label);
    if (!cond) failures.push(label);
};

// ---- home ------------------------------------------------------------------
await page.goto(BASE + '/', { waitUntil: 'load' });
ok('home: renders without server error', !(await hasServerError(page)));
ok('home: no captcha remnants', await page.locator('.default-captcha-container, .render-grecaptcha-response').count() === 0);

// ---- login through the modal (captcha teardown, end to end) ---------------
await page.evaluate(() => {
    const modal = document.querySelector('#loginModal');
    if (modal && window.bootstrap) new window.bootstrap.Modal(modal).show();
});
await page.waitForSelector('#loginModal.show', { timeout: 6000 }).catch(() => {});
const loginForm = page.locator('#loginModal form#customer-login-form').first();
ok('login: modal form present', await loginForm.count() > 0);
await loginForm.locator('input[name="user_identity"]').first().fill('qa.customer@localhost.test');
await loginForm.locator('input[name="password"]').first().fill('aster12345');
await Promise.all([
    page.waitForLoadState('load').catch(() => {}),
    loginForm.locator('button[type="submit"]').first().click(),
]);
await page.waitForTimeout(2500);
await page.goto(BASE + '/user-account', { waitUntil: 'load' }).catch(() => {});
ok('login: authenticated (account page reachable)', !page.url().includes('auth') && !(await hasServerError(page)));

// ---- PDP + SEO supplement --------------------------------------------------
await page.goto(`${BASE}/product/${PDP}`, { waitUntil: 'load' });
ok('pdp: renders', !(await hasServerError(page)));
const jsonLd = await page.locator('script[type="application/ld+json"]').allTextContents();
ok('pdp: Product JSON-LD emitted (seo.product-supplement)', jsonLd.some(t => t.includes('"Product"')));
ok('pdp: canonical link present', await page.locator('link[rel="canonical"]').count() > 0);

// ---- add to cart (drive the theme's own addToCart with the PDP form) -------
await page.waitForFunction(() => typeof window.jQuery === 'function' && typeof window.addToCart === 'function',
    { timeout: 15000 });
const cartPost = page.waitForResponse(r => r.url().includes('cart') && r.request().method() === 'POST',
    { timeout: 15000 }).catch(() => null);
await page.evaluate(() => {
    document.querySelectorAll('.add-to-cart-details-form .variant-change').forEach(g => {
        if (!g.querySelector('input:checked')) g.querySelector('input')?.click();
    });
    window.addToCart(window.jQuery('.add-to-cart-details-form').first());
});
const cartResponse = await cartPost;
ok('cart: add-to-cart POST accepted', cartResponse !== null && cartResponse.status() === 200);
await page.waitForTimeout(1500);

// ---- shop-cart: choose the order-wise shipping method ----------------------
await page.goto(BASE + '/shop-cart', { waitUntil: 'load' });
const shipSelect = page.locator('.set-shipping-onchange').first();
if (await shipSelect.count() > 0) {
    await shipSelect.selectOption({ index: 1 });
    await page.waitForTimeout(1500);
    console.log('  shipping method selected on shop-cart');
} else {
    console.log('SKIP no shipping select rendered (already chosen or not order-wise)');
}

// ---- checkout: shipping → payment -----------------------------------------
await page.goto(BASE + '/checkout-details', { waitUntil: 'load' });
ok('checkout-details: reached (not bounced)', page.url().includes('checkout-details')
    && !(await hasServerError(page)));
await page.evaluate(() => {
    const form = document.querySelector('#address-form');
    if (!form) return;
    const set = (name, value) => {
        const el = form.querySelector(`[name="${name}"]`);
        if (el) { el.value = value; el.dispatchEvent(new Event('change', { bubbles: true })); }
    };
    set('contact_person_name', 'QA Customer');
    set('phone', '+96550001234');
    set('email', 'qa.customer@localhost.test');
    set('address', 'Salmiya Block 10, Street 1');
    set('city', 'Salmiya');
    set('zip', '20000');
    set('latitude', '29.3375');
    set('longitude', '48.0758');
    const country = form.querySelector('[name="country"]');
    if (country && country.tagName === 'SELECT') {
        const opt = [...country.options].find(o => o.value);
        if (opt) { country.value = opt.value; country.dispatchEvent(new Event('change', { bubbles: true })); }
    }
});
await page.waitForTimeout(800);
const chooseResp = page.waitForResponse(r => r.url().includes('choose-shipping-address'), { timeout: 15000 }).catch(() => null);
await page.locator('#proceed-to-next-action').click();
const resp = await chooseResp;
if (resp) console.log('  choose-shipping-address:', resp.status(), (await resp.text().catch(() => '')).slice(0, 300));
await page.waitForURL('**/checkout-payment**', { timeout: 20000 }).catch(() => {});
if (!page.url().includes('checkout-payment')) {
    await page.goto(BASE + '/checkout-payment', { waitUntil: 'load' });
}
ok('checkout-payment: reached', page.url().includes('checkout-payment') && !(await hasServerError(page)));

// the two forms the branch converted from GET (405 on the POST-only routes)
const codForm = page.locator('form.checkout-cash-on-payment').first();
ok('payment: COD form present', await codForm.count() > 0);
ok('payment: COD form method=post + _token',
    (await codForm.getAttribute('method') || '').toLowerCase() === 'post'
    && await codForm.locator('input[name="_token"]').count() === 1);
const walletForm = page.locator('form.checkout-wallet-payment-form').first();
if (await walletForm.count() > 0) {
    ok('payment: wallet form method=post + _token',
        (await walletForm.getAttribute('method') || '').toLowerCase() === 'post'
        && await walletForm.locator('input[name="_token"]').count() === 1);
} else {
    console.log('SKIP wallet form not rendered (wallet feature off) — method fix asserted for COD');
}

// ---- real COD submit through the theme's own flow: radio + proceed ---------
// (payment-page.js intercepts the form submit and AJAXes it; the branch makes
// that AJAX honor the form's POST method — a GET here means 405 and no order)
await codForm.locator('label').first().click();
const termsCheckbox = page.locator('.payment-input-checkbox').first();
if (await termsCheckbox.count() > 0 && !(await termsCheckbox.isChecked().catch(() => false))) {
    await termsCheckbox.check({ force: true });
}
await page.waitForTimeout(800);
const completeResp = page.waitForResponse(r => r.url().includes('checkout-complete'), { timeout: 20000 }).catch(() => null);
await page.locator('#proceed-to-payment-action').click();
const cr = await completeResp;
console.log('  checkout-complete:', cr ? `${cr.request().method()} → ${cr.status()}` : 'no request observed');
ok('cod: checkout-complete accepted the POST (no 405)', cr !== null
    && cr.request().method() === 'POST' && cr.status() === 200);
await page.waitForURL('**account-oder**', { timeout: 15000 }).catch(() => {});
ok('cod: redirected to the customer orders page', page.url().includes('account-oder'));
console.log('  landed on: ' + page.url());

// google-maps noise: staging has no Maps API key, so the loader script 404s
// and shipping-page's autocomplete probe throws — environment, not the branch.
const known = problems.filter(p => !p.includes('Element not found') && !p.includes('firebase')
    && !p.includes('google') && !p.includes('maps'));
ok('no console/JS/HTTP problems', known.length === 0);
if (known.length) console.log('  problems:', known.slice(0, 10));
await browser.close();
console.log(failures.length ? `FAILURES: ${failures.length}` : 'ALL CLEAN');
process.exit(failures.length ? 1 : 0);
