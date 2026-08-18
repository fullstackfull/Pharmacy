/**
 * The PDP fixes from the study, verified against staging as a guest:
 * - sticky bar honors zero stock (restock shows, buy/add hidden) — pass ZERO=1
 *   with the fixture product's stock set to 0 by the wrapper;
 * - the computed price is visible on phone and tablet widths;
 * - the in-house guest chat button goes to login (used to go to the shop page);
 * - the seller card renders through the shared partial on both product kinds;
 * - ShareThis loads only when a property id is configured (none on staging);
 * - the color-change gallery script logs nothing and never throws.
 */
import { chromium } from '@playwright/test';
import { BASE, watch, dismissPromoPopup, hasServerError } from './_env.mjs';

const ZERO = process.env.ZERO === '1';
const VENDOR_PDP = BASE + '/product/qa-vendor-product-1787046108';
const INHOUSE_PDP = BASE + '/product/feme-hair-rosemary-oil-Iv6roz';

const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
const failures = [];
const ok = (label, cond) => {
    console.log((cond ? 'PASS ' : 'FAIL ') + label);
    if (!cond) failures.push(label);
};

if (ZERO) {
    // -- Zero-stock pass: fixture product stock is 0 right now. -----------
    const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
    const problems = watch(page);
    await page.goto(VENDOR_PDP, { waitUntil: 'load' });
    await dismissPromoPopup(page);
    ok('zero: renders', !(await hasServerError(page)));
    const sticky = page.locator('.product-details-sticky');
    ok('zero: sticky hides buy/add', await sticky.locator('.product-add-and-buy-section').evaluate(el =>
        getComputedStyle(el).display === 'none').catch(() => false));
    ok('zero: sticky shows restock', await sticky.locator('.product-restock-request-section').evaluate(el =>
        getComputedStyle(el).display !== 'none').catch(() => false));
    ok('zero: main form also hides buy/add', await page.locator('.product-add-and-buy-section-parent .product-add-and-buy-section').evaluate(el =>
        getComputedStyle(el).display === 'none').catch(() => false));
    const known = problems.filter(p => !p.includes('Element not found'));
    ok('zero: no console/JS/HTTP problems', known.length === 0);
    if (known.length) console.log('  problems:', known.slice(0, 6));
    await page.close();
} else {
    // -- Normal pass (in stock). ------------------------------------------
    {
        const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
        const problems = watch(page);
        await page.goto(VENDOR_PDP, { waitUntil: 'load' });
        await dismissPromoPopup(page);
        ok('desktop: renders', !(await hasServerError(page)));
        const sticky = page.locator('.product-details-sticky');
        ok('desktop: sticky shows buy/add', await sticky.locator('.product-add-and-buy-section').evaluate(el =>
            getComputedStyle(el).display !== 'none').catch(() => false));
        ok('desktop: sticky hides restock', await sticky.locator('.product-restock-request-section').evaluate(el =>
            getComputedStyle(el).display === 'none').catch(() => false));
        ok('desktop: seller card via shared partial', (await page.locator('.chat_with_seller-buttons').count()) >= 1);
        ok('desktop: guest chat goes to login (vendor product)',
            ((await page.locator('.chat_with_seller-buttons a').first().getAttribute('href').catch(() => '')) || '').includes('/customer/auth/login'));
        ok('desktop: no ShareThis without a property id', (await page.locator('script[src*="sharethis"]').count()) === 0);

        // Color change must not throw (guarded swiper access).
        const colorInput = page.locator('input[name="color"]').first();
        if (await colorInput.count()) {
            await colorInput.check({ force: true }).catch(() => {});
            await page.waitForTimeout(800);
        }
        const known = problems.filter(p => !p.includes('Element not found'));
        ok('desktop: no console/JS/HTTP problems', known.length === 0);
        if (known.length) console.log('  problems:', known.slice(0, 6));
        await page.close();
    }
    {
        // In-house product as guest: chat link target check.
        const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
        watch(page);
        await page.goto(INHOUSE_PDP, { waitUntil: 'load' });
        await dismissPromoPopup(page);
        ok('inhouse: renders', !(await hasServerError(page)));
        const chatHref = (await page.locator('.chat_with_seller-buttons a').first().getAttribute('href').catch(() => '')) || '';
        ok('inhouse: guest chat goes to login, not the shop page', chatHref.includes('/customer/auth/login'));
        await page.close();
    }
    {
        // Phone: computed price visible in the sticky bar and live.
        const page = await browser.newPage({ viewport: { width: 390, height: 844 } });
        watch(page);
        await page.goto(VENDOR_PDP, { waitUntil: 'load' });
        await dismissPromoPopup(page);
        ok('phone: renders', !(await hasServerError(page)));
        // The sticky bar only appears after scrolling past the fold.
        await page.mouse.wheel(0, 1600);
        await page.waitForTimeout(1200);
        const priceVisible = await page.locator('.product-details-sticky .product-bottom-section-price').first().evaluate(el => {
            const r = el.getBoundingClientRect();
            return r.width > 0 && r.height > 0 && getComputedStyle(el).display !== 'none';
        }).catch(() => false);
        ok('phone: computed price visible in sticky', priceVisible);
        const priceText = await page.locator('.product-details-sticky .product-bottom-section-price').first().evaluate(el => el.textContent.trim()).catch(() => '');
        ok('phone: price has a value', priceText.length > 0);
        console.log('  (info) phone sticky price:', JSON.stringify(priceText));
        await page.close();
    }
    {
        // Tablet: the fs-24 computed price block now shows from sm up.
        const page = await browser.newPage({ viewport: { width: 820, height: 1180 } });
        watch(page);
        await page.goto(VENDOR_PDP, { waitUntil: 'load' });
        await dismissPromoPopup(page);
        ok('tablet: computed price block visible', await page.locator('.product-details-sticky .fs-24.product-bottom-section-price').evaluate(el => {
            const r = el.getBoundingClientRect();
            return r.width > 0 && getComputedStyle(el).display !== 'none';
        }).catch(() => false));
        await page.close();
    }
}

await browser.close();
console.log(failures.length ? `FAILURES: ${failures.length}` : 'ALL CLEAN');
process.exit(failures.length ? 1 : 0);
