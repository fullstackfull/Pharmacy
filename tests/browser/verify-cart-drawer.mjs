/**
 * Cart drawer end-to-end, in the two postures this store actually has (guest
 * checkout is off in the restored database):
 *
 *   Guest      — icon click must still funnel to login (unchanged behaviour),
 *                but add-to-cart now opens the drawer with the item visible.
 *   Signed-in  — icon click opens the drawer; Escape/backdrop close it; the
 *                quantity buttons inside still hit the ajax handler; the
 *                expand link still reaches /shop-cart; RTL slides from the
 *                opposite edge; mobile stays inside the viewport.
 *
 * The QA customer is created by staging setup (qa.customer@localhost.test).
 */
import { chromium } from '@playwright/test';
import { BASE, watch, setDirection, hasServerError, dismissPromoPopup } from './_env.mjs';

const PDP = '/product/feme-hair-rosemary-oil-Iv6roz';
const CUSTOMER = { identity: 'qa.customer@localhost.test', password: 'QaCustomer!2026' };

const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
const failures = [];
const ok = (label, cond) => {
    console.log((cond ? 'PASS ' : 'FAIL ') + label);
    if (!cond) failures.push(label);
};

async function drawerBox(page) {
    return page.evaluate(() => {
        const el = document.querySelector('.k-cart-drawer');
        if (!el) return null;
        const r = el.getBoundingClientRect();
        return { x: r.x, w: r.width, right: r.right, visibility: getComputedStyle(el).visibility, vw: window.innerWidth };
    });
}

const isOpen = page => page.evaluate(() => document.body.classList.contains('k-cart-open'));

async function loginCustomer(page) {
    await page.goto(BASE + '/customer/auth/login', { waitUntil: 'domcontentloaded' });
    await page.fill('#customer-login-form input[name="user_identity"]', CUSTOMER.identity);
    await page.fill('#customer-login-form input[name="password"]', CUSTOMER.password);
    await page.click('#customer-login-form button[type="submit"]');
    await page.waitForLoadState('domcontentloaded');
    await page.waitForTimeout(1000);
}

// ---- Guest posture ------------------------------------------------------
{
    const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
    await page.goto(BASE + '/', { waitUntil: 'domcontentloaded' });
    await dismissPromoPopup(page);

    ok('guest: no drawer trigger (login funnel preserved)',
        await page.locator('.action-open-cart-drawer').count() === 0);

    await page.click('#cart_items a.navbar-tool-icon-box');
    await page.waitForLoadState('domcontentloaded');
    ok('guest: icon click funnels to login', page.url().includes('/customer/auth/login'));

    await page.goto(BASE + PDP, { waitUntil: 'domcontentloaded' });
    await dismissPromoPopup(page);
    await page.waitForTimeout(600);
    await page.click('.add-to-cart-details-form .product-add-to-cart-button');
    await page.waitForTimeout(2500);
    ok('guest: add-to-cart opens drawer', await isOpen(page));
    ok('guest: added item visible', await page.locator('.k-cart-drawer .widget-cart-item').count() >= 1);
    await page.keyboard.press('Escape');
    await page.waitForTimeout(400);
    ok('guest: escape closes', !(await isOpen(page)));
    await page.close();
}

// ---- Signed-in, desktop LTR --------------------------------------------
{
    const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
    const problems = watch(page);
    await loginCustomer(page);
    await page.goto(BASE + '/', { waitUntil: 'domcontentloaded' });
    await dismissPromoPopup(page);

    ok('customer: drawer trigger rendered', await page.locator('.action-open-cart-drawer').count() > 0);

    await page.click('.action-open-cart-drawer');
    await page.waitForTimeout(500);
    let box = await drawerBox(page);
    ok('customer: click opens drawer at right edge',
        (await isOpen(page)) && box && box.visibility === 'visible' && Math.abs(box.right - box.vw) < 2);
    ok('customer: body scroll locked',
        await page.evaluate(() => getComputedStyle(document.body).overflow === 'hidden'));
    ok('customer: did not navigate away', new URL(page.url()).pathname === '/');

    await page.keyboard.press('Escape');
    await page.waitForTimeout(400);
    box = await drawerBox(page);
    ok('customer: escape closes', !(await isOpen(page)) && box.visibility === 'hidden');

    await page.goto(BASE + PDP, { waitUntil: 'domcontentloaded' });
    await dismissPromoPopup(page);
    await page.waitForTimeout(600);
    await page.click('.add-to-cart-details-form .product-add-to-cart-button');
    await page.waitForTimeout(2500);
    ok('customer: add-to-cart opens drawer', await isOpen(page));
    ok('customer: item visible in drawer', await page.locator('.k-cart-drawer .widget-cart-item').count() >= 1);

    const qtyBefore = await page.inputValue('.k-cart-drawer .cart-qty-input').catch(() => null);
    if (qtyBefore !== null) {
        await page.click('.k-cart-drawer .quantity__plus');
        await page.waitForTimeout(2000);
        const qtyAfter = await page.inputValue('.k-cart-drawer .cart-qty-input').catch(() => null);
        ok('customer: quantity plus works in drawer', Number(qtyAfter) === Number(qtyBefore) + 1);
    } else {
        ok('customer: quantity input present in drawer', false);
    }

    await page.mouse.click(200, 450);
    await page.waitForTimeout(400);
    ok('customer: backdrop closes', !(await isOpen(page)));

    await page.goto(BASE + '/', { waitUntil: 'domcontentloaded' });
    await dismissPromoPopup(page);
    await page.click('.action-open-cart-drawer');
    await page.waitForTimeout(400);
    await page.click('.k-cart-drawer a[href*="shop-cart"]');
    await page.waitForLoadState('domcontentloaded');
    ok('customer: expand link reaches /shop-cart', page.url().includes('shop-cart'));
    ok('no server error page', !(await hasServerError(page)));

    ok('no console/JS/HTTP problems (desktop ltr)', problems.length === 0);
    if (problems.length) console.log('  problems:', problems.slice(0, 5));
    await page.close();
}

// ---- Signed-in, RTL -----------------------------------------------------
{
    const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
    await loginCustomer(page);
    await setDirection(page, 'rtl');
    await page.goto(BASE + '/', { waitUntil: 'domcontentloaded' });
    await dismissPromoPopup(page);
    ok('rtl: page direction applied', await page.getAttribute('html', 'dir') === 'rtl');

    await page.click('.action-open-cart-drawer');
    await page.waitForTimeout(500);
    const box = await drawerBox(page);
    ok('rtl: drawer at inline-end (left edge)', box && box.visibility === 'visible' && Math.abs(box.x) < 2);
    await setDirection(page, 'ltr');
    await page.close();
}

// ---- Signed-in, mobile --------------------------------------------------
{
    const page = await browser.newPage({ viewport: { width: 390, height: 844 } });
    await loginCustomer(page);
    await page.goto(BASE + '/', { waitUntil: 'domcontentloaded' });
    await dismissPromoPopup(page);
    await page.click('.action-open-cart-drawer');
    await page.waitForTimeout(500);
    const box = await drawerBox(page);
    ok('mobile: opens on tap, width ≤ 94vw', box && box.visibility === 'visible' && box.w <= box.vw * 0.95);
    ok('mobile: no horizontal overflow', await page.evaluate(() =>
        document.scrollingElement.scrollWidth - document.scrollingElement.clientWidth) === 0);
    await page.close();
}

await browser.close();
console.log(failures.length ? `FAILURES: ${failures.length}` : 'ALL CLEAN');
process.exit(failures.length ? 1 : 0);
