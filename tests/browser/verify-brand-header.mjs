/**
 * Brand landing header and the placement guide.
 *
 * - a "Brand Banner" created through the real admin form heads that brand's page
 * - the page offers only the categories its products actually live in, each with
 *   a count, and picking one narrows the list to that category AND that brand
 * - a brand with no banner still gets its filter chips
 * - the placement guide renders a wireframe for every banner type and layout
 */
import { chromium } from '@playwright/test';
import { BASE, watch, loginAdmin, hasServerError } from './_env.mjs';

const BRAND_SLUG = process.env.BRAND_SLUG || 'vitamin-factory';
const BRAND_ID = process.env.BRAND_ID || '3';
const WEB_IMAGE = 'public/assets/front-end/img/image-place-holder.png';

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
const go = async (url) => {
    await page.goto(url, { waitUntil: 'load' }).catch(async () => {
        await page.waitForTimeout(2000);
        await page.goto(url, { waitUntil: 'load' });
    });
};

await loginAdmin(page);

// ---- clean slate ------------------------------------------------------------
const brandRows = () => page.locator('table tbody tr').filter({ hasText: /Brand Banner|بانر الماركة/i });
for (let attempt = 0; attempt < 20; attempt++) {
    await go(BASE + '/admin/banner/list');
    await dismissNewOrderPopup();
    if (await brandRows().count() === 0) break;
    await brandRows().first().locator('.banner-delete-button').first().click();
    await page.waitForSelector('.swal2-confirm', { timeout: 6000 }).catch(() => {});
    if (await page.locator('.swal2-confirm').count() > 0) await page.locator('.swal2-confirm').click();
    await page.waitForLoadState('load').catch(() => {});
    await page.waitForTimeout(1800);
}

// ---- the brand page works before any banner exists --------------------------
await go(`${BASE}/brand/${BRAND_SLUG}`);
ok('brand page: renders without a banner', !(await hasServerError(page)));
const chips = page.locator('.brand-header__chip');
const chipCount = await chips.count();
ok('brand page: category chips shown (all + at least one category)', chipCount >= 2);
ok('brand page: no banner yet', await page.locator('.category-header__banner').count() === 0);

// every chip must carry a count, and the counts must be real
const chipCounts = await page.locator('.brand-header__chip-count').allTextContents();
ok('brand page: each category chip carries a product count',
    chipCounts.length === chipCount - 1 && chipCounts.every(text => Number(text.trim()) > 0));

// ---- the filter narrows to that category AND stays on the brand -------------
const productCount = () => page.locator('.product-single-hover').count();
const brandOnlyCount = await productCount();
const firstChipCount = Number((await chips.nth(1).locator('.brand-header__chip-count').innerText()).trim());
await chips.nth(1).click();
await page.waitForLoadState('load').catch(() => {});
await page.waitForTimeout(1500);
ok('brand filter: stays on the brand page', page.url().includes(`/brand/${BRAND_SLUG}`));
ok('brand filter: the chosen chip is marked active',
    (await page.locator('.brand-header__chip--active').innerText()).includes(String(firstChipCount)));
const filteredCount = await productCount();
ok('brand filter: shows exactly the products the chip promised',
    filteredCount === firstChipCount && filteredCount <= brandOnlyCount && !(await hasServerError(page)));
console.log(`  brand alone: ${brandOnlyCount} products, chip "${firstChipCount}" -> ${filteredCount}`);

// a category the brand has nothing in must return nothing, proving the two
// filters intersect instead of one overriding the other
const foreignCategoryId = await page.evaluate(async () => {
    const response = await fetch('/api/v1/categories?guest_id=1', { headers: { Accept: 'application/json' } });
    const categories = await response.json().catch(() => []);
    return Array.isArray(categories) && categories.length ? categories.map(c => c.id) : [];
});
const shownCategoryIds = await page.locator('.brand-header__chip[href*="category_ids"]').evaluateAll(
    links => links.map(link => Number(new URL(link.href).searchParams.get('category_ids[0]'))));
const unusedCategory = (foreignCategoryId || []).find(id => !shownCategoryIds.includes(id));
if (unusedCategory) {
    await go(`${BASE}/brand/${BRAND_SLUG}?category_ids[0]=${unusedCategory}`);
    ok('brand filter: a category the brand has nothing in returns nothing', await productCount() === 0);
} else {
    console.log('SKIP the brand covers every category, no negative case to check');
}

// ---- admin: create the brand banner ----------------------------------------
await go(BASE + '/admin/banner/list');
await page.locator('#main-banner-add').click();
await page.locator('form.banner_form').waitFor({ state: 'visible', timeout: 6000 });
await page.waitForTimeout(600);
const typeOptions = await page.locator('#banner_type_select option').allTextContents();
ok('admin: Brand Banner offered as a type',
    typeOptions.some(t => /brand\s*banner|بانر\s*الماركة/i.test(t.trim())));
