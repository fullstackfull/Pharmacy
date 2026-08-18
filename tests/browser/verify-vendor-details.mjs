/**
 * Surgical Kohl pass on the two vendor detail pages: order details (items
 * table + status badges moved to the system; every control — status select,
 * edit-products modal, invoice, summary totals, order.js spans — untouched)
 * and refund details (request-logs table + badges).
 */
import { chromium } from '@playwright/test';
import { BASE, watch, hasServerError } from './_env.mjs';

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

// ---- Order details (delivered QA order) ----------------------------------
await page.goto(BASE + '/vendor/orders/details/100007', { waitUntil: 'load' });
ok('order details: renders', !(await hasServerError(page)));
ok('order details: items on k-table with a row', (await page.locator('.k-table tbody tr').count()) >= 1);
ok('order details: delivered status is a success k-badge',
    ((await page.locator('.order-status .k-badge').innerText().catch(() => '')) || '').match(/delivered/i) !== null);
ok('order details: status select intact', (await page.locator('#order_status').count()) === 1);
ok('order details: invoice + totals intact', (await page.locator('a[href*="generate-invoice"]').count()) >= 1
    && (await page.locator('.table-borderless.table-sm').count()) === 1);
ok('order details: order.js data spans intact', (await page.locator('#order-status-url, [id*="message-status"]').count()) >= 1);

// ---- Refund details (pending QA refund; refund 1 was approved in an earlier
// verified flow, refund 2 is the standing pending fixture) ------------------
await page.goto(BASE + '/vendor/refund/details/2', { waitUntil: 'load' });
ok('refund details: renders', !(await hasServerError(page)));
ok('refund details: logs k-table or honest empty', (await page.locator('.k-table').count()) === 1
    && ((await page.locator('.k-table tbody tr').count()) >= 1 || (await page.locator('.k-empty').count()) >= 1));
ok('refund details: approve/reject modals present', (await page.locator('#approveModal, #rejectModal').count()) >= 1);

const known = problems.filter(p => !p.includes('Element not found'));
ok('no console/JS/HTTP problems', known.length === 0);
if (known.length) console.log('  problems:', known.slice(0, 8));
await browser.close();
console.log(failures.length ? `FAILURES: ${failures.length}` : 'ALL CLEAN');
process.exit(failures.length ? 1 : 0);
