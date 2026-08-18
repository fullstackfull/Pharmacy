/**
 * Admin transaction reports and vendor-withdraw list on the data-view.
 * Charts and stat cards stay; the 24-column order-transaction table, the
 * expense table, and the withdraw table move — with the status filter select
 * (withdraw-status-filter JS hook) in the toolbar. The delivered QA order
 * gives the order-transaction table a real row.
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

// ---- Order transactions --------------------------------------------------
await page.goto(BASE + '/admin/transaction/order-transaction-list', { waitUntil: 'load' });
ok('order-txn: renders', !(await hasServerError(page)));
ok('order-txn: data-view + donut chart', (await page.locator('.k-view').count()) === 1
    && (await page.locator('#dognut-pie').count()) === 1);
ok('order-txn: delivered order row present', (await page.locator('.k-view .k-table tbody tr').count()) >= 1);
ok('order-txn: badge + row PDF action', (await page.locator('.k-view .k-badge').count()) >= 1
    && (await page.locator('.k-view a[href*="pdf-order-wise-transaction"]').count()) >= 1);
ok('order-txn: PDF + excel actions', (await page.locator('.k-view a[href*="order-transaction-summary-pdf"]').count()) === 1
    && (await page.locator('.k-view a[href*="order-transaction-export-excel"]').count()) === 1);

// ---- Expense transactions ------------------------------------------------
await page.goto(BASE + '/admin/transaction/expense-transaction-list', { waitUntil: 'load' });
ok('expense: renders', !(await hasServerError(page)));
ok('expense: data-view present', (await page.locator('.k-view').count()) === 1);
ok('expense: rows or honest empty', (await page.locator('.k-view .k-table tbody tr').count()) >= 1
    || (await page.locator('.k-view .k-empty').count()) === 1);

// ---- Vendor withdraw requests --------------------------------------------
await page.goto(BASE + '/admin/vendors/withdraw-list', { waitUntil: 'load' });
ok('withdraw: renders', !(await hasServerError(page)));
ok('withdraw: data-view present', (await page.locator('.k-view').count()) === 1);
ok('withdraw: status filter select in toolbar', (await page.locator('.k-view select.withdraw-status-filter').count()) === 1);
ok('withdraw: rows or honest empty', (await page.locator('.k-view .k-table tbody tr').count()) >= 1
    || (await page.locator('.k-view .k-empty').count()) === 1);

const known = problems.filter(p => !p.includes('Element not found'));
ok('no console/JS/HTTP problems', known.length === 0);
if (known.length) console.log('  problems:', known.slice(0, 8));
await browser.close();
console.log(failures.length ? `FAILURES: ${failures.length}` : 'ALL CLEAN');
process.exit(failures.length ? 1 : 0);
