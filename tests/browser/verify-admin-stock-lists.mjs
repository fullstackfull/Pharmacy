/**
 * The two admin lists that slipped past earlier batches — request-restock and
 * stock-limit — rebuilt on the data-view like their vendor twins. Checks the
 * search, the sort select (now carrying the search term), the update-quantity
 * modal hook, and the restock filter offcanvas.
 */
import { chromium } from '@playwright/test';
import { BASE, watch, loginAdmin, hasServerError } from './_env.mjs';

const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
const page = await browser.newPage({ viewport: { width: 1440, height: 1100 } });
const problems = watch(page);
const failures = [];
const ok = (label, cond) => {
    console.log((cond ? 'PASS ' : 'FAIL ') + label);
    if (!cond) failures.push(label);
};

await loginAdmin(page);

// ---- stock-limit list ----
await page.goto(BASE + '/admin/products/stock-limit-list/in_house', { waitUntil: 'load' });
ok('stock-limit: renders', !(await hasServerError(page)));
ok('stock-limit: data-view with k-table', (await page.locator('.k-view .k-table').count()) === 1);
ok('stock-limit: rows or honest empty', (await page.locator('.k-table tbody tr').count()) >= 1
    || (await page.locator('.k-empty').count()) >= 1);
ok('stock-limit: sort select keeps search term',
    ((await page.locator('.action-select-onchange-get-view').getAttribute('data-url-prefix')) || '').includes('searchValue='));

const rows = await page.locator('.k-table tbody tr').count();
if (rows > 0) {
    await page.locator('.action-update-product-quantity').first().click();
    await page.waitForTimeout(2500);
    ok('stock-limit: update-quantity modal opens with content',
        (await page.locator('#update-quantity.show .rest-part-content *').count()) >= 1);
    await page.locator('#update-quantity [data-bs-dismiss="modal"]').first().click().catch(() => {});
    await page.waitForTimeout(800);
} else {
    console.log('  (no low-stock rows; modal hook untested here)');
}

// ---- request-restock list ----
await page.goto(BASE + '/admin/products/request-restock-list', { waitUntil: 'load' });
ok('restock: renders', !(await hasServerError(page)));
ok('restock: data-view with k-table', (await page.locator('.k-view .k-table').count()) === 1);
ok('restock: rows or honest empty', (await page.locator('.k-table tbody tr').count()) >= 1
    || (await page.locator('.k-empty').count()) >= 1);
await page.locator('[data-bs-target="#offcanvasRestockFilter"]').click();
await page.waitForTimeout(1200);
ok('restock: filter offcanvas opens', (await page.locator('#offcanvasRestockFilter.show').count()) === 1);

const known = problems.filter(p => !p.includes('Element not found'));
ok('no console/JS/HTTP problems', known.length === 0);
if (known.length) console.log('  problems:', known.slice(0, 10));
await browser.close();
console.log(failures.length ? `FAILURES: ${failures.length}` : 'ALL CLEAN');
process.exit(failures.length ? 1 : 0);
