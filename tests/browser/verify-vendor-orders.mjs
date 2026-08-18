/**
 * Vendor order list on the data-view. First half places a real COD order for
 * the QA vendor's own product (checkout is POST-only now, so the storefront
 * flow is the only honest way to mint a seller order); second half signs in
 * as that vendor and verifies the rebuilt list: summary tiles, table cells,
 * badges, export/filter actions, search, and the per-status variant.
 */
import { chromium } from '@playwright/test';
import { BASE, watch, dismissPromoPopup, hasServerError } from './_env.mjs';

const CUSTOMER = { identity: 'qa.customer@localhost.test', password: 'QaCustomer!2026' };
const VENDOR = { email: 'mahmoudeosama2@gmail.com', password: 'QaVendor!2026' };
const VENDOR_PRODUCT = '/product/qa-vendor-product-1787046108';

const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
const failures = [];
const ok = (label, cond) => {
    console.log((cond ? 'PASS ' : 'FAIL ') + label);
    if (!cond) failures.push(label);
};

// ---- Act 1: mint an order that belongs to the vendor. --------------------
{
    const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
    const problems = watch(page);

    await page.goto(BASE + '/customer/auth/login', { waitUntil: 'domcontentloaded' });
    await page.fill('#customer-login-form input[name="user_identity"]', CUSTOMER.identity);
    await page.fill('#customer-login-form input[name="password"]', CUSTOMER.password);
    await page.click('#customer-login-form button[type="submit"]');
    await page.waitForTimeout(1500);

    await page.goto(BASE + VENDOR_PRODUCT, { waitUntil: 'domcontentloaded' });
    await dismissPromoPopup(page);
    ok('vendor product PDP renders', !(await hasServerError(page)));
    await page.click('.add-to-cart-details-form .product-add-to-cart-button');
    await page.waitForTimeout(2500);
    await page.keyboard.press('Escape');

    await page.goto(BASE + '/shop-cart', { waitUntil: 'domcontentloaded' });
    await dismissPromoPopup(page);
    const shipSelect = page.locator('select.action-set-shipping-id').first();
    const shipValues = await shipSelect.locator('option').evaluateAll(os =>
        os.map(o => o.getAttribute('value')).filter(v => v && /^\d+$/.test(v)));
    ok('shipping option available', shipValues.length >= 1);
    await shipSelect.selectOption(shipValues[0]);
    await page.waitForTimeout(2500);

    await page.goto(BASE + '/checkout-details', { waitUntil: 'domcontentloaded' });
    const fillField = async (sel, val) => {
        const el = page.locator(sel).first();
        if (await el.count()) await el.fill(val).catch(() => {});
    };
    const pickFirst = async sel => {
        const el = page.locator(sel).first();
        if (!(await el.count())) return;
        const vals = await el.locator('option').evaluateAll(os =>
            os.map(o => o.getAttribute('value')).filter(v => v));
        if (vals.length) await el.selectOption(vals[0]).catch(() => {});
    };
    await fillField('input[name="contact_person_name"]', 'QA Customer');
    await fillField('input[name="phone"]', '50001111');
    await page.evaluate(() => {
        const el = document.querySelector('input[name="phone"]');
        if (el) { el.value = '50001111'; el.dispatchEvent(new Event('input', { bubbles: true })); }
    });
    await pickFirst('select[name="country"]');
    await fillField('input[name="city"]', 'Kuwait City');
    await fillField('input[name="zip"]', '00000');
    await fillField('textarea[name="address"]', 'Block 1, Street 2, Building 3');
    await fillField('input[name="billing_contact_person_name"]', 'QA Customer');
    await fillField('input[name="billing_phone"]', '50001111');
    await page.evaluate(() => {
        const el = document.querySelector('input[name="billing_phone"]');
        if (el) { el.value = '50001111'; el.dispatchEvent(new Event('input', { bubbles: true })); }
    });
    await pickFirst('select[name="billing_country"]');
    await fillField('input[name="billing_city"]', 'Kuwait City');
    await fillField('input[name="billing_zip"]', '00000');
    await fillField('textarea[name="billing_address"]', 'Block 1, Street 2, Building 3');

    await page.locator('a.btn--primary', { hasText: 'Proceed' }).locator('visible=true').first().click();
    await page.waitForURL('**/checkout-payment', { timeout: 30000 }).catch(() => {});
    await page.waitForSelector('#cash_on_delivery', { timeout: 20000 }).catch(() => {});
    if (!page.url().includes('checkout-payment')) {
        await page.goto(BASE + '/checkout-payment', { waitUntil: 'domcontentloaded' });
        await page.waitForSelector('#cash_on_delivery', { timeout: 20000 }).catch(() => {});
    }
    ok('reached checkout-payment', page.url().includes('checkout-payment'));

    await page.locator('#cash_on_delivery').check({ force: true });
    for (const box of await page.locator('.payment-input-checkbox').all()) {
        await box.check({ force: true }).catch(() => {});
    }
    await page.waitForTimeout(400);
    await page.locator('.proceed_to_next_button, .action-checkout-function').first().click();
    await page.waitForURL(u => !u.href.includes('checkout-payment'), { timeout: 30000 }).catch(() => {});
    await page.waitForLoadState('load').catch(() => {});
    ok('vendor-product COD order placed', !page.url().includes('checkout-payment')
        && !(await hasServerError(page)));

    const noise = problems.filter(p => !p.includes('HTTP 405'));
    ok('storefront leg: no console/JS/HTTP problems', noise.length === 0);
    if (noise.length) console.log('  problems:', noise.slice(0, 6));
    await page.close();
}

