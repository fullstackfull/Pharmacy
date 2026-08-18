/** Push-notification screen: send form intact, data-view list renders real rows,
 *  resend/edit/delete hooks present for notification.js. */
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

await page.goto(BASE + '/admin/notification/index', { waitUntil: 'domcontentloaded' }).catch(() => {});
if ((await page.locator('.k-view').count()) === 0) {
    await page.goto(BASE + '/admin/notification/index', { waitUntil: 'domcontentloaded' });
}
ok('renders', !(await hasServerError(page)));
ok('send form intact', await page.locator('form[action*="notification"] input[name="title"]').count() >= 1);
ok('data-view rows', (await page.locator('.k-view .k-table tbody tr').count()) === 3);
ok('switchers intact', (await page.locator('.switcher_input.custom-modal-plugin').count()) === 3);
ok('resend + delete hooks', (await page.locator('.resend-notification').count()) === 3
    && (await page.locator('.delete-data-without-form').count()) === 3);

ok('no console/JS/HTTP problems', problems.length === 0);
if (problems.length) console.log('  problems:', problems.slice(0, 6));
await browser.close();
console.log(failures.length ? `FAILURES: ${failures.length}` : 'ALL CLEAN');
process.exit(failures.length ? 1 : 0);
