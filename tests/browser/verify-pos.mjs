/**
 * POS batch: both order-details pages (status badges + items table on the
 * Kohl system), both hold-orders tables (k-table shell), and the empty-state
 * asset() fix. Exercised the real way: an actual POS sale per panel — add to
 * cart from quick-view, hold, resume from the rebuilt hold-orders table,
 * place the order through the Swal confirm, then read the freshly minted
 * order's details page.
 */
import { chromium } from '@playwright/test';
import { BASE, watch, loginAdmin, hasServerError } from './_env.mjs';

const VENDOR = { email: 'mahmoudeosama2@gmail.com', password: 'QaVendor!2026' };
const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
const page = await browser.newPage({ viewport: { width: 1440, height: 1100 } });
const problems = watch(page);
const failures = [];
const ok = (label, cond) => {
    console.log((cond ? 'PASS ' : 'FAIL ') + label);
    if (!cond) failures.push(label);
};

/** ajax-reloaded pages race goto — settle, then retry once on ERR_ABORTED. */
async function go(url) {
    await page.goto(url, { waitUntil: 'load' }).catch(async () => {
        await page.waitForTimeout(2500);
        await page.goto(url, { waitUntil: 'load' });
    });
}

async function hideOpenModals() {
    // close the quick-view the way a user does; forcing jQuery .modal('hide')
    // races Bootstrap's transition teardown and throws a 'backdrop' TypeError
    const close = page.locator('.modal.show .close-quick-view-modal, .modal.show [data-bs-dismiss="modal"], .modal.show [data-dismiss="modal"]');
    if (await close.count() > 0) await close.first().click({ force: true }).catch(() => {});
    await page.evaluate(() => {
        // the low-stock warning toast overlays the billing column and intercepts clicks
        document.querySelectorAll('.alert--container').forEach(a => {
            a.classList.remove('active');
            a.style.display = 'none';
        });
    }).catch(() => {});
    await page.waitForTimeout(800);
}

/** Click product tiles until one yields an enabled add-to-cart in quick-view. */
async function addAnyProductToCart(prefix) {
    const tiles = page.locator('.action-select-product');
    const total = Math.min(await tiles.count(), 8);
    for (let i = 0; i < total; i++) {
        await tiles.nth(i).click();
        const shown = await page.waitForSelector('#quick-view.show, #quick-view.in', { timeout: 6000 }).catch(() => null);
        if (!shown) continue;
        await page.waitForTimeout(1200);
        // choose the first option of every variation group, then let the price ajax settle
        await page.evaluate(() => {
            document.querySelectorAll('#add-to-cart-form .variant-change').forEach(group => {
                if (!group.querySelector('input:checked')) group.querySelector('input')?.click();
            });
        });
        await page.waitForTimeout(1500);
        const btn = page.locator('#quick-view .action-add-to-cart:not([disabled])');
        if (await btn.count() > 0) {
            await btn.first().click();
            await page.waitForTimeout(2500);
            await hideOpenModals();
            const armed = await page.locator('#submit_order.action-form-submit:not([disabled])').count();
            if (armed > 0) return true;
        }
        await hideOpenModals();
    }
    console.log(`  ${prefix}: no addable product among first ${total} tiles`);
    return false;
}

