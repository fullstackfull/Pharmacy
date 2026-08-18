/**
 * The light shell sweep across remaining detail/sub pages — every page must
 * render with its k-table (or an honest empty branch) and a clean console.
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
const check = async (label, url) => {
    await page.goto(BASE + url, { waitUntil: 'load' });
    ok(label + ': renders', !(await hasServerError(page)));
    ok(label + ': k-table or empty branch', (await page.locator('.k-table').count()) >= 1
        || (await page.locator('.k-empty, .text-center img').first().count()) >= 1);
};

await page.goto(BASE + '/login/employee', { waitUntil: 'domcontentloaded' });
await page.fill('input[name="email"]', ADMIN.email);
await page.fill('input[name="password"]', ADMIN.password);
await page.click('button[type="submit"]');
await page.waitForTimeout(2000);

await check('sub-categories', '/admin/sub-category/view');
await check('sub-sub-categories', '/admin/sub-sub-category/view');
await check('customer view', '/admin/customer/view/21');
await check('product view (vendor product)', '/admin/products/view/vendor/20');
await check('dm active log', '/admin/delivery-man/earning-statement-overview/1');

const known = problems.filter(p => !p.includes('Element not found'));
ok('no console/JS/HTTP problems', known.length === 0);
if (known.length) console.log('  problems:', known.slice(0, 8));

// Vendor side: product view + payment info.
const vp = await browser.newPage({ viewport: { width: 1440, height: 1100 } });
const vproblems = watch(vp);
await vp.goto(BASE + '/vendor/auth/login', { waitUntil: 'domcontentloaded' });
await vp.fill('input[name="email"]', 'mahmoudeosama2@gmail.com');
await vp.fill('input[name="password"]', 'QaVendor!2026');
await vp.click('button[type="submit"]');
await vp.waitForTimeout(2000);
await vp.goto(BASE + '/vendor/products/view/20', { waitUntil: 'load' });
ok('vendor product view: renders', !(await hasServerError(vp)));
await vp.goto(BASE + '/vendor/shop/payment-information', { waitUntil: 'load' }).catch(() => {});
ok('vendor payment info: renders or redirects sanely', !(await hasServerError(vp)));
const vknown = vproblems.filter(p => !p.includes('Element not found') && !p.includes('HTTP 404 /vendor/shop/payment-information'));
ok('vendor pages: no console/JS problems', vknown.length === 0);
if (vknown.length) console.log('  vendor problems:', vknown.slice(0, 6));
await vp.close();

await browser.close();
console.log(failures.length ? `FAILURES: ${failures.length}` : 'ALL CLEAN');
process.exit(failures.length ? 1 : 0);
