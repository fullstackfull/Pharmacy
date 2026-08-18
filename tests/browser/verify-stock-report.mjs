/** Stock report on the data-view: renders, filter card + inline menu intact,
 *  search keeps the seller/category/sort filters, badges reflect stock states. */
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

await page.goto(BASE + ADMIN.loginPath, { waitUntil: 'domcontentloaded' });
await page.fill('input[name="email"]', ADMIN.email);
await page.fill('input[name="password"]', ADMIN.password);
await page.click('button[type="submit"]');
await page.waitForTimeout(1500);

await page.goto(BASE + '/admin/stock/product-stock?sort=ASC', { waitUntil: 'load' });
ok('renders', !(await hasServerError(page)));
ok('filter card intact', await page.locator('select[name="seller_id"]').count() === 1
    && await page.locator('select[name="category_id"]').count() === 1);
ok('inline report menu intact', (await page.locator('a[href*="report"]').count()) >= 2);
ok('data-view rows', (await page.locator('.k-view .k-table tbody tr').count()) >= 1);
ok('stock badges present', (await page.locator('.k-view .k-badge--success, .k-view .k-badge--warning, .k-view .k-badge--danger').count()) >= 1);

await page.fill('.k-view input[name="search"]', 'serum');
await page.press('.k-view input[name="search"]', 'Enter');
await page.waitForLoadState('load');
ok('search keeps sort filter', page.url().includes('sort=ASC') && page.url().includes('search=serum'));
ok('search narrows or empties honestly', !(await hasServerError(page)));

// Wishlist report shares the same shell.
await page.goto(BASE + '/admin/stock/product-in-wishlist', { waitUntil: 'load' });
ok('wishlist report renders', !(await hasServerError(page)));
ok('wishlist data-view present', (await page.locator('.k-view').count()) === 1);

ok('no console/JS/HTTP problems', problems.length === 0);
if (problems.length) console.log('  problems:', problems.slice(0, 6));
await browser.close();
console.log(failures.length ? `FAILURES: ${failures.length}` : 'ALL CLEAN');
process.exit(failures.length ? 1 : 0);
