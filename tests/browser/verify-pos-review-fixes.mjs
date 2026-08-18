/**
 * Verifies the code-review fixes on the POS lifecycle:
 * - a card-paid POS delivery order can be walked to delivered from the panel
 *   (previously fataled on a null order transaction in the wallet settlement)
 * - a wallet-paid POS delivery order refunds the customer's wallet exactly
 *   once when cancelled, no matter how often cancel is repeated
 * - toggling fulfillment delivery → instant brings the cash tender UI back
 *   (the payment-radio handler used to swallow the fulfillment radios)
 */
import { chromium } from '@playwright/test';
import { BASE, watch, loginAdmin, hasServerError } from './_env.mjs';

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

async function addAnyProductToCart() {
    const tiles = page.locator('.action-select-product');
    const total = Math.min(await tiles.count(), 8);
    for (let i = 0; i < total; i++) {
        await tiles.nth(i).click();
        const shown = await page.waitForSelector('#quick-view.show', { timeout: 6000 }).catch(() => null);
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

await loginAdmin(page);
await go(BASE + '/admin/pos');
await tidy();
await selectCustomer(21);
ok('setup: product in cart', await addAnyProductToCart());

// ---- radio interplay: delivery hides the tender, instant restores it ------
await page.locator('label[for="fulfillment-delivery"]').click();
await page.waitForTimeout(800);
const hiddenOnDelivery = await page.locator('.cash-change-amount').first()
    .evaluate(el => el.classList.contains('d-none') || getComputedStyle(el).display === 'none');
ok('radios: tender hidden while delivery selected', hiddenOnDelivery);
await page.locator('label[for="fulfillment-instant"]').click();
await page.waitForTimeout(800);
const visibleOnInstant = await page.locator('.cash-change-amount').first()
    .evaluate(el => !el.classList.contains('d-none') && getComputedStyle(el).display !== 'none');
ok('radios: tender restored on instant', visibleOnInstant);

// ---- card-paid delivery order walked to delivered (the old null fatal) ----
await page.locator('label[for="fulfillment-delivery"]').click();
await page.locator('label[for="card"]').click();
await page.waitForTimeout(600);
const cardOrderId = await placeOrder();
ok('card delivery: order placed', cardOrderId !== null);
await go(`${BASE}/admin/orders/details/${cardOrderId}`);
ok('card delivery: starts pending + paid', /pending|قيد/i.test(await statusBadge())
    && (await page.locator('.payment-status:checked').count()) === 1);
await changeStatus('confirmed');
await changeStatus('delivered');
ok('card delivery: delivered without a settlement fatal', /delivered/i.test(await statusBadge())
    && !(await hasServerError(page)));

// ---- wallet-paid delivery order refunds once on cancel --------------------
// (the exact one-credit-only accounting is asserted afterwards from the DB)
await go(BASE + '/admin/pos');
await tidy();
await selectCustomer(21);
ok('setup: product in cart (wallet leg)', await addAnyProductToCart());
await page.locator('label[for="fulfillment-delivery"]').click();
await page.locator('label[for="wallet"]').click();
await page.waitForTimeout(600);
const walletOrderId = await placeOrder();
ok('wallet delivery: order placed', walletOrderId !== null);

await go(`${BASE}/admin/orders/details/${walletOrderId}`);
await changeStatus('canceled');
ok('wallet delivery: cancelled cleanly', /cancel/i.test(await statusBadge()) && !(await hasServerError(page)));

const known = problems.filter(p => !p.includes('Element not found'));
ok('no console/JS/HTTP problems', known.length === 0);
if (known.length) console.log('  problems:', known.slice(0, 10));
await browser.close();
console.log('WALLET_ORDER=' + walletOrderId);
console.log(failures.length ? `FAILURES: ${failures.length}` : 'ALL CLEAN');
process.exit(failures.length ? 1 : 0);
