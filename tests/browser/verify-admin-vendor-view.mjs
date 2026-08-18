/**
 * Admin vendor-detail tabs on the Kohl surface: order tab (summary tiles
 * stay, table + badge chain move), product tab (featured checkbox and
 * status switcher intact), transaction tab (filter card stays, data-view
 * table), review + clearance tabs (light shell pass), and the
 * withdraw-method setup list. Seller 1 has real orders and products.
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

await page.goto(BASE + '/admin/vendors/view/1/order', { waitUntil: 'load' });
ok('order tab: renders', !(await hasServerError(page)));
ok('order tab: k-table with rows', (await page.locator('.k-table tbody tr').count()) >= 1);
ok('order tab: status + paid badges', (await page.locator('.k-table .k-badge').count()) >= 2);
ok('order tab: view/invoice actions', (await page.locator('.k-table a[href*="generate-invoice"]').count()) >= 1);

await page.goto(BASE + '/admin/vendors/view/1/product', { waitUntil: 'load' });
ok('product tab: renders', !(await hasServerError(page)));
ok('product tab: k-table with rows', (await page.locator('.k-table tbody tr').count()) >= 1);
ok('product tab: featured checkbox + switcher intact', (await page.locator('.k-table .product-status-checkbox').count()) >= 1
    && (await page.locator('.k-table form.status-update-form .custom-modal-plugin').count()) >= 1);

await page.goto(BASE + '/admin/vendors/view/1/transaction', { waitUntil: 'load' });
ok('transaction tab: renders', !(await hasServerError(page)));
ok('transaction tab: data-view + rows or empty', (await page.locator('.k-view').count()) === 1
    && ((await page.locator('.k-table tbody tr').count()) >= 1 || (await page.locator('.k-empty').count()) >= 1));
ok('transaction tab: filter card intact', (await page.locator('#date_type').count()) === 1);

await page.goto(BASE + '/admin/vendors/view/1/review', { waitUntil: 'load' });
ok('review tab: renders', !(await hasServerError(page)));
ok('review tab: k-table present', (await page.locator('.k-table').count()) >= 1);

await page.goto(BASE + '/admin/vendors/view/1/clearance_sale', { waitUntil: 'load' });
ok('clearance tab: renders', !(await hasServerError(page)));
// The clearance tables only render once the vendor configures a sale;
// with none active the tab shows its setup state.
ok('clearance tab: k-table or setup state', (await page.locator('.k-table').count()) >= 1
    || (await page.locator('.card, .k-card').count()) >= 1);

await page.goto(BASE + '/admin/third-party/withdraw-method/list', { waitUntil: 'load' });
ok('withdraw methods: renders', !(await hasServerError(page)));
ok('withdraw methods: data-view + rows or empty', (await page.locator('.k-view').count()) === 1
    && ((await page.locator('.k-table tbody tr').count()) >= 1 || (await page.locator('.k-empty').count()) >= 1));

const known = problems.filter(p => !p.includes('Element not found'));
ok('no console/JS/HTTP problems', known.length === 0);
if (known.length) console.log('  problems:', known.slice(0, 8));
await browser.close();
console.log(failures.length ? `FAILURES: ${failures.length}` : 'ALL CLEAN');
process.exit(failures.length ? 1 : 0);
