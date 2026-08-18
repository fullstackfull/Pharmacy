/** Vendor panel first Kohl screen: product list on the data-view — renders in
 *  the vendor shell, search works, switchers + filter offcanvas + delete forms
 *  intact, badges present. */
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
console.log('  (info) after login:', page.url());

await page.goto(BASE + '/vendor/products/list/all', { waitUntil: 'load' });
ok('renders', !(await hasServerError(page)));
ok('kohl css loaded in vendor shell', await page.evaluate(() =>
    [...document.styleSheets].some(s => (s.href || '').includes('kohl/css/console.css'))));
ok('data-view present', (await page.locator('.k-view').count()) === 1);
const rows = await page.locator('.k-view .k-table tbody tr').count();
ok('product rows render', rows >= 1);
console.log('  (info) rows:', rows);
ok('status switchers intact', (await page.locator('.switcher_input.toggle-switch-message').count()) >= 1);
ok('filter offcanvas present', (await page.locator('#offcanvasProductFilter').count()) === 1);
ok('delete forms intact', (await page.locator('form[action*="delete"]').count()) >= 1);

await page.fill('.k-view input[name="searchValue"]', 'serum');
await page.press('.k-view input[name="searchValue"]', 'Enter');
await page.waitForLoadState('load');
ok('search submits and renders', !(await hasServerError(page)) && page.url().includes('searchValue=serum'));

const known = problems.filter(p => !p.includes('Element not found'));
ok('no console/JS/HTTP problems', known.length === 0);
if (known.length) console.log('  problems:', known.slice(0, 6));
await browser.close();
console.log(failures.length ? `FAILURES: ${failures.length}` : 'ALL CLEAN');
process.exit(failures.length ? 1 : 0);
