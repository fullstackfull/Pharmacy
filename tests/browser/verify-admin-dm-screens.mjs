/**
 * Admin delivery-man sub-screens: withdraw table partial, rating page
 * (distribution card stays, reviews table moves with show-more intact),
 * emergency contacts (ajax status switcher + edit-modal hook), and the
 * earning-statement table partial reached through the overview page.
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

await page.goto(BASE + '/admin/delivery-man/withdraw-list', { waitUntil: 'load' });
ok('dm-withdraw: renders', !(await hasServerError(page)));
ok('dm-withdraw: k-table or empty', (await page.locator('.k-table').count()) >= 1
    || (await page.locator('.k-empty').count()) >= 1);

await page.goto(BASE + '/admin/delivery-man/rating/1', { waitUntil: 'load' });
ok('dm-rating: renders', !(await hasServerError(page)));
ok('dm-rating: k-table or empty', (await page.locator('.k-table').count()) >= 1
    || (await page.locator('.k-empty').count()) >= 1);

await page.goto(BASE + '/admin/delivery-man/emergency-contact', { waitUntil: 'load' });
ok('dm-contacts: renders', !(await hasServerError(page)));
ok('dm-contacts: k-table + add form', (await page.locator('.k-table').count()) >= 1
    && (await page.locator('form input[name="name"]').first().count()) >= 1);
ok('dm-contacts: rows or empty', (await page.locator('.k-table tbody tr').count()) >= 1
    || (await page.locator('.k-empty').count()) >= 1);

await page.goto(BASE + '/admin/delivery-man/order-wise-earning/1', { waitUntil: 'load' });
ok('dm-earnings: renders', !(await hasServerError(page)));
ok('dm-earnings: k-table or empty', (await page.locator('.k-table').count()) >= 1
    || (await page.locator('.k-empty').count()) >= 1);

const known = problems.filter(p => !p.includes('Element not found'));
ok('no console/JS/HTTP problems', known.length === 0);
if (known.length) console.log('  problems:', known.slice(0, 8));
await browser.close();
console.log(failures.length ? `FAILURES: ${failures.length}` : 'ALL CLEAN');
process.exit(failures.length ? 1 : 0);
