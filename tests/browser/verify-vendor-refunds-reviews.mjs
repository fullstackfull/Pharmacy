/**
 * Vendor refund list + review list on the data-view. Refunds gain status tabs
 * (the old card said "Pending" no matter which status the sidebar opened);
 * reviews keep switcher forms, view/reply modals, lightbox attachments, and
 * the filter offcanvas. Fixtures: a seeded review and a pending refund on the
 * QA vendor's own COD order.
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

// ---- Refunds -------------------------------------------------------------
await page.goto(BASE + '/vendor/refund/index/pending', { waitUntil: 'load' });
ok('refunds: renders', !(await hasServerError(page)));
ok('refunds: data-view present', (await page.locator('.k-view').count()) === 1);
ok('refunds: four status tabs', (await page.locator('.k-view .k-tab').count()) === 4);
ok('refunds: pending tab selected', (await page.locator('.k-view .k-tab[aria-selected="true"]').innerText()).match(/pending/i) !== null);
const refundRows = await page.locator('.k-view .k-table tbody tr').count();
ok('refunds: rows render', refundRows >= 1);
const refundRow = page.locator('.k-view .k-table tbody tr').first();
ok('refunds: refund id links to details', (await refundRow.locator('a[href*="/vendor/refund/details/"]').count()) >= 1);
ok('refunds: order id links to order', (await refundRow.locator('a[href*="/vendor/orders/details/"]').count()) === 1);
ok('refunds: approve/reject modal buttons', (await refundRow.locator('[data-target^="#approveModal-"]').count()) === 1
    && (await refundRow.locator('[data-target^="#rejectModal-"]').count()) === 1);
ok('refunds: approve modal exists in DOM', (await page.locator('.modal[id^="approveModal-"]').count()) >= 1);
ok('refunds: filter offcanvas present', (await page.locator('#PendingRefundRequestFilter').count()) === 1);
ok('refunds: export link present', (await page.locator('.k-view a[href*="/vendor/refund/export/"]').count()) === 1);

await page.click('.k-view .k-tab:has-text("Approved")');
await page.waitForLoadState('load');
ok('refunds: approved tab navigates', page.url().includes('/vendor/refund/index/approved')
    && !(await hasServerError(page)));
ok('refunds: approved tab now selected', (await page.locator('.k-view .k-tab[aria-selected="true"]').innerText()).match(/approved/i) !== null);

// ---- Reviews -------------------------------------------------------------
await page.goto(BASE + '/vendor/reviews/index', { waitUntil: 'load' });
ok('reviews: renders', !(await hasServerError(page)));
ok('reviews: data-view present', (await page.locator('.k-view').count()) === 1);
const reviewRows = await page.locator('.k-view .k-table tbody tr').count();
ok('reviews: rows render', reviewRows >= 1);
const reviewRow = page.locator('.k-view .k-table tbody tr').first();
ok('reviews: status switcher form intact', (await reviewRow.locator('form.reviews_status_form .switcher_input.toggle-switch-message').count()) === 1);
ok('reviews: view modal button + modal', (await reviewRow.locator('[data-target^="#review-view-for-"]').count()) === 1
    && (await page.locator('.modal[id^="review-view-for-"]').count()) >= 1);
ok('reviews: no stray template garbage', !(await page.content()).includes('`)">'));
ok('reviews: filter offcanvas present', (await page.locator('#reviewFilterOffcanvas').count()) === 1);
ok('reviews: export link present', (await page.locator('.k-view a[href*="/vendor/reviews/export"]').count()) === 1);

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
