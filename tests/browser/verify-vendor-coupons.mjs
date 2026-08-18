/**
 * Vendor coupon page: the add form stays the theme's own; the list below it
 * moves onto the data-view. The suite creates a coupon through the real form
 * (select2 coupon type, generated code), then verifies the row: owned-coupon
 * switcher, edit/delete, quick-view modal via coupon.js, and search.
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

await page.goto(BASE + '/vendor/coupon/index', { waitUntil: 'load' });
ok('page renders', !(await hasServerError(page)));
ok('add form intact', (await page.locator('form[action*="/vendor/coupon/add"]').count()) === 1);
ok('data-view present', (await page.locator('.k-view').count()) === 1);

// Create one through the real form if the list is empty.
if ((await page.locator('.k-view .k-table tbody tr').count()) === 0) {
    await page.selectOption('#coupon_type', 'discount_on_purchase');
    await page.selectOption('select[name="customer_id"]', '0');
    await page.fill('#title', 'QA Vendor Coupon');
    await page.fill('#code', 'QAVEND5');
    await page.fill('#coupon_limit', '5');
    await page.selectOption('#discount_type', 'amount');
    await page.fill('#discount', '5');
    await page.fill('input[name="min_purchase"]', '10');
    const today = new Date();
    const plus30 = new Date(Date.now() + 30 * 86400000);
    const fmt = d => d.toISOString().slice(0, 10);
    await page.fill('#start_date', fmt(today));
    await page.fill('#expire_date', fmt(plus30));
    await page.click('form[action*="/vendor/coupon/add"] button[type="submit"]');
    await page.waitForLoadState('load');
    await page.waitForTimeout(1000);
    console.log('  (info) created coupon, now at', page.url());
    await page.goto(BASE + '/vendor/coupon/index', { waitUntil: 'load' });
}

const rows = await page.locator('.k-view .k-table tbody tr').count();
ok('coupon rows render', rows >= 1);
const row = page.locator('.k-view .k-table tbody tr').first();
ok('owned coupon has live switcher', (await page.locator('.k-view form.coupon_status_form .switcher_input').count()) >= 1);
ok('edit + delete present', (await row.locator('a[href*="/vendor/coupon/update/"]').count()) >= 1
    && (await row.locator('form[action*="/vendor/coupon/delete/"]').count()) === 1);
ok('quick-view span + modal shell', (await page.locator('#get-detail-url').count()) === 1
    && (await page.locator('#quick-view #quick-view-modal').count()) === 1);
ok('export link present', (await page.locator('.k-view a[href*="/vendor/coupon/export"]').count()) === 1);

// Quick view: coupon.js fetches into the modal.
await row.locator('.get-quick-view').click();
await page.waitForTimeout(2500);
ok('quick-view modal fills', ((await page.locator('#quick-view-modal').innerHTML()).trim().length) > 50);
await page.keyboard.press('Escape');
await page.waitForTimeout(600);

await page.fill('.k-view input[name="searchValue"]', 'QAVEND5');
await page.press('.k-view input[name="searchValue"]', 'Enter');
await page.waitForLoadState('load');
ok('search by code works', !(await hasServerError(page))
    && page.url().includes('searchValue=QAVEND5')
    && (await page.locator('.k-view .k-table tbody tr').count()) >= 1);

const known = problems.filter(p => !p.includes('Element not found'));
ok('no console/JS/HTTP problems', known.length === 0);
if (known.length) console.log('  problems:', known.slice(0, 8));
await browser.close();
console.log(failures.length ? `FAILURES: ${failures.length}` : 'ALL CLEAN');
process.exit(failures.length ? 1 : 0);
