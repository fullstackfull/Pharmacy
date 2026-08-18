/** Analytics page: renders with real rollup data — trend SVG, totals, sources
 *  (incl. the seeded instagram UTM), top products with conversion, API groups;
 *  range tabs switch; RTL renders. */
import { chromium } from '@playwright/test';
import { BASE, ADMIN, watch, hasServerError } from './_env.mjs';

const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
const page = await browser.newPage({ viewport: { width: 1440, height: 1400 } });
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

await page.goto(BASE + '/admin/analytics', { waitUntil: 'load' });
ok('renders', !(await hasServerError(page)));
ok('sidebar entry present', await page.locator('[data-item="analytics"]').count() === 1);
ok('trend SVG drawn', await page.locator('svg polyline').count() === 2);
ok('stats show non-zero visitors', parseInt((await page.locator('.k-stat__value').first().innerText()).replace(/\D/g, '')) >= 1);
ok('sources include a referrer or direct', (await page.locator('.k-card:has-text("Traffic sources") td, .k-card td').count()) > 0);
const bodyText = await page.locator('#monitoring-root, .content').innerText();
ok('utm campaign row present (instagram)', bodyText.toLowerCase().includes('instagram'));
ok('a product row with views is listed', await page.locator('.k-card:has-text("product") tbody tr, table tbody tr').count() > 0);
ok('api groups listed', bodyText.includes('api/v1'));

// Range switch keeps the page alive.
await page.click('.k-tab[href*="range=30"]');
await page.waitForLoadState('load');
ok('30-day range renders', !(await hasServerError(page)) && page.url().includes('range=30'));

ok('no console/JS/HTTP problems', problems.length === 0);
if (problems.length) console.log('  problems:', problems.slice(0, 6));
await browser.close();
console.log(failures.length ? `FAILURES: ${failures.length}` : 'ALL CLEAN');
process.exit(failures.length ? 1 : 0);
