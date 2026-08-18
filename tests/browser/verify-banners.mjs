/** Banner page on the data-view: renders, type filter narrows via searchValue,
 *  add-banner button still reveals the form (banner.js), switchers intact. */
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

await page.goto(BASE + '/admin/banner/list', { waitUntil: 'domcontentloaded' });
ok('renders', !(await hasServerError(page)));
ok('data-view present', await page.locator('.k-view').count() === 1);
const rows = await page.locator('.k-table tbody tr').count();
ok('rows rendered (4 real banners)', rows === 4);
ok('images render', await page.locator('.k-table tbody img').count() === 4);
ok('switchers intact', await page.locator('.switcher_input.custom-modal-plugin').count() === 4);

// The add form starts hidden and is revealed by banner.js.
ok('form hidden initially', await page.locator('#main-banner.d--none').count() === 1);
await page.click('#main-banner-add');
await page.waitForTimeout(800);
// banner.js slideToggles inline display; the d--none class itself stays.
ok('add-banner reveals the form', await page.locator('#main-banner form.banner_form').isVisible());

// Type filter narrows through the same searchValue param.
await page.selectOption('.k-view select[name="searchValue"]', 'Main Banner');
await page.waitForLoadState('domcontentloaded');
await page.waitForTimeout(500);
const filtered = await page.locator('.k-table tbody tr').count();
ok('type select narrows to main banners', filtered === 1 && decodeURIComponent(page.url()).includes('searchValue=Main'));

ok('no console/JS/HTTP problems', problems.length === 0);
if (problems.length) console.log('  problems:', problems.slice(0, 6));
await browser.close();
console.log(failures.length ? `FAILURES: ${failures.length}` : 'ALL CLEAN');
process.exit(failures.length ? 1 : 0);
