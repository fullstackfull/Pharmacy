/**
 * Admin refund list (now with status tabs, mirroring the vendor page) and
 * admin customer-reviews list on the data-view — BS5 offcanvas/modal wiring,
 * the no-reload switcher form, reply-state actions, and filters intact.
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

// ---- Refunds -------------------------------------------------------------
await page.goto(BASE + '/admin/refund-section/refund/list/pending', { waitUntil: 'load' });
ok('refunds: renders', !(await hasServerError(page)));
ok('refunds: data-view + four tabs', (await page.locator('.k-view').count()) === 1
    && (await page.locator('.k-view .k-tab').count()) === 4);
ok('refunds: pending tab selected', ((await page.locator('.k-view .k-tab[aria-selected="true"]').innerText().catch(() => ''))).match(/pending/i) !== null);
const refundRows = await page.locator('.k-view .k-table tbody tr').count();
ok('refunds: rows or honest empty', refundRows >= 1 || (await page.locator('.k-view .k-empty').count()) === 1);
if (refundRows >= 1) {
    const row = page.locator('.k-view .k-table tbody tr').first();
    ok('refunds: refund/reject/approve modal buttons', (await row.locator('[data-bs-target^="#refundModal-"]').count()) === 1
        && (await row.locator('[data-bs-target^="#approveModal-"]').count()) === 1);
    ok('refunds: modals exist in DOM', (await page.locator('.modal[id^="refundModal-"]').count()) >= 1);
}
ok('refunds: filter offcanvas + export', (await page.locator('#PendingRefundRequestFilter').count()) === 1
    && (await page.locator('.k-view a[href*="/refund/export/"]').count()) === 1);

await page.click('.k-view .k-tab:has-text("Refunded")');
await page.waitForLoadState('load');
ok('refunds: refunded tab navigates', page.url().includes('/refund/list/refunded') && !(await hasServerError(page)));

// ---- Reviews -------------------------------------------------------------
await page.goto(BASE + '/admin/reviews/list', { waitUntil: 'load' });
ok('reviews: renders', !(await hasServerError(page)));
ok('reviews: data-view present', (await page.locator('.k-view').count()) === 1);
const reviewRows = await page.locator('.k-view .k-table tbody tr').count();
ok('reviews: rows render', reviewRows >= 1);
if (reviewRows >= 1) {
    ok('reviews: no-reload switcher form intact', (await page.locator('.k-view form.no-reload-form .switcher_input.custom-modal-plugin').count()) >= 1);
    ok('reviews: view modal button + modal', (await page.locator('.k-view [data-bs-target^="#review-view-for-"]').count()) >= 1
        && (await page.locator('.modal[id^="review-view-for-"]').count()) >= 1);
}
ok('reviews: filter offcanvas + export', (await page.locator('#reviewFilterOffcanvas').count()) === 1
    && (await page.locator('.k-view a[href*="/reviews/export"]').count()) === 1);

await page.fill('.k-view input[name="searchValue"]', 'lovely');
await page.press('.k-view input[name="searchValue"]', 'Enter');
await page.waitForLoadState('load');
ok('reviews: search works', !(await hasServerError(page))
    && page.url().includes('searchValue=lovely')
    && (await page.locator('.k-view .k-table tbody tr').count()) >= 1);

const known = problems.filter(p => !p.includes('Element not found'));
ok('no console/JS/HTTP problems', known.length === 0);
if (known.length) console.log('  problems:', known.slice(0, 8));
await browser.close();
console.log(failures.length ? `FAILURES: ${failures.length}` : 'ALL CLEAN');
process.exit(failures.length ? 1 : 0);
