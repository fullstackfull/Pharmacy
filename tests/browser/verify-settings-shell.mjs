/**
 * Settings/report shell batch: remaining routed admin pages with plain BS
 * tables (business settings, pages-and-media, system setup, third-party,
 * reports) plus the vendor shipping-method page move to the k-table shell.
 * Each page must render without server error, keep zero stray BS tables,
 * and stay console clean.
 */
import { chromium } from '@playwright/test';
import { BASE, watch, loginAdmin, hasServerError } from './_env.mjs';

const VENDOR = { email: 'mahmoudeosama2@gmail.com', password: 'QaVendor!2026' };
const ADMIN_PAGES = [
    '/admin/business-settings/delivery-zone',
    '/admin/pages-and-media/vendor-registration-settings/faq',
    '/admin/custom-role/add',
    '/admin/customer/wallet/bonus-setup',
    '/admin/system-setup/file-manager/index',
    '/admin/helpTopic/index',
    '/admin/pages-and-media/list',
    '/admin/pages-and-media/social-media',
    '/admin/business-settings/shipping-method/index',
    '/admin/system-setup/currency/view',
    '/admin/system-setup/language/translate/en',
    '/admin/theme',
    '/admin/third-party/delivery-syria',
    '/admin/third-party/offline-payment-method/index',
    '/admin/transaction/wallet-bonus',
    '/admin/report/admin-earning',
    '/admin/report/all-product',
    '/admin/stock/product-stock',
];

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
    const stray = await page.locator('.content table.table, .content table[class*="table-hover"]').count();
    ok(`${label}: no stray BS table`, stray === 0);
}

await loginAdmin(page);
for (const path of ADMIN_PAGES) {
    await checkPage(`admin ${path.replace('/admin/', '')}`, BASE + path);
}

await page.goto(BASE + '/vendor/auth/login', { waitUntil: 'domcontentloaded' });
await page.fill('input[name="email"]', VENDOR.email);
await page.fill('input[name="password"]', VENDOR.password);
await page.click('button[type="submit"]');
await page.waitForTimeout(2500);
await checkPage('vendor shipping-method', BASE + '/vendor/business-settings/shipping-method/index');

// Theme preview images 404 only on artisan-serve staging: production points the
// domain at the repo root so /resources/themes/... resolves; the public/themes
// copy the 'public'-docroot branch expects is minted by the theme installer.
const known = problems.filter(p => !p.includes('Element not found')
    && !/HTTP 404 \/themes\/.*\/public\/addon\//.test(p));
ok('no console/JS/HTTP problems', known.length === 0);
if (known.length) console.log('  problems:', known.slice(0, 10));
await browser.close();
console.log(failures.length ? `FAILURES: ${failures.length}` : 'ALL CLEAN');
process.exit(failures.length ? 1 : 0);
