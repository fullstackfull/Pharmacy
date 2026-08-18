/** Subscriber list on the data-view: renders with real rows, filter card intact,
 *  search preserves filter params via the data-view's hidden inputs. */
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

await page.goto(BASE + '/admin/customer/subscriber-list?sort_by=desc', { waitUntil: 'domcontentloaded' });
ok('renders', !(await hasServerError(page)));
ok('filter card intact', await page.locator('input[name="subscription_date"]').count() >= 1);
ok('3 real rows', await page.locator('.k-table tbody tr').count() === 3);

// Search through the data-view keeps the sort_by filter.
await page.fill('.k-view input[name="searchValue"]', '@');
await page.press('.k-view input[name="searchValue"]', 'Enter');
await page.waitForLoadState('domcontentloaded');
ok('search keeps existing filters', page.url().includes('sort_by=desc') && page.url().includes('searchValue='));
ok('search matches emails', (await page.locator('.k-table tbody tr').count()) >= 1);

ok('no console/JS/HTTP problems', problems.length === 0);
if (problems.length) console.log('  problems:', problems.slice(0, 6));
await browser.close();
console.log(failures.length ? `FAILURES: ${failures.length}` : 'ALL CLEAN');
process.exit(failures.length ? 1 : 0);
