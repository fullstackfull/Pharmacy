/** Developer portal: live API reference with version tabs, endpoint filter,
 *  auth badges, guides with snippets; version badge in the sidebar. */
import { chromium } from '@playwright/test';
import { BASE, ADMIN, watch, hasServerError } from './_env.mjs';

const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
const page = await browser.newPage({ viewport: { width: 1440, height: 1200 } });
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

await page.goto(BASE + '/admin/developer', { waitUntil: 'load' });
ok('renders', !(await hasServerError(page)));
ok('version badge in header', (await page.locator('#dev-portal .k-badge--info').first().innerText()).startsWith('v1.'));
ok('version pinned in sidebar', (await page.locator('.v2-version-badge').innerText()).trim().startsWith('v1.'));
ok('guides with snippets', await page.locator('.k-dev-snippet').count() >= 4);

const versionTabs = await page.locator('#dev-version-tabs .k-tab').allInnerTexts();
ok('version tabs from live routes', versionTabs.includes('v1') && versionTabs.some(v => /v[23]/.test(v)));
const rowsV1 = await page.locator('[data-version-panel="v1"] [data-endpoint]').count();
ok('v1 endpoints listed (>50)', rowsV1 > 50);
ok('bearer badges present', await page.locator('[data-version-panel="v1"] .k-badge--info').count() > 0);

// Filter narrows and force-opens matching groups.
await page.fill('#dev-filter', 'banner');
await page.waitForTimeout(400);
const filtered = await page.evaluate(() => {
    const rows = [...document.querySelectorAll('[data-version-panel="v1"] [data-endpoint]')];
    return rows.filter(r => !r.hidden).length;
});
ok('filter narrows to banner endpoints', filtered >= 1 && filtered < rowsV1);

// Switch to a seller version tab.
const sellerTab = versionTabs.find(v => /v3/.test(v));
if (sellerTab) {
    await page.fill('#dev-filter', '');
    await page.click(`#dev-version-tabs .k-tab[data-version="${sellerTab}"]`);
    await page.waitForTimeout(300);
    ok('v3 seller panel opens', await page.locator(`[data-version-panel="${sellerTab}"] [data-endpoint]`).count() > 0);
} else {
    ok('v3 tab exists', false);
}

ok('no console/JS/HTTP problems', problems.length === 0);
if (problems.length) console.log('  problems:', problems.slice(0, 6));
await browser.close();
console.log(failures.length ? `FAILURES: ${failures.length}` : 'ALL CLEAN');
process.exit(failures.length ? 1 : 0);
