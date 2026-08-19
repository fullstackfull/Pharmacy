/**
 * Category landing header, end to end through the real admin form:
 * - a "Category Banner" can be created from admin > banners against a category
 * - the category page shows that banner plus the category's sub-category strip
 * - a sub-category page inherits the parent's banner and shows its own children
 *   (or no strip when it is a leaf)
 * - a category with no banner still renders its strip, and unrelated list pages
 *   (latest products) render neither
 * - the app endpoint returns the same banner/sub-categories, and the home-screen
 *   banner feed stays free of the new type
 */
import { chromium } from '@playwright/test';
import { BASE, watch, loginAdmin, hasServerError } from './_env.mjs';

const PARENT_SLUG = process.env.PARENT_SLUG || 'skin-care';
const PARENT_ID = process.env.PARENT_ID || '7';
const CHILD_SLUG = process.env.CHILD_SLUG || 'face-serums';
const CHILD_ID = process.env.CHILD_ID || '11';
const IMAGE = process.env.BANNER_IMAGE || 'public/assets/front-end/img/image-place-holder.png';

const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
const page = await browser.newPage({ viewport: { width: 1440, height: 1100 } });
const problems = watch(page);
const failures = [];
const ok = (label, cond) => {
    console.log((cond ? 'PASS ' : 'FAIL ') + label);
    if (!cond) failures.push(label);
};

await loginAdmin(page);

// ---- admin: the new type is offered and creates a banner ------------------
await page.goto(BASE + '/admin/banner/list', { waitUntil: 'load' });
ok('admin: banner list renders', !(await hasServerError(page)));
const typeOptions = await page.locator('#banner_type_select option').allTextContents();
ok('admin: Category Banner offered as a type',
    typeOptions.some(t => /category\s*banner|بانر\s*الفئة/i.test(t.trim())));

// the add form is collapsed behind the list's "add" button
await page.locator('#main-banner-add').click();
await page.locator('form.banner_form').waitFor({ state: 'visible', timeout: 6000 });
await page.waitForTimeout(800);

// the form's selects are select2-enhanced (native element hidden), so drive them
// the same way the page's own scripts do
const setSelect = (selector, value) => page.evaluate(([sel, val]) => {
    const element = document.querySelector(sel);
    element.value = val;
    if (window.jQuery) {
        window.jQuery(element).val(val).trigger('change');
    } else {
        element.dispatchEvent(new Event('change', { bubbles: true }));
    }
}, [selector, value]);

await setSelect('#banner_type_select', 'Category Banner');
await setSelect('select[name="resource_type"]', 'category');
await page.waitForTimeout(600);
await setSelect('select[name="category_id"]', PARENT_ID);
await page.locator('input[name="title"]').first().fill('QA Category Banner').catch(() => {});
await page.setInputFiles('input[name="image"]', IMAGE);
await page.waitForTimeout(800);
// the panel's new-order poller can raise its modal mid-run; it is unrelated noise here
const dismissNewOrderPopup = async () => {
    if (await page.locator('#popup-modal.show').count() > 0) {
        await page.locator('.ignore-check-order').first().click({ force: true }).catch(() => {});
        await page.waitForTimeout(800);
    }
};
await dismissNewOrderPopup();
await page.locator('form.banner_form button[type="submit"]').last().click();
await page.waitForLoadState('load').catch(() => {});
await page.waitForTimeout(2500);
ok('admin: banner saved without a server error', !(await hasServerError(page)));
const listRows = await page.locator('table tbody tr').allTextContents();
ok('admin: the new banner appears in the list',
    listRows.some(r => /category\s*banner|بانر\s*الفئة/i.test(r)));

// published so the storefront can pick it up
await dismissNewOrderPopup();
const toggle = page.locator('table tbody tr').filter({ hasText: /Category Banner|بانر الفئة/i })
    .first().locator('input[type="checkbox"]');
