/**
 * Surgical pass on the admin detail pages: order details (items table +
 * status badges; every control untouched) and refund details (log table).
 * The suite also drives a real refund approval through the modal so the log
 * table renders an actual row with its tone badge.
 */
import { chromium } from '@playwright/test';
import { BASE, ADMIN, watch, hasServerError } from './_env.mjs';

const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
const page = await browser.newPage({ viewport: { width: 1440, height: 1100 } });
const problems = watch(page);
const failures = [];
const ok = (label, cond) => {
    console.log((cond ? 'PASS ' : 'FAIL ') + label);
    if (!cond) failures.push(label);
};

await page.goto(BASE + '/login/employee', { waitUntil: 'domcontentloaded' });
await page.fill('input[name="email"]', ADMIN.email);
await page.fill('input[name="password"]', ADMIN.password);
await page.click('button[type="submit"]');
await page.waitForTimeout(2000);

// ---- Admin order details -------------------------------------------------
await page.goto(BASE + '/admin/orders/details/100007', { waitUntil: 'load' });
ok('order details: renders', !(await hasServerError(page)));
ok('order details: items on k-table', (await page.locator('.k-table tbody tr').count()) >= 1);
ok('order details: delivered badge is k-badge success',
    ((await page.locator('.order-status .k-badge').innerText().catch(() => ''))).match(/delivered/i) !== null);
ok('order details: status select + invoice intact', (await page.locator('#order_status').count()) === 1
    && (await page.locator('a[href*="generate-invoice"]').count()) >= 1);

// ---- Refund details + real approval --------------------------------------
await page.goto(BASE + '/admin/refund-section/refund/details/1', { waitUntil: 'load' });
ok('refund details: renders', !(await hasServerError(page)));
ok('refund details: log k-table or empty', (await page.locator('.k-table').count()) >= 1);

const approveBtn = page.locator('[data-bs-target="#approveModal-1"], [data-bs-target^="#approveModal"]').first();
if (await approveBtn.count()) {
    await approveBtn.click();
    await page.waitForTimeout(800);
    const modalForm = page.locator('.modal.show form[action*="refund-status-update"]');
    ok('refund details: approve modal opens', await modalForm.count() >= 1);
    await modalForm.locator('textarea[name="approved_note"]').fill('QA approval through the rebuilt modal').catch(() => {});
    await page.locator('.modal.show .form-submit').first().click();
    await page.locator('.swal2-confirm').click({ timeout: 10000 }).catch(() => {});
    await page.waitForTimeout(3000);
    await page.waitForLoadState('load').catch(() => {});
    await page.waitForTimeout(1500);
    await page.goto(BASE + '/admin/refund-section/refund/details/1', { waitUntil: 'load' });
    ok('refund details: log row appears after approval', (await page.locator('.k-table tbody tr').count()) >= 1);
    ok('refund details: approved tone badge in log', (await page.locator('.k-table .k-badge').count()) >= 1);
} else {
    console.log('  (info) refund not pending anymore — asserting log rows only');
    ok('refund details: log rows render', (await page.locator('.k-table tbody tr').count()) >= 1);
}

const known = problems.filter(p => !p.includes('Element not found'));
ok('no console/JS/HTTP problems', known.length === 0);
if (known.length) console.log('  problems:', known.slice(0, 8));
await browser.close();
console.log(failures.length ? `FAILURES: ${failures.length}` : 'ALL CLEAN');
process.exit(failures.length ? 1 : 0);
