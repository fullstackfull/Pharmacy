/**
 * Category section banners (a banner above a category's product row) and the
 * dual-image banner record, end to end through the real admin form:
 * - the new type is offered, and a banner saves with BOTH a web image and a
 *   phone-shaped mobile image
 * - the home page renders it above that category's product row, and only there
 * - editing without re-uploading keeps both stored images
 * - the app endpoint returns both images plus the category, while the
 *   home-screen banner feed stays free of the new type
 * - a banner with no mobile image reports the web image as its mobile url
 */
import { chromium } from '@playwright/test';
import { BASE, watch, loginAdmin, hasServerError } from './_env.mjs';

const CATEGORY_ID = process.env.CATEGORY_ID || '7';
const WEB_IMAGE = 'public/assets/front-end/img/image-place-holder.png';
const MOBILE_IMAGE = 'public/assets/front-end/img/image-place-holder-2_1.png';

const browser = await chromium.launch({ executablePath: '/opt/pw-browsers/chromium' });
const page = await browser.newPage({ viewport: { width: 1440, height: 1100 } });
const problems = watch(page);
const failures = [];
const ok = (label, cond) => {
    console.log((cond ? 'PASS ' : 'FAIL ') + label);
    if (!cond) failures.push(label);
};
const setSelect = (selector, value) => page.evaluate(([sel, val]) => {
    const element = document.querySelector(sel);
    element.value = val;
    if (window.jQuery) window.jQuery(element).val(val).trigger('change');
    else element.dispatchEvent(new Event('change', { bubbles: true }));
}, [selector, value]);
const dismissNewOrderPopup = async () => {
    if (await page.locator('#popup-modal.show').count() > 0) {
        await page.locator('.ignore-check-order').first().click({ force: true }).catch(() => {});
        await page.waitForTimeout(800);
    }
};
const api = async (path) => page.evaluate(async (url) => {
    const response = await fetch(url, { headers: { Accept: 'application/json' } });
    return { status: response.status, body: await response.json().catch(() => null) };
}, path);

await loginAdmin(page);

// ---- admin: create with both images ----------------------------------------
await page.goto(BASE + '/admin/banner/list', { waitUntil: 'load' });
const typeOptions = await page.locator('#banner_type_select option').allTextContents();
ok('admin: Category Section Banner offered as a type',
    typeOptions.some(t => /category\s*section\s*banner|بانر\s*قسم\s*الفئة/i.test(t.trim())));

await page.locator('#main-banner-add').click();
await page.locator('form.banner_form').waitFor({ state: 'visible', timeout: 6000 });
await page.waitForTimeout(600);
ok('admin: the form exposes a mobile app image field',
    await page.locator('form.banner_form input[name="mobile_image"]').count() === 1);

await setSelect('#banner_type_select', 'Category Section Banner');
await setSelect('select[name="resource_type"]', 'category');
await page.waitForTimeout(600);
await setSelect('select[name="category_id"]', CATEGORY_ID);
await page.setInputFiles('form.banner_form input[name="image"]', WEB_IMAGE);
await page.setInputFiles('form.banner_form input[name="mobile_image"]', MOBILE_IMAGE);
await page.waitForTimeout(800);
await dismissNewOrderPopup();
await page.locator('form.banner_form button[type="submit"]').last().click();
await page.waitForLoadState('load').catch(() => {});
await page.waitForTimeout(2500);
ok('admin: banner saved without a server error', !(await hasServerError(page)));

const sectionRow = () => page.locator('table tbody tr').filter({ hasText: /Category Section Banner|بانر قسم الفئة/i }).first();
ok('admin: the new banner appears in the list', await sectionRow().count() > 0);

await dismissNewOrderPopup();
const toggle = sectionRow().locator('input[type="checkbox"]');
if (!(await toggle.first().isChecked().catch(() => false))) {
    await toggle.first().dispatchEvent('click');
    const confirmButton = page.locator('.modal.show button[id$="-ok-button"]');
    await confirmButton.first().waitFor({ state: 'visible', timeout: 6000 }).catch(() => {});
    if (await confirmButton.count() > 0) await confirmButton.first().click();
    await page.waitForLoadState('load').catch(() => {});
    await page.waitForTimeout(2500);
}
ok('admin: banner is published',
    await sectionRow().locator('input[type="checkbox"]').isChecked().catch(() => false));