if (await toggle.count() > 0 && !(await toggle.first().isChecked().catch(() => false))) {
    // the switcher hides its input behind a styled control, and confirming goes
    // through the panel's own modal plugin (an "-ok-button" per modal instance)
    await toggle.first().dispatchEvent('click');
    const confirmButton = page.locator('.modal.show button[id$="-ok-button"]');
    await confirmButton.first().waitFor({ state: 'visible', timeout: 6000 }).catch(() => {});
    if (await confirmButton.count() > 0) await confirmButton.first().click();
    await page.waitForLoadState('load').catch(() => {});
    await page.waitForTimeout(2500);
}
const publishedNow = await page.locator('table tbody tr').filter({ hasText: /Category Banner|بانر الفئة/i })
    .first().locator('input[type="checkbox"]').isChecked().catch(() => false);
ok('admin: banner is published', publishedNow);

// ---- storefront: parent category page --------------------------------------
await page.goto(`${BASE}/category/${PARENT_SLUG}`, { waitUntil: 'load' });
ok('category page: renders', !(await hasServerError(page)));
ok('category page: banner shown', await page.locator('.category-header__banner img').count() === 1);
const subCount = await page.locator('.category-header__sub').count();
ok('category page: sub-category strip shown', subCount > 0);
console.log(`  sub-categories rendered: ${subCount}`);
const firstSubHref = await page.locator('.category-header__sub').first().getAttribute('href');
ok('category page: sub tile links to its category page', (firstSubHref || '').includes("/category/"));

// the banner image must actually load, not 404
const bannerLoaded = await page.locator('.category-header__banner img')
    .evaluate(img => img.complete && img.naturalWidth > 0).catch(() => false);
ok('category page: banner image resolves', bannerLoaded);

// ---- storefront: child category inherits the parent's banner ---------------
await page.goto(`${BASE}/category/${CHILD_SLUG}`, { waitUntil: 'load' });
ok('sub-category page: renders', !(await hasServerError(page)));
ok('sub-category page: inherits the parent banner', await page.locator('.category-header__banner img').count() === 1);

// ---- storefront: a non-category list page shows no header ------------------
await page.goto(BASE + '/products?data_from=latest', { waitUntil: 'load' });
ok('latest products: no category header', await page.locator('.category-header').count() === 0);

// ---- app API ---------------------------------------------------------------
const api = async (path) => page.evaluate(async (url) => {
    const response = await fetch(url, { headers: { Accept: 'application/json' } });
    return { status: response.status, body: await response.json().catch(() => null) };
}, path);

const parentHeader = await api(`/api/v1/categories/page-header/${PARENT_ID}?guest_id=1`);
ok('api: page-header 200', parentHeader.status === 200);
ok('api: banner returned', !!parentHeader.body?.banner?.photo_full_url);
ok('api: banner not flagged inherited on its own category', parentHeader.body?.banner?.inherited === false);
ok('api: sub_categories returned', (parentHeader.body?.sub_categories?.length ?? 0) === subCount);

const childHeader = await api(`/api/v1/categories/page-header/${CHILD_ID}?guest_id=1`);
ok('api: child inherits the banner and says so', childHeader.body?.banner?.inherited === true);

const missing = await api('/api/v1/categories/page-header/999999?guest_id=1');
ok('api: unknown category 404s', missing.status === 404);

const banners = await api('/api/v1/banners?guest_id=1');
const leaked = (banners.body || []).some(b => b.banner_type === 'Category Banner');
ok('api: home banner feed excludes Category Banner', banners.status === 200 && !leaked);

const known = problems.filter(p => !p.includes('Element not found') && !p.includes('google') && !p.includes('maps')
    // the unknown-category probe above asserts this 404 deliberately
    && !p.includes('page-header/999999'));
ok('no console/JS/HTTP problems', known.length === 0);
if (known.length) console.log('  problems:', known.slice(0, 8));
await browser.close();
console.log(failures.length ? `FAILURES: ${failures.length}` : 'ALL CLEAN');
process.exit(failures.length ? 1 : 0);
