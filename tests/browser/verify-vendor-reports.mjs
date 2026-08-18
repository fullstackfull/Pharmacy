/**
 * Vendor report pages on the data-view: product stock, all products, and the
 * order report — filter cards and ApexCharts stay; the tables move. The dead
 * Chart.js tail in order-report.js is gone, so these pages load with a clean
 * console (the same fix the transaction pages got).
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

// ---- Product stock report ------------------------------------------------
await page.goto(BASE + '/vendor/report/stock-product-report', { waitUntil: 'load' });
ok('stock report: renders', !(await hasServerError(page)));
ok('stock report: data-view + filter card', (await page.locator('.k-view').count()) === 1
    && (await page.locator('#form-data').count()) === 1);
ok('stock report: rows render', (await page.locator('.k-view .k-table tbody tr').count()) >= 1);
ok('stock report: status badges', (await page.locator('.k-view .k-badge').count()) >= 1);
ok('stock report: export link', (await page.locator('.k-view a[href*="product-stock-export"]').count()) === 1);

// ---- All products report -------------------------------------------------
await page.goto(BASE + '/vendor/report/all-product', { waitUntil: 'load' });
ok('all-product: renders', !(await hasServerError(page)));
ok('all-product: data-view + chart present', (await page.locator('.k-view').count()) === 1
    && (await page.locator('#apex-line-chart, .apexcharts-canvas').first().count()) >= 1);
ok('all-product: rows render', (await page.locator('.k-view .k-table tbody tr').count()) >= 1);
ok('all-product: export link', (await page.locator('.k-view a[href*="all-product-excel"]').count()) === 1);

// ---- Order report --------------------------------------------------------
await page.goto(BASE + '/vendor/report/order-report', { waitUntil: 'load' });
ok('order report: renders', !(await hasServerError(page)));
ok('order report: data-view + donut chart', (await page.locator('.k-view').count()) === 1
    && (await page.locator('#dognut-pie').count()) === 1);
ok('order report: rows render', (await page.locator('.k-view .k-table tbody tr').count()) >= 1);
ok('order report: status badge chain', (await page.locator('.k-view .k-badge').count()) >= 1);
ok('order report: excel + pdf actions', (await page.locator('.k-view a[href*="order-report-excel"]').count()) === 1
    && (await page.locator('.k-view a[href*="order-report-pdf"]').count()) === 1);

// Search by the delivered QA order id.
const firstOrderId = (await page.locator('.k-view .k-table tbody tr a[href*="/vendor/orders/details/"]').first().innerText()).trim();
await page.fill('.k-view input[name="search"]', firstOrderId);
await page.press('.k-view input[name="search"]', 'Enter');
await page.waitForLoadState('load');
ok('order report: search works', !(await hasServerError(page))
    && page.url().includes('search=' + firstOrderId)
    && (await page.locator('.k-view .k-table tbody tr').count()) >= 1);

const known = problems.filter(p => !p.includes('Element not found'));
ok('no console/JS/HTTP problems', known.length === 0);
if (known.length) console.log('  problems:', known.slice(0, 8));
await browser.close();
console.log(failures.length ? `FAILURES: ${failures.length}` : 'ALL CLEAN');
process.exit(failures.length ? 1 : 0);
