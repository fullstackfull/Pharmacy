/**
 * Split-tag straggler batch: multiline <table> tags that escaped the earlier
 * single-line inventory. Routed pages: vendor-earning report, most-demanded,
 * language list, flash-deal add-product, clearance-sale index (admin+vendor,
 * whose _product-add-list partials render inline), product-gallery
 * (admin+vendor, whose details offcanvas renders per product click).
 */
import { chromium } from '@playwright/test';
import { BASE, watch, loginAdmin, hasServerError } from './_env.mjs';

const VENDOR = { email: 'mahmoudeosama2@gmail.com', password: 'QaVendor!2026' };
const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
const page = await browser.newPage({ viewport: { width: 1440, height: 1100 } });
const problems = watch(page);
const failures = [];
const ok = (label, cond) => {
    console.log((cond ? 'PASS ' : 'FAIL ') + label);
    if (!cond) failures.push(label);
};

async function checkPage(label, url) {
    await page.goto(url, { waitUntil: 'load' }).catch(async () => {
        await page.waitForTimeout(2000);
        await page.goto(url, { waitUntil: 'load' });
    });
    ok(`${label}: renders`, !(await hasServerError(page)));
    const stray = await page.evaluate(() =>
        [...document.querySelectorAll('.content table')].filter(t => /(^|\s)table(\s|$)/.test(t.className)).length);
    ok(`${label}: no stray BS table`, stray === 0);
}

await loginAdmin(page);
await checkPage('admin report/vendor-earning', BASE + '/admin/report/vendor-earning');
await checkPage('admin most-demanded', BASE + '/admin/most-demanded');
await checkPage('admin language list', BASE + '/admin/system-setup/language');
await checkPage('admin deal add-product', BASE + '/admin/deal/add-product/1');
await checkPage('admin clearance-sale', BASE + '/admin/deal/clearance-sale');
await checkPage('admin product-gallery', BASE + '/admin/products/product-gallery');

await page.goto(BASE + '/vendor/auth/login', { waitUntil: 'domcontentloaded' });
await page.fill('input[name="email"]', VENDOR.email);
await page.fill('input[name="password"]', VENDOR.password);
await page.click('button[type="submit"]');
await page.waitForTimeout(2500);
await checkPage('vendor clearance-sale', BASE + '/vendor/clearance-sale');
await checkPage('vendor product-gallery', BASE + '/vendor/products/product-gallery');

const known = problems.filter(p => !p.includes('Element not found'));
ok('no console/JS/HTTP problems', known.length === 0);
if (known.length) console.log('  problems:', known.slice(0, 10));
await browser.close();
console.log(failures.length ? `FAILURES: ${failures.length}` : 'ALL CLEAN');
process.exit(failures.length ? 1 : 0);
