/**
 * Delivery-man sub-screens on the Kohl surface: rating (summary card stays,
 * reviews table moves), emergency contacts (form stays, table moves), the
 * wallet trio (earning history, cash collect, order history with its
 * status-history modal hook), and the withdraw table partial.
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

// ---- Rating page ---------------------------------------------------------
await page.goto(BASE + '/vendor/delivery-man/rating/1', { waitUntil: 'load' });
ok('rating: renders', !(await hasServerError(page)));
ok('rating: summary progress bars intact', (await page.locator('.progress-bar').count()) >= 5);
ok('rating: data-view with rows or empty state', (await page.locator('.k-view').count()) === 1
    && ((await page.locator('.k-view .k-table tbody tr').count()) >= 1
        || (await page.locator('.k-view .k-empty').count()) === 1));
ok('rating: filter form intact', (await page.locator('select[name="rating"]').count()) === 1);

// ---- Emergency contacts --------------------------------------------------
await page.goto(BASE + '/vendor/delivery-man/emergency-contact/index', { waitUntil: 'load' });
ok('contacts: renders', !(await hasServerError(page)));
ok('contacts: add form intact', (await page.locator('form[action*="emergency-contact"] input[name="name"]').count()) >= 1);
ok('contacts: k-table + rows or empty', (await page.locator('.k-table').count()) === 1
    && ((await page.locator('.k-table tbody tr').count()) >= 1 || (await page.locator('.k-empty').count()) === 1));

// Create one through the real form if empty, then check switcher/actions.
if ((await page.locator('.k-table tbody tr').count()) === 0) {
    await page.fill('form[action*="emergency-contact"] input[name="name"]', 'QA Emergency');
    await page.fill('form[action*="emergency-contact"] input[type="tel"]', '55501234');
    await page.evaluate(() => {
        const el = document.querySelector('form[action*="emergency-contact"] input[name="phone"]');
        if (el) { el.value = '55501234'; el.dispatchEvent(new Event('input', { bubbles: true })); }
    });
    await page.click('form[action*="emergency-contact"] button[type="submit"]');
    await page.waitForLoadState('load');
    await page.waitForTimeout(1000);
}
ok('contacts: row with switcher + edit + delete', (await page.locator('.k-table form.contact_status_form').count()) >= 1
    && (await page.locator('.k-table .emergency-contact-update-view').count()) >= 1
    && (await page.locator('.k-table .delete-data').count()) >= 1);

// ---- Wallet: earning -----------------------------------------------------
await page.goto(BASE + '/vendor/delivery-man/wallet/earning/1', { waitUntil: 'load' });
ok('earning: renders', !(await hasServerError(page)));
ok('earning: data-view + rows or empty', (await page.locator('.k-view').count()) === 1
    && ((await page.locator('.k-view .k-table tbody tr').count()) >= 1
        || (await page.locator('.k-view .k-empty').count()) === 1));

// ---- Wallet: cash collect ------------------------------------------------
await page.goto(BASE + '/vendor/delivery-man/wallet/cash-collect/1', { waitUntil: 'load' });
ok('cash-collect: renders', !(await hasServerError(page)));
ok('cash-collect: receive form intact', (await page.locator('form input[name="amount"]').count()) >= 1);
ok('cash-collect: k-table + rows or empty', (await page.locator('.k-table').count()) === 1
    && ((await page.locator('.k-table tbody tr').count()) >= 1 || (await page.locator('.k-empty').count()) === 1));

// ---- Wallet: order history ----------------------------------------------
await page.goto(BASE + '/vendor/delivery-man/wallet/order-history/1', { waitUntil: 'load' });
ok('order-history: renders', !(await hasServerError(page)));
ok('order-history: data-view + modal shell + url span', (await page.locator('.k-view').count()) === 1
    && (await page.locator('#exampleModalLong .load-with-ajax').count()) === 1
    && (await page.locator('#order-status-url').count()) === 1);

// ---- Withdraws -----------------------------------------------------------
await page.goto(BASE + '/vendor/delivery-man/withdraw/index', { waitUntil: 'load' });
ok('withdraws: renders', !(await hasServerError(page)));
ok('withdraws: k-table + rows or empty', (await page.locator('.k-table').count()) >= 1
    && ((await page.locator('.k-table tbody tr').count()) >= 1 || (await page.locator('.k-empty').count()) >= 1));

const known = problems.filter(p => !p.includes('Element not found'));
ok('no console/JS/HTTP problems', known.length === 0);
if (known.length) console.log('  problems:', known.slice(0, 8));
await browser.close();
console.log(failures.length ? `FAILURES: ${failures.length}` : 'ALL CLEAN');
process.exit(failures.length ? 1 : 0);