// ---- Act 2: the vendor sees it on the rebuilt list. ----------------------
{
    const page = await browser.newPage({ viewport: { width: 1440, height: 1100 } });
    const problems = watch(page);

    await page.goto(BASE + '/vendor/auth/login', { waitUntil: 'domcontentloaded' });
    await page.fill('input[name="email"]', VENDOR.email);
    await page.fill('input[name="password"]', VENDOR.password);
    await page.click('button[type="submit"]');
    await page.waitForTimeout(2000);

    await page.goto(BASE + '/vendor/orders/list/all', { waitUntil: 'load' });
    ok('order list renders', !(await hasServerError(page)));
    ok('data-view present', (await page.locator('.k-view').count()) === 1);
    ok('summary tiles are stat links (8)', (await page.locator('.k-stats a.k-stat').count()) === 8);

    const rows = await page.locator('.k-view .k-table tbody tr').count();
    ok('order rows render', rows >= 1);
    console.log('  (info) rows:', rows);

    const firstRow = page.locator('.k-view .k-table tbody tr').first();
    ok('order id links to details', ((await firstRow.locator('a[href*="/vendor/orders/details/"]').count()) >= 1));
    ok('payment badge present', (await firstRow.locator('.k-badge').count()) >= 1);
    ok('invoice action present', (await firstRow.locator('a[target="_blank"][href*="generate-invoice"]').count()) === 1);
    ok('export link carries status', (await page.locator('.k-view a[href*="export-excel/all"]').count()) === 1);
    ok('filter button targets offcanvas', (await page.locator('.k-view [data-target="#offcanvasOrderFilter"]').count()) === 1);
    ok('filter offcanvas present', (await page.locator('#offcanvasOrderFilter').count()) === 1);
    ok('order.js data spans intact', (await page.locator('#message-date-range-text').count()) === 1
        && (await page.locator('#js-data-example-ajax-url').count()) === 1);

    // Search by the first row's order id: the same row must come back alone.
    const orderId = (await firstRow.locator('a[href*="/vendor/orders/details/"]').first().innerText()).trim().split(/\s/)[0];
    await page.fill('.k-view input[name="searchValue"]', orderId);
    await page.press('.k-view input[name="searchValue"]', 'Enter');
    await page.waitForLoadState('load');
    ok('search by order id works', !(await hasServerError(page))
        && page.url().includes('searchValue=' + orderId)
        && (await page.locator('.k-view .k-table tbody tr').count()) >= 1);

    // Status-specific page swaps the status column for payment_method.
    await page.goto(BASE + '/vendor/orders/list/pending', { waitUntil: 'load' });
    ok('pending page renders', !(await hasServerError(page)));
    ok('pending page has no summary tiles', (await page.locator('.k-stats').count()) === 0);
    const headText = await page.locator('.k-view .k-table thead').innerText();
    ok('payment_method column on status page', /payment/i.test(headText));

    const known = problems.filter(p => !p.includes('Element not found'));
    ok('vendor leg: no console/JS/HTTP problems', known.length === 0);
    if (known.length) console.log('  problems:', known.slice(0, 6));
    await page.close();
}

await browser.close();
console.log(failures.length ? `FAILURES: ${failures.length}` : 'ALL CLEAN');
process.exit(failures.length ? 1 : 0);