await setSelect('#banner_type_select', 'Brand Banner');
await setSelect('select[name="resource_type"]', 'brand');
await page.waitForTimeout(500);
await setSelect('select[name="brand_id"]', BRAND_ID);
await page.setInputFiles('form.banner_form input[name="image"]', WEB_IMAGE);
await page.waitForTimeout(700);
await dismissNewOrderPopup();
await page.locator('form.banner_form button[type="submit"]').last().click();
await page.waitForLoadState('load').catch(() => {});
await page.waitForTimeout(2500);
ok('admin: brand banner saved', !(await hasServerError(page)) && await brandRows().count() === 1);

await dismissNewOrderPopup();
const toggle = brandRows().first().locator('input[type="checkbox"]').first();
if (!(await toggle.isChecked().catch(() => false))) {
    await toggle.dispatchEvent('click');
    const confirmButton = page.locator('.modal.show button[id$="-ok-button"]');
    await confirmButton.first().waitFor({ state: 'visible', timeout: 6000 }).catch(() => {});
    if (await confirmButton.count() > 0) await confirmButton.first().click();
    await page.waitForLoadState('load').catch(() => {});
    await page.waitForTimeout(2500);
}

// ---- the banner now heads the brand page ------------------------------------
await go(`${BASE}/brand/${BRAND_SLUG}`);
ok('brand page: banner shown above the chips', await page.locator('.category-header__banner').count() === 1);
ok('brand page: banner image resolves', await page.locator('.category-header__banner img')
    .evaluate(img => img.complete && img.naturalWidth > 0).catch(() => false));
const bannerAboveChips = await page.evaluate(() => {
    const banner = document.querySelector('.category-header__banner');
    const chip = document.querySelector('.brand-header__chip');
    return banner && chip ? banner.getBoundingClientRect().top < chip.getBoundingClientRect().top : false;
});
ok('brand page: the banner sits above the filters', bannerAboveChips);

// a different brand must not inherit this banner
const otherBrand = await page.evaluate(async () => {
    const response = await fetch('/api/v1/brands?guest_id=1', { headers: { Accept: 'application/json' } });
    const brands = await response.json().catch(() => []);
    return (Array.isArray(brands) ? brands : brands?.brands ?? []).map(b => b.slug).filter(Boolean);
});
const differentSlug = (otherBrand || []).find(slug => slug !== BRAND_SLUG);
if (differentSlug) {
    await go(`${BASE}/brand/${differentSlug}`);
    ok('brand page: another brand does not inherit the banner',
        await page.locator('.category-header__banner').count() === 0 && !(await hasServerError(page)));
} else {
    console.log('SKIP only one brand available to compare against');
}

// ---- app API ----------------------------------------------------------------
const api = async (path) => page.evaluate(async (url) => {
    const response = await fetch(url, { headers: { Accept: 'application/json' } });
    return { status: response.status, body: await response.json().catch(() => null) };
}, path);

const header = await api(`/api/v1/brands/page-header/${BRAND_ID}?guest_id=1`);
ok('api: brand page-header 200', header.status === 200);
ok('api: returns the brand banner with both images',
    !!header.body?.banner?.photo_full_url && !!header.body?.banner?.mobile_photo_full_url);
ok('api: returns the same categories the web shows',
    (header.body?.categories?.length ?? 0) === chipCount - 1
    && (header.body?.categories ?? []).every(category => category.products_count > 0));
const missingBrand = await api('/api/v1/brands/page-header/999999?guest_id=1');
ok('api: unknown brand 404s', missingBrand.status === 404);
const feed = await api('/api/v1/banners?guest_id=1');
ok('api: the installed apps banner feed excludes brand banners',
    feed.status === 200 && !(feed.body || []).some(b => b.banner_type === 'Brand Banner'));

// ---- the placement guide ----------------------------------------------------
await go(BASE + '/admin/banner/placement-guide');
ok('guide: renders', !(await hasServerError(page)));
ok('guide: a wireframe for every placement and layout', await page.locator('.bp-wire').count() === 12);
ok('guide: the new types are marked', await page.locator('.k-badge').filter({ hasText: /new|جديد/i }).count() >= 4);
ok('guide: reachable from the banner list',
    await (await go(BASE + '/admin/banner/list'), page.locator('a[href$="/admin/banner/placement-guide"]').count()) === 1);

const known = problems.filter(p => !p.includes('Element not found') && !p.includes('google') && !p.includes('maps')
    // the unknown-brand probe above asserts this 404 deliberately
    && !p.includes('page-header/999999'));
ok('no console/JS/HTTP problems', known.length === 0);
if (known.length) console.log('  problems:', known.slice(0, 8));
await browser.close();
console.log(failures.length ? `FAILURES: ${failures.length}` : 'ALL CLEAN');
process.exit(failures.length ? 1 : 0);
