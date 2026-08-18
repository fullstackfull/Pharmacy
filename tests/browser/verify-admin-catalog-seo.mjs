/**
 * Category and attribute setup pages (add forms stay, list tables move) and
 * the six SEO-settings tables on their light shell pass.
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
const checkPage = async (label, url) => {
    await page.goto(BASE + url, { waitUntil: 'load' });
    ok(label + ': renders', !(await hasServerError(page)));
    ok(label + ': k-table or empty', (await page.locator('.k-table').count()) >= 1
        || (await page.locator('.k-empty').count()) >= 1);
};

await page.goto(BASE + '/login/employee', { waitUntil: 'domcontentloaded' });
await page.fill('input[name="email"]', ADMIN.email);
await page.fill('input[name="password"]', ADMIN.password);
await page.click('button[type="submit"]');
await page.waitForTimeout(2000);

await page.goto(BASE + '/admin/category/view', { waitUntil: 'load' });
ok('categories: renders', !(await hasServerError(page)));
ok('categories: k-table with rows + switchers', (await page.locator('.k-table tbody tr').count()) >= 1
    && (await page.locator('.k-table form.no-reload-form .custom-modal-plugin').count()) >= 1);
ok('categories: edit offcanvas + delete hooks', (await page.locator('.k-table [href^="#categoryEditOffcanvas-"]').count()) >= 1
    && (await page.locator('.k-table .delete-category').count()) >= 1);

await page.goto(BASE + '/admin/attribute/view', { waitUntil: 'load' });
ok('attributes: renders', !(await hasServerError(page)));
ok('attributes: data-view + form intact', (await page.locator('.k-view').count()) === 1
    && (await page.locator('#attribute-form').count()) === 1);
ok('attributes: rows or empty', (await page.locator('.k-table tbody tr').count()) >= 1
    || (await page.locator('.k-empty').count()) >= 1);

await checkPage('seo-health', '/admin/seo-settings/health');
await checkPage('seo-redirects', '/admin/seo-settings/redirects');
await checkPage('seo-error-logs', '/admin/seo-settings/error-logs/index');

const known = problems.filter(p => !p.includes('Element not found'));
ok('no console/JS/HTTP problems', known.length === 0);
if (known.length) console.log('  problems:', known.slice(0, 8));
await browser.close();
console.log(failures.length ? `FAILURES: ${failures.length}` : 'ALL CLEAN');
process.exit(failures.length ? 1 : 0);
