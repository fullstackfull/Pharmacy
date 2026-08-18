/** Flash + featured deal lists on the data-view: render, badges, switcher markup,
 *  priority modal intact, search narrows. */
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

// ---- Flash deals ----
await page.goto(BASE + '/admin/deal/flash', { waitUntil: 'domcontentloaded' });
ok('flash: renders', !(await hasServerError(page)));
ok('flash: data-view present', await page.locator('.k-view').count() === 1);
ok('flash: 2 rows', await page.locator('.k-table tbody tr').count() === 2);
ok('flash: live + expired badges', (await page.locator('.k-badge--warning').count()) === 1
    && (await page.locator('.k-badge--danger').count()) === 1);
ok('flash: switcher intact incl. disabled expired', (await page.locator('.switcher_input.custom-modal-plugin').count()) === 2
    && (await page.locator('.switcher_input[disabled]').count()) === 1);
ok('flash: priority modal present', await page.locator('#prioritySetModal').count() === 1);
await page.goto(BASE + '/admin/deal/flash?searchValue=Old', { waitUntil: 'domcontentloaded' });
ok('flash: search narrows', (await page.locator('.k-table tbody tr').count()) === 1);

// ---- Featured deals ----
await page.goto(BASE + '/admin/deal/feature', { waitUntil: 'domcontentloaded' });
ok('feature: renders', !(await hasServerError(page)));
ok('feature: 1 row with upcoming badge', (await page.locator('.k-table tbody tr').count()) === 1
    && (await page.locator('.k-badge--info').count()) === 1);
ok('feature: switcher + priority modal intact', (await page.locator('.switcher_input.custom-modal-plugin').count()) === 1
    && (await page.locator('#prioritySetModal').count()) === 1);

// ---- Deal of the day ----
await page.goto(BASE + '/admin/deal/day', { waitUntil: 'domcontentloaded' });
ok('day: renders', !(await hasServerError(page)));
ok('day: form + product picker intact', (await page.locator('form[action*="deal/day"]').count()) >= 1
    && (await page.locator('.select-product-search').count()) === 1);
ok('day: 1 row on data-view', (await page.locator('.k-view .k-table tbody tr').count()) === 1);
ok('day: switcher + delete intact', (await page.locator('.switcher_input.custom-modal-plugin').count()) === 1
    && (await page.locator('.delete-data-without-form').count()) === 1);

ok('no console/JS/HTTP problems', problems.length === 0);
if (problems.length) console.log('  problems:', problems.slice(0, 6));
await browser.close();
console.log(failures.length ? `FAILURES: ${failures.length}` : 'ALL CLEAN');
process.exit(failures.length ? 1 : 0);
