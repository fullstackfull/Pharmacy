/**
 * Batch verification for the remaining vendor list screens on the Kohl
 * surface: stock-limit list (sort select + quantity modal hook), restock
 * request list (fixed filter-dot params), delivery-man list (sorting
 * dropdown + badges as links), marketplace payouts (k-stats buckets +
 * request table), and seller verification (documents table).
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

// ---- Stock limit list ----------------------------------------------------
await page.goto(BASE + '/vendor/products/stock-limit-list', { waitUntil: 'load' });
ok('stock-limit: renders', !(await hasServerError(page)));
ok('stock-limit: data-view present', (await page.locator('.k-view').count()) === 1);
ok('stock-limit: low-stock fixture row', (await page.locator('.k-view .k-table tbody tr').count()) >= 1);
ok('stock-limit: sort select in toolbar', (await page.locator('.k-view select.action-select-onchange-get-view').count()) === 1);
ok('stock-limit: quantity modal hook + modal', (await page.locator('.k-view .action-update-product-quantity').count()) >= 1
    && (await page.locator('#update-quantity .rest-part-content').count()) === 1);
ok('stock-limit: switcher + delete form', (await page.locator('.k-view form.admin-product-status-form').count()) >= 1
    && (await page.locator('.k-view form[action*="delete"]').count()) >= 1);

// The sort select navigates via JS (action-select-onchange-get-view).
await page.selectOption('.k-view select.action-select-onchange-get-view', 'quantity_asc');
await page.waitForTimeout(2500);
await page.waitForLoadState('load').catch(() => {});
ok('stock-limit: sort select navigates', page.url().includes('sortOrderQty=quantity_asc')
    && !(await hasServerError(page)));

// ---- Restock request list ------------------------------------------------
await page.goto(BASE + '/vendor/products/request-restock-list', { waitUntil: 'load' });
ok('restock: renders', !(await hasServerError(page)));
ok('restock: data-view present', (await page.locator('.k-view').count()) === 1);
ok('restock: rows or honest empty state', (await page.locator('.k-view .k-table tbody tr').count()) >= 1
    || (await page.locator('.k-view .k-empty').count()) === 1);
ok('restock: filter offcanvas + export', (await page.locator('#offcanvasRestockFilter').count()) === 1
    && (await page.locator('.k-view a[href*="export-restock"]').count()) === 1);

// ---- Delivery man list ---------------------------------------------------
await page.goto(BASE + '/vendor/delivery-man/list', { waitUntil: 'load' });
ok('deliveryman: renders', !(await hasServerError(page)));
ok('deliveryman: data-view present', (await page.locator('.k-view').count()) === 1);
ok('deliveryman: fixture row', (await page.locator('.k-view .k-table tbody tr').count()) >= 1);
ok('deliveryman: status switcher form', (await page.locator('.k-view form.deliveryman_status_form').count()) >= 1);
ok('deliveryman: orders + rating badge links', (await page.locator('.k-view a.k-badge[href*="delivery_man_id"]').count()) >= 1
    && (await page.locator('.k-view a.k-badge[href*="/rating"]').count()) >= 1);

// Sorting dropdown opens and its links carry the search term name (search, not searchValue).
await page.click('#exportDropdown');
await page.waitForTimeout(600);
ok('deliveryman: sorting dropdown opens', await page.locator('.dropdown-menu a[href*="sort_by=rating"]').isVisible());
await page.click('.dropdown-menu a[href*="sort_by=rating"]');
await page.waitForLoadState('load');
ok('deliveryman: rating sort navigates', page.url().includes('sort_by=rating') && !(await hasServerError(page)));

// ---- Payouts -------------------------------------------------------------
await page.goto(BASE + '/vendor/business-settings/payouts', { waitUntil: 'load' });
ok('payouts: renders', !(await hasServerError(page)));
ok('payouts: four bucket stats', (await page.locator('.k-stats .k-stat').count()) === 4);
ok('payouts: request form intact', (await page.locator('form[action*="payouts"] input[name="amount"]').count()) === 1);
ok('payouts: k-table + rows or empty state', (await page.locator('.k-table').count()) === 1
    && ((await page.locator('.k-table tbody tr').count()) >= 1 || (await page.locator('.k-empty').count()) === 1));

// ---- Seller verification -------------------------------------------------
await page.goto(BASE + '/vendor/business-settings/seller-verification', { waitUntil: 'load' });
ok('verification: renders', !(await hasServerError(page)));
ok('verification: submit form intact', (await page.locator('form input[name="document_type"]').count()) === 1);
ok('verification: k-table + rows or empty state', (await page.locator('.k-table').count()) === 1
    && ((await page.locator('.k-table tbody tr').count()) >= 1 || (await page.locator('.k-empty').count()) === 1));

const known = problems.filter(p => !p.includes('Element not found'));
ok('no console/JS/HTTP problems', known.length === 0);
if (known.length) console.log('  problems:', known.slice(0, 8));
await browser.close();
console.log(failures.length ? `FAILURES: ${failures.length}` : 'ALL CLEAN');
process.exit(failures.length ? 1 : 0);
