/**
 * Vendor transaction report (order + expense) tables and the withdraw table
 * on the Kohl surface. To have a real row, the suite first walks the newest
 * QA seller order through the real status flow (confirmed → packaging →
 * out for delivery → delivered) on the order-details page — delivering a COD
 * order is what mints an order transaction. Charts and filter cards on the
 * report pages are untouched and must keep working.
 */
import { chromium } from '@playwright/test';
import { BASE, ADMIN, watch, hasServerError } from './_env.mjs';

const VENDOR = { email: 'mahmoudeosama2@gmail.com', password: 'QaVendor!2026' };
const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
const page = await browser.newPage({ viewport: { width: 1440, height: 1100 } });
const problems = watch(page);
const failures = [];
const ok = (label, cond) => {
    console.log((cond ? 'PASS ' : 'FAIL ') + label);
    if (!cond) failures.push(label);
};

await page.goto(BASE + '/vendor/auth/login', { waitUntil: 'domcontentloaded' });
await page.fill('input[name="email"]', VENDOR.email);
await page.fill('input[name="password"]', VENDOR.password);
await page.click('button[type="submit"]');
await page.waitForTimeout(2000);

// ---- Deliver the newest pending order so a transaction exists. -----------
// Inhouse shipping splits the flow: the vendor can take an order to
// packaging, then delivery belongs to the admin — both legs use the real
// status select + Swal confirm on the respective details pages.
const setStatus = async (detailsUrl, status) => {
    await page.selectOption('#order_status', status);
    await page.locator('.swal2-confirm').click({ timeout: 10000 }).catch(() => {});
    // The success handler reloads the page itself; let that settle before
    // navigating again or goto races the reload into ERR_ABORTED.
    await page.waitForTimeout(4000);
    await page.waitForLoadState('load').catch(() => {});
    await page.goto(detailsUrl, { waitUntil: 'load' }).catch(async () => {
        await page.waitForTimeout(2500);
        await page.goto(detailsUrl, { waitUntil: 'load' }).catch(() => {});
    });
};

let href = null;
for (const list of ['pending', 'confirmed', 'processing']) {
    await page.goto(BASE + '/vendor/orders/list/' + list, { waitUntil: 'load' });
    if ((await page.locator('.k-view .k-table tbody tr').count()) > 0) {
        href = await page.locator('.k-view .k-table tbody tr a[href*="/vendor/orders/details/"]').first().getAttribute('href');
        console.log('  (info) picking order from the', list, 'list');
        break;
    }
}
if (href) {
    const orderId = href.split('/').pop();
    await page.goto(href, { waitUntil: 'load' });
    ok('order details renders', !(await hasServerError(page)));
    for (const status of ['confirmed', 'processing']) await setStatus(href, status);

    const adminHref = BASE + '/admin/orders/details/' + orderId;
    await page.goto(BASE + '/login/employee', { waitUntil: 'domcontentloaded' });
    await page.fill('input[name="email"]', ADMIN.email);
    await page.fill('input[name="password"]', ADMIN.password);
    await page.click('button[type="submit"]');
    await page.waitForTimeout(2000);
    await page.goto(adminHref, { waitUntil: 'load' });
    ok('admin order details renders', !(await hasServerError(page)));
    await setStatus(adminHref, 'delivered');
    const statusNow = await page.locator('#order_status').inputValue().catch(() => '?');
    console.log('  (info) order', orderId, 'status now:', statusNow);
    ok('order reached delivered', statusNow === 'delivered');
} else {
    console.log('  (info) no pending order to deliver — relying on prior runs');
}

// ---- Order transactions page. --------------------------------------------
await page.goto(BASE + '/vendor/transaction/order-list', { waitUntil: 'load' });
ok('transactions: renders', !(await hasServerError(page)));
ok('transactions: data-view present', (await page.locator('.k-view').count()) === 1);
ok('transactions: filter card + charts untouched', (await page.locator('#form-data').count()) === 1
    && (await page.locator('#dognut-pie').count()) === 1);
const txRows = await page.locator('.k-view .k-table tbody tr').count();
ok('transactions: a delivered order minted a row', txRows >= 1);
if (txRows >= 1) {
    const row = page.locator('.k-view .k-table tbody tr').first();
    ok('transactions: status badge present', (await row.locator('.k-badge').count()) === 1);
    ok('transactions: order-wise PDF action', (await row.locator('a[href*="pdf-order-wise-transaction"]').count()) === 1);
}
ok('transactions: PDF + excel actions', (await page.locator('.k-view a[href*="order-transaction-summary-pdf"]').count()) === 1
    && (await page.locator('.k-view a[href*="order-transaction-export-excel"]').count()) === 1);

// ---- Expense transactions page. ------------------------------------------
await page.goto(BASE + '/vendor/transaction/expense-list', { waitUntil: 'load' });
ok('expenses: renders', !(await hasServerError(page)));
ok('expenses: data-view present', (await page.locator('.k-view').count()) === 1);
const expRows = await page.locator('.k-view .k-table tbody tr').count();
ok('expenses: table or honest empty state', expRows >= 1
    || (await page.locator('.k-view .k-empty').count()) === 1);

// ---- Withdraw table. -----------------------------------------------------
await page.goto(BASE + '/vendor/business-settings/withdraw/index', { waitUntil: 'load' });
ok('withdraw: renders', !(await hasServerError(page)));
ok('withdraw: k-table present', (await page.locator('#status-wise-view .k-table').count()) === 1);
const wdRows = await page.locator('#status-wise-view .k-table tbody tr').count();
ok('withdraw: rows or honest empty state', wdRows >= 1
    || (await page.locator('#status-wise-view .k-empty').count()) === 1);
ok('withdraw: request offcanvas form intact', (await page.locator('form[action*="withdraw-request"]').count()) >= 1);

const known = problems.filter(p => !p.includes('Element not found'));
ok('no console/JS/HTTP problems', known.length === 0);
if (known.length) console.log('  problems:', known.slice(0, 8));
await browser.close();
console.log(failures.length ? `FAILURES: ${failures.length}` : 'ALL CLEAN');
process.exit(failures.length ? 1 : 0);
