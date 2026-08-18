/**
 * Second admin batch on the data-view: brands (offcanvas add/view/edit +
 * no-reload switcher), employees (role filter select in the toolbar, admin
 * row locked), and deliverymen (sorting dropdown + badge links).
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

// ---- Brands --------------------------------------------------------------
await page.goto(BASE + '/admin/brand/list', { waitUntil: 'load' });
ok('brands: renders', !(await hasServerError(page)));
ok('brands: data-view present', (await page.locator('.k-view').count()) === 1);
const brandRows = await page.locator('.k-view .k-table tbody tr').count();
ok('brands: rows render', brandRows >= 1);
if (brandRows >= 1) {
    ok('brands: no-reload switcher', (await page.locator('.k-view form.no-reload-form .custom-modal-plugin').count()) >= 1);
    ok('brands: offcanvas actions + shells', (await page.locator('.k-view [href^="#brandViewOffcanvas-"]').count()) >= 1
        && (await page.locator('[id^="brandViewOffcanvas-"]').count()) >= 1
        && (await page.locator('#brandAddOffcanvas').count()) === 1);
}
ok('brands: export + add actions', (await page.locator('.k-view a[href*="/brand/export"]').count()) === 1
    && (await page.locator('.k-view [href="#brandAddOffcanvas"]').count()) === 1);

// ---- Employees -----------------------------------------------------------
await page.goto(BASE + '/admin/employee/list', { waitUntil: 'load' });
ok('employees: renders', !(await hasServerError(page)));
ok('employees: data-view present', (await page.locator('.k-view').count()) === 1);
ok('employees: rows render', (await page.locator('.k-view .k-table tbody tr').count()) >= 1);
ok('employees: role filter in toolbar', (await page.locator('.k-view select[name="admin_role_id"]').count()) === 1);
ok('employees: row controls render (badge or switcher)', (await page.locator('.k-view .k-badge').count()) >= 1
    || (await page.locator('.k-view form.no-reload-form .custom-modal-plugin').count()) >= 1);
ok('employees: export + add actions', (await page.locator('.k-view a[href*="/employee/export"]').count()) === 1
    && (await page.locator('.k-view a[href*="/employee/add"]').count()) === 1);

// ---- Deliverymen ---------------------------------------------------------
await page.goto(BASE + '/admin/delivery-man/list', { waitUntil: 'load' });
ok('deliverymen: renders', !(await hasServerError(page)));
ok('deliverymen: data-view present', (await page.locator('.k-view').count()) === 1);
ok('deliverymen: rows or honest empty', (await page.locator('.k-view .k-table tbody tr').count()) >= 1
    || (await page.locator('.k-view .k-empty').count()) === 1);
ok('deliverymen: sorting dropdown present', (await page.locator('.k-view [data-bs-toggle="dropdown"]').count()) === 1);

// Search on brands round-trips.
await page.goto(BASE + '/admin/brand/list', { waitUntil: 'load' });
const firstBrand = ((await page.locator('.k-view .k-table tbody tr .k-truncate').first().innerText().catch(() => '')) || '').trim().split(/\s/)[0];
if (firstBrand) {
    await page.fill('.k-view input[name="searchValue"]', firstBrand);
    await page.press('.k-view input[name="searchValue"]', 'Enter');
    await page.waitForLoadState('load');
    ok('brands: search round-trips', !(await hasServerError(page))
        && (await page.locator('.k-view .k-table tbody tr').count()) >= 1);
}

const known = problems.filter(p => !p.includes('Element not found'));
ok('no console/JS/HTTP problems', known.length === 0);
if (known.length) console.log('  problems:', known.slice(0, 8));
await browser.close();
console.log(failures.length ? `FAILURES: ${failures.length}` : 'ALL CLEAN');
process.exit(failures.length ? 1 : 0);
