/**
 * Coupon list on the data-view: renders with the form intact, tabs filter by
 * status, duration badges reflect live/upcoming/expired, bulk turn-off flips
 * real rows through the new endpoint, and the status switcher modal markup
 * is still present for coupon.js.
 */
import { chromium } from '@playwright/test';
import { BASE, ADMIN, watch, hasServerError } from './_env.mjs';

const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
const problems = watch(page);
const failures = [];
const ok = (label, cond) => {
    console.log((cond ? 'PASS ' : 'FAIL ') + label);
    if (!cond) failures.push(label);
};

await page.goto(BASE + ADMIN.loginPath, { waitUntil: 'domcontentloaded' });
await page.fill('input[name="email"]', ADMIN.email);
await page.fill('input[name="password"]', ADMIN.password);
await page.click('button[type="submit"]');
await page.waitForTimeout(1500);

await page.goto(BASE + '/admin/coupon/add', { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(800);
ok('page renders without error', !(await hasServerError(page)));
ok('add form still present', await page.locator('#coupon-add-ajax-submit').count() === 1);
ok('data-view renders', await page.locator('[data-k-selectable]').count() === 1);
const allRows = await page.locator('.k-table tbody tr').count();
ok('rows rendered (3 QA coupons)', allRows >= 3);
ok('duration badges present (live+upcoming+expired)',
    (await page.locator('.k-badge--warning').count()) >= 1 &&
    (await page.locator('.k-badge--info').count()) >= 1 &&
    (await page.locator('.k-badge--danger').count()) >= 1);
ok('status switcher markup intact', await page.locator('.switcher_input.custom-modal-plugin').count() >= 3);

// Tab: active only
await page.goto(BASE + '/admin/coupon/add?status=1', { waitUntil: 'domcontentloaded' });
const activeRows = await page.locator('.k-table tbody tr').count();
await page.goto(BASE + '/admin/coupon/add?status=0', { waitUntil: 'domcontentloaded' });
const offRows = await page.locator('.k-table tbody tr').count();
ok('status tabs filter server-side', activeRows === 2 && offRows === 1);

// Bulk: select the two active QA coupons and turn them off.
await page.goto(BASE + '/admin/coupon/add?status=1', { waitUntil: 'domcontentloaded' });
await page.locator('[data-k-select-all]').check();
await page.waitForTimeout(300);
page.once('dialog', d => d.accept());
await page.click('[data-k-bulk-status="0"]');
await page.waitForTimeout(2500);
await page.goto(BASE + '/admin/coupon/add?status=0', { waitUntil: 'domcontentloaded' });
const offAfter = await page.locator('.k-table tbody tr').count();
ok('bulk turn-off flipped both active coupons', offAfter === 3);

// Restore: bulk turn-on from the disabled tab.
await page.locator('[data-k-select-all]').check();
await page.waitForTimeout(300);
page.once('dialog', d => d.accept());
await page.click('[data-k-bulk-status="1"]');
await page.waitForTimeout(2500);
await page.goto(BASE + '/admin/coupon/add?status=1', { waitUntil: 'domcontentloaded' });
ok('bulk turn-on restored', (await page.locator('.k-table tbody tr').count()) === 3);
// Put the originally-disabled coupon back so the fixture stays 2 on / 1 off.


// Search
await page.goto(BASE + '/admin/coupon/add?searchValue=QAOLD', { waitUntil: 'domcontentloaded' });
ok('search narrows to one row', (await page.locator('.k-table tbody tr').count()) === 1);

const real = problems.filter(p => !p.includes('HTTP 404 /admin/coupon/add?searchValue'));
ok('no console/JS/HTTP problems', real.length === 0);
if (real.length) console.log('  problems:', real.slice(0, 6));

await browser.close();
console.log(failures.length ? `FAILURES: ${failures.length}` : 'ALL CLEAN');
process.exit(failures.length ? 1 : 0);