async function runPanel(prefix, base) {
    await go(BASE + base);
    ok(`${prefix} pos: renders`, !(await hasServerError(page)));
    ok(`${prefix} pos: product tiles present`, (await page.locator('.action-select-product').count()) >= 1);
    await hideOpenModals();

    const added = await addAnyProductToCart(prefix);
    ok(`${prefix} pos: product added to cart`, added);
    if (!added) return null;

    // -- hold, then inspect the rebuilt hold-orders table, then resume ------
    await hideOpenModals();
    await page.locator('.pos-hold-btn:not([disabled])').first().click({ timeout: 10000 });
    await page.waitForSelector('#holdOrderModal.show, #holdOrderModal.in', { timeout: 6000 }).catch(() => {});
    await page.locator('#holdOrderModal button[type="submit"]').click().catch(() => {});
    await page.waitForLoadState('load').catch(() => {});
    await page.waitForTimeout(2500);
    await hideOpenModals();

    await page.locator('.action-view-all-hold-orders').click();
    await page.waitForSelector('#hold-orders-modal.show, #hold-orders-modal.in', { timeout: 8000 }).catch(() => {});
    await page.waitForTimeout(1200);
    ok(`${prefix} pos: hold-orders on k-table with a row`,
        (await page.locator('#hold-orders-modal .k-table tbody tr').count()) >= 1);
    const resume = page.locator('#hold-orders-modal .action-cart-change').first();
    ok(`${prefix} pos: resume control present`, (await resume.count()) === 1);
    await resume.click().catch(() => {});
    await page.waitForLoadState('load').catch(() => {});
    await page.waitForTimeout(2500);
    await hideOpenModals();
    ok(`${prefix} pos: cart restored after resume`,
        (await page.locator('#submit_order.action-form-submit:not([disabled])').count()) >= 1);

    // -- place the order through the Swal confirm ---------------------------
    await hideOpenModals();
    await page.locator('#submit_order.action-form-submit:not([disabled])').first().click();
    await page.waitForSelector('.swal2-confirm', { timeout: 6000 });
    await page.locator('.swal2-confirm').click();
    await page.waitForTimeout(4000);
    await page.waitForLoadState('load').catch(() => {});
    await page.waitForTimeout(2500);

    const invoiceText = (await page.locator('#print-invoice').evaluate(el => el.textContent).catch(() => '')) || '';
    const idMatch = invoiceText.replace(/\s+/g, ' ').match(/ID\s*:\s*(\d+)/i) || invoiceText.match(/(\d{4,})/);
    ok(`${prefix} pos: invoice offcanvas holds new order id`, idMatch !== null);
    return idMatch ? idMatch[1] : null;
}

async function checkDetails(prefix, url) {
    await go(url);
    ok(`${prefix} details: renders`, !(await hasServerError(page)));
    ok(`${prefix} details: items on k-table with a row`,
        (await page.locator('.k-table-wrap .k-table tbody tr').count()) >= 1);
    ok(`${prefix} details: status is a k-badge`,
        (await page.locator('.order-status .k-badge').count()) === 1);
    ok(`${prefix} details: paid amount rows present (POS)`,
        ((await page.locator('.table-borderless.table-sm').evaluate(el => el.textContent).catch(() => '')) || '').length > 0);
    ok(`${prefix} details: invoice link intact`, (await page.locator('a[href*="generate-invoice"]').count()) >= 1);
}

// ================= admin =================
await loginAdmin(page);
const adminOrderId = await runPanel('admin', '/admin/pos');
if (adminOrderId) await checkDetails('admin', `${BASE}/admin/orders/details/${adminOrderId}`);
else failures.push('admin: no order id extracted');

// ================= vendor =================
await page.goto(BASE + '/vendor/auth/login', { waitUntil: 'domcontentloaded' });
await page.fill('input[name="email"]', VENDOR.email);
await page.fill('input[name="password"]', VENDOR.password);
await page.click('button[type="submit"]');
await page.waitForTimeout(2500);

const vendorOrderId = await runPanel('vendor', '/vendor/pos');
if (vendorOrderId) await checkDetails('vendor', `${BASE}/vendor/orders/details/${vendorOrderId}`);
else failures.push('vendor: no order id extracted');

const known = problems.filter(p => !p.includes('Element not found'));
ok('no console/JS/HTTP problems', known.length === 0);
if (known.length) console.log('  problems:', known.slice(0, 10));
await browser.close();
console.log(failures.length ? `FAILURES: ${failures.length}` : 'ALL CLEAN');
process.exit(failures.length ? 1 : 0);