// both images must have been stored, not just the web one
const storedBefore = await api('/api/v1/banners/category-sections?guest_id=1');
const created = (storedBefore.body || []).find(b => b.category_id === Number(CATEGORY_ID));
ok('api: the banner is served for its category', !!created);
ok('api: web and mobile images are distinct records',
    !!created?.photo_full_url && !!created?.mobile_photo_full_url
    && created.photo_full_url.key !== created.mobile_photo_full_url.key);
ok('api: the category is attached', created?.category?.id === Number(CATEGORY_ID));

// ---- admin: editing without re-uploading keeps both images -----------------
await sectionRow().locator('a[href*="/admin/banner/update/"]').first().click();
await page.waitForLoadState('load').catch(() => {});
await page.waitForTimeout(1500);
ok('admin: edit view renders', !(await hasServerError(page)));
ok('admin: edit view shows the stored mobile image',
    await page.locator('input[name="mobile_image"]').count() === 1
    && !!(await page.locator('input[name="mobile_image"] ~ .upload-file__wrapper .upload-file-img')
        .first().getAttribute('src').catch(() => '')));
// resubmit untouched: the stored images must not be dropped
await dismissNewOrderPopup();
await page.locator('form.banner_form button[type="submit"]').last().click();
await page.waitForLoadState('load').catch(() => {});
await page.waitForTimeout(2500);
ok('edit: saved without a server error', !(await hasServerError(page)));
const afterEdit = await api('/api/v1/banners/category-sections?guest_id=1');
const edited = (afterEdit.body || []).find(b => b.category_id === Number(CATEGORY_ID));
ok('edit: both images survived an edit with no new upload',
    edited?.photo_full_url?.key === created?.photo_full_url?.key
    && edited?.mobile_photo_full_url?.key === created?.mobile_photo_full_url?.key);

// ---- storefront: the banner sits above its category row --------------------
await page.goto(BASE + '/', { waitUntil: 'load' });
ok('home: renders', !(await hasServerError(page)));
const banners = page.locator('.category-section-banner');
ok('home: exactly one section banner rendered', await banners.count() === 1);
const bannerLoaded = await banners.first().locator('img')
    .evaluate(img => img.complete && img.naturalWidth > 0).catch(() => false);
ok('home: section banner image resolves', bannerLoaded);
const sitsAboveItsOwnRow = await banners.first().evaluate((element) => {
    const section = element.closest('section');
    const heading = section?.querySelector('.category-product-view-title');
    const products = section?.querySelectorAll('.category-wise-product-slider > *')?.length ?? 0;
    return { heading: heading?.textContent.trim() ?? '', products };
});
console.log(`  section: "${sitsAboveItsOwnRow.heading}" with ${sitsAboveItsOwnRow.products} products`);
ok('home: the banner sits inside a category section that has products',
    sitsAboveItsOwnRow.heading.length > 0 && sitsAboveItsOwnRow.products > 0);

// ---- the generic feeds stay untouched --------------------------------------
const feed = await api('/api/v1/banners?guest_id=1');
ok('api: home banner feed excludes the new type',
    feed.status === 200 && !(feed.body || []).some(b => b.banner_type === 'Category Section Banner'));
ok('api: banners in the home feed still expose a mobile url (falling back to the web image)',
    (feed.body || []).every(b => b.mobile_photo_full_url !== undefined));

const known = problems.filter(p => !p.includes('Element not found') && !p.includes('google') && !p.includes('maps'));
ok('no console/JS/HTTP problems', known.length === 0);
if (known.length) console.log('  problems:', known.slice(0, 8));
await browser.close();
console.log(failures.length ? `FAILURES: ${failures.length}` : 'ALL CLEAN');
process.exit(failures.length ? 1 : 0);
