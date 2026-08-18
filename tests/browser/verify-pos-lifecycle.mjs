/**
 * POS orders on the full order lifecycle:
 * - checkout gains a fulfillment choice: instant sale (delivered/paid, as
 *   before) vs home delivery (order starts pending; cash becomes COD/unpaid;
 *   requires a real customer; captures their latest shipping address)
 * - the POS order-details pages gain the standard machinery: status select
 *   with Swal confirm, payment switch, delivery-man assignment
 * Exercised end to end: an admin delivery sale walked pending → confirmed →
 * (assign rider) → out_for_delivery → delivered; a vendor delivery sale
 * walked to packaging then delivered by the admin; the walk-in guard; and an
 * instant-sale regression.
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

async function go(url) {
    await page.goto(url, { waitUntil: 'load' }).catch(async () => {
        await page.waitForTimeout(2500);
        await page.goto(url, { waitUntil: 'load' });
    });
}

async function tidy() {
    const close = page.locator('.modal.show .close-quick-view-modal, .modal.show [data-bs-dismiss="modal"], .modal.show [data-dismiss="modal"]');
    if (await close.count() > 0) await close.first().click({ force: true }).catch(() => {});
    await page.evaluate(() => {
        document.querySelectorAll('.alert--container').forEach(a => { a.classList.remove('active'); a.style.display = 'none'; });
    }).catch(() => {});
    await page.waitForTimeout(700);
}

async function selectCustomer(id) {
    await page.locator('.custom_dropdown_toggle').first().click();
    await page.waitForTimeout(600);
    await page.locator(`.custom_dropdown_item.action-customer-change[data-id="${id}"]`).first().click();
    await page.waitForTimeout(3000);
    await tidy();
}

async function addAnyProductToCart(prefix) {
    const tiles = page.locator('.action-select-product');
    const total = Math.min(await tiles.count(), 8);
    for (let i = 0; i < total; i++) {
        await tiles.nth(i).click();
        const shown = await page.waitForSelector('#quick-view.show, #quick-view.in', { timeout: 6000 }).catch(() => null);
        if (!shown) continue;
        await page.waitForTimeout(1200);
        await page.evaluate(() => {
            document.querySelectorAll('#add-to-cart-form .variant-change').forEach(g => {
                if (!g.querySelector('input:checked')) g.querySelector('input')?.click();
            });
        });
        await page.waitForTimeout(1400);
        const btn = page.locator('#quick-view .action-add-to-cart:not([disabled])');
        if (await btn.count() > 0) {
            await btn.first().click();
            await page.waitForTimeout(2500);
            await tidy();
            if (await page.locator('#submit_order.action-form-submit:not([disabled])').count() > 0) return true;
        }
        await tidy();
    }
    console.log(`  ${prefix}: no addable product`);
    return false;
}

async function placeOrder() {
    await tidy();
    await page.locator('#submit_order.action-form-submit:not([disabled])').first().click({ timeout: 10000 });
    await page.waitForSelector('.swal2-confirm', { timeout: 6000 });
    await page.locator('.swal2-confirm').click();
    await page.waitForTimeout(4000);
    await page.waitForLoadState('load').catch(() => {});
    await page.waitForTimeout(2500);
    const invoiceText = (await page.locator('#print-invoice').evaluate(el => el.textContent).catch(() => '')) || '';
    const idMatch = invoiceText.replace(/\s+/g, ' ').match(/ID\s*:\s*(\d+)/i) || invoiceText.match(/(\d{4,})/);
    return idMatch ? idMatch[1] : null;
}

async function changeStatus(value) {
    await page.selectOption('#order_status', value);
    await page.waitForSelector('.swal2-confirm', { timeout: 6000 });
    await page.locator('.swal2-confirm').click();
    await page.waitForTimeout(3500);
    await page.waitForLoadState('load').catch(() => {});
    await page.waitForTimeout(1500);
}

const statusBadge = async () =>
    ((await page.locator('.order-status .k-badge').evaluate(el => el.textContent).catch(() => '')) || '').trim();

// ================= admin: delivery sale through the full lifecycle =========
await loginAdmin(page);
await go(BASE + '/admin/pos');
await tidy();
await selectCustomer(21);
ok('admin pos: product added for QA Customer', await addAnyProductToCart('admin'));

await page.locator('label[for="fulfillment-delivery"]').click();
await page.waitForTimeout(800);
ok('admin pos: delivery note appears', (await page.locator('.fulfillment-delivery-note:not(.d-none)').count()) === 1);
ok('admin pos: cash tender hidden for delivery', (await page.locator('.cash-change-amount.d-none').count()) === 1);

const adminOrderId = await placeOrder();
ok('admin pos: delivery order placed', adminOrderId !== null);

await go(`${BASE}/admin/orders/details/${adminOrderId}`);
ok('admin details: renders', !(await hasServerError(page)));
ok('admin details: starts pending', /pending|قيد/i.test(await statusBadge()));
ok('admin details: status select shows pending', (await page.locator('#order_status').inputValue()) === 'pending');
ok('admin details: payment starts unpaid', (await page.locator('.payment-status:checked').count()) === 0);
ok('admin details: COD payment method recorded', ((await page.locator('.payment-method').evaluate(el => el.textContent).catch(() => '')) || '').match(/cash on delivery/i) !== null);
ok('admin details: shipping address card present', (await page.locator('.card:has-text("Salmiya")').count()) >= 1);

await changeStatus('confirmed');
ok('admin details: walked to confirmed', /confirmed/i.test(await statusBadge()));

// assign the admin rider through the real select
await page.evaluate(() => { window.jQuery('#addDeliveryMan').val('2').trigger('change'); });
await page.waitForTimeout(3500);
await page.waitForLoadState('load').catch(() => {});
await page.waitForTimeout(1500);
ok('admin details: rider assigned', (await page.locator('.choose_delivery_man:has-text("AdminRider")').count()) >= 1);

await changeStatus('out_for_delivery');
ok('admin details: out for delivery', /out[_ ]for[_ ]delivery/i.test(await statusBadge()));

await changeStatus('delivered');
ok('admin details: delivered', /delivered/i.test(await statusBadge()));
ok('admin details: payment settles to paid on delivery', (await page.locator('.payment-status:checked').count()) === 1);

// ================= admin: walk-in delivery guard ===========================
await go(BASE + '/admin/pos');
await tidy();
await selectCustomer(0);
ok('guard: product added for walk-in', await addAnyProductToCart('guard'));
await page.locator('label[for="fulfillment-delivery"]').click();
await page.waitForTimeout(600);
await tidy();
await page.locator('#submit_order.action-form-submit:not([disabled])').first().click({ timeout: 10000 });
await page.waitForSelector('.swal2-confirm', { timeout: 6000 });
await page.locator('.swal2-confirm').click();
await page.waitForTimeout(4000);
ok('guard: walk-in delivery is refused with add-customer prompt',
    (await page.locator('#offcanvasAddNewCustomer.show').count()) === 1);
await page.evaluate(() => {
    const offcanvasElement = document.getElementById('offcanvasAddNewCustomer');
    if (offcanvasElement && window.bootstrap) window.bootstrap.Offcanvas.getOrCreateInstance(offcanvasElement).hide();
});
await page.waitForTimeout(1000);

// ================= admin: instant-sale regression ==========================
await page.locator('label[for="fulfillment-instant"]').click();
await page.waitForTimeout(600);
const instantOrderId = await placeOrder();
ok('instant: sale placed for walk-in as before', instantOrderId !== null);
await go(`${BASE}/admin/orders/details/${instantOrderId}`);
ok('instant: delivered immediately', /delivered/i.test(await statusBadge()));
ok('instant: paid immediately', (await page.locator('.payment-status:checked').count()) === 1);

// ================= vendor: delivery sale, then admin delivers ==============
await page.goto(BASE + '/vendor/auth/login', { waitUntil: 'domcontentloaded' });
await page.fill('input[name="email"]', VENDOR.email);
await page.fill('input[name="password"]', VENDOR.password);
await page.click('button[type="submit"]');
await page.waitForTimeout(2500);

await go(BASE + '/vendor/pos');
await tidy();
await selectCustomer(21);
ok('vendor pos: product added', await addAnyProductToCart('vendor'));
await page.locator('label[for="fulfillment-delivery"]').click();
await page.waitForTimeout(800);
const vendorOrderId = await placeOrder();
ok('vendor pos: delivery order placed', vendorOrderId !== null);

await go(`${BASE}/vendor/orders/details/${vendorOrderId}`);
ok('vendor details: renders', !(await hasServerError(page)));
ok('vendor details: starts pending', /pending|قيد/i.test(await statusBadge()));
ok('vendor details: inhouse notice shown', (await page.locator('.alert:has-text("inhouse shipping")').count()) >= 1);
ok('vendor details: delivery statuses withheld under inhouse shipping',
    (await page.locator('#order_status option[value="out_for_delivery"]').count()) === 0);

await changeStatus('confirmed');
ok('vendor details: confirmed', /confirmed/i.test(await statusBadge()));
await changeStatus('processing');
ok('vendor details: packaging', /packaging|processing/i.test(await statusBadge()));

// admin takes over the vendor order for the delivery leg — the admin guard
// from earlier in the run is still authenticated in this browser context
await go(`${BASE}/admin/orders/details/${vendorOrderId}`);
await page.evaluate(() => { window.jQuery('#addDeliveryMan').val('2').trigger('change'); });
await page.waitForTimeout(3500);
await page.waitForLoadState('load').catch(() => {});
await page.waitForTimeout(1500);
ok('handover: admin assigned rider on vendor POS order',
    (await page.locator('.choose_delivery_man:has-text("AdminRider")').count()) >= 1);
await changeStatus('out_for_delivery');
await changeStatus('delivered');
ok('handover: vendor POS order delivered by admin', /delivered/i.test(await statusBadge()));

const known = problems.filter(p => !p.includes('Element not found'));
ok('no console/JS/HTTP problems', known.length === 0);
if (known.length) console.log('  problems:', known.slice(0, 10));
await browser.close();
console.log(failures.length ? `FAILURES: ${failures.length}` : 'ALL CLEAN');
process.exit(failures.length ? 1 : 0);
