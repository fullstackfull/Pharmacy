/**
 * RTL + mobile sweep across the migrated surfaces. The session is switched to
 * Arabic (server-side direction), then every key screen is loaded at desktop
 * and phone widths. A screen passes when the document is genuinely RTL, the
 * body does not scroll horizontally (wide tables must scroll inside their own
 * k-table-wrap), and the console stays clean.
 */
import { chromium } from '@playwright/test';
import { BASE, watch, loginAdmin, setDirection, hasServerError, SHOTS } from './_env.mjs';
import { mkdirSync } from 'fs';

const VENDOR = { email: 'mahmoudeosama2@gmail.com', password: 'QaVendor!2026' };
mkdirSync(SHOTS, { recursive: true });

const ADMIN_SCREENS = [
    ['orders-list', '/admin/orders/list/all'],
    ['order-details', '/admin/orders/details/100005'],
    ['pos-order-details', '/admin/orders/details/100023'],
    ['products-list', '/admin/products/list/in-house'],
    ['pos', '/admin/pos'],
    ['refunds', '/admin/refund-section/refund/list/pending'],
    ['customers', '/admin/customer/list'],
    ['vendors', '/admin/vendors/list'],
    ['delivery-men', '/admin/delivery-man/list'],
    ['marketplace-payouts', '/admin/marketplace/payouts'],
    ['settings', '/admin/business-settings/web-config'],
    ['stock-limit', '/admin/products/stock-limit-list/in_house'],
];
const VENDOR_SCREENS = [
    ['v-orders', '/vendor/orders/list/all'],
    ['v-products', '/vendor/products/list/all'],
    ['v-pos', '/vendor/pos'],
];

const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
const problems = watch(page);
const failures = [];
const ok = (label, cond) => {
    console.log((cond ? 'PASS ' : 'FAIL ') + label);
    if (!cond) failures.push(label);
};

async function checkScreen(label, url) {
    for (const [device, width, height] of [['desktop', 1440, 1000], ['mobile', 390, 844]]) {
        await page.setViewportSize({ width, height });
        await page.goto(url, { waitUntil: 'load' }).catch(async () => {
            await page.waitForTimeout(2000);
            await page.goto(url, { waitUntil: 'load' });
        });
        await page.waitForTimeout(1800);
        const state = await page.evaluate(() => ({
            dir: document.documentElement.getAttribute('dir'),
            scrollWidth: document.scrollingElement.scrollWidth,
            innerWidth: window.innerWidth,
        }));
        const broken = await hasServerError(page);
        ok(`${label} [${device}]: renders rtl`, !broken && state.dir === 'rtl');
        const overflow = state.scrollWidth - state.innerWidth;
        ok(`${label} [${device}]: no page-level horizontal overflow`, overflow <= 2);
        if (overflow > 2) console.log(`  overflow: ${overflow}px (scrollWidth ${state.scrollWidth} vs ${state.innerWidth})`);
        if (device === 'mobile') await page.screenshot({ path: `${SHOTS}/rtl-${label}-${device}.png`, fullPage: false }).catch(() => {});
    }
}

// admin in Arabic
await loginAdmin(page);
ok('session switched to arabic', (await setDirection(page, 'rtl')) === 200);
for (const [label, path] of ADMIN_SCREENS) await checkScreen(`admin ${label}`, BASE + path);

// vendor in Arabic (same session cookie carries the direction)
await page.goto(BASE + '/vendor/auth/login', { waitUntil: 'domcontentloaded' });
await page.fill('input[name="email"]', VENDOR.email);
await page.fill('input[name="password"]', VENDOR.password);
await page.click('button[type="submit"]');
await page.waitForTimeout(2500);
for (const [label, path] of VENDOR_SCREENS) await checkScreen(`vendor ${label}`, BASE + path);

// storefront home + PDP in Arabic
await checkScreen('web home', BASE + '/');
await checkScreen('web pdp', BASE + '/product/qa-vendor-product-1787046108');

const known = problems.filter(p => !p.includes('Element not found')
    && !/HTTP 404 \/themes\/.*\/public\/addon\//.test(p));
ok('no console/JS/HTTP problems', known.length === 0);
if (known.length) console.log('  problems:', known.slice(0, 12));
await browser.close();
console.log(failures.length ? `FAILURES: ${failures.length}` : 'ALL CLEAN');
process.exit(failures.length ? 1 : 0);
