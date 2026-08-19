/**
 * The banner grid: home promo banners (bound to no category) and the layout
 * option shared with category section banners.
 *
 * Creates, through the real admin form, a full-width promo, two halves and two
 * sliders, then checks the home page lays them out as asked — halves side by
 * side on one row, sliders pooled into a single rotating slot — and that the
 * app endpoint reports the same layouts in the same order while the installed
 * apps' banner feed stays unchanged.
 */
import { chromium } from '@playwright/test';
import { BASE, watch, loginAdmin, hasServerError } from './_env.mjs';

const WEB_IMAGE = 'public/assets/front-end/img/image-place-holder.png';
const MOBILE_IMAGE = 'public/assets/front-end/img/image-place-holder-2_1.png';
const PROMOS = [
    { layout: 'full', priority: 1 },
    { layout: 'half', priority: 2 },
    { layout: 'half', priority: 3 },
    { layout: 'slider', priority: 4 },
    { layout: 'slider', priority: 5 },
];

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
// the publish loop leaves a navigation in flight now and then; retry once
const go = async (url) => {
    await page.goto(url, { waitUntil: 'load' }).catch(async () => {
        await page.waitForTimeout(2000);
        await page.goto(url, { waitUntil: 'load' });
    });
};
const api = async (path) => page.evaluate(async (url) => {
    const response = await fetch(url, { headers: { Accept: 'application/json' } });
    return { status: response.status, body: await response.json().catch(() => null) };
}, path);

await loginAdmin(page);

// ---- start from a clean slate so a re-run asserts on its own banners --------
const promoRows = () => page.locator('table tbody tr').filter({ hasText: /Home Promo Banner|بانر ترويجي/i });
for (let attempt = 0; attempt < 20; attempt++) {
    await go(BASE + '/admin/banner/list');
    await dismissNewOrderPopup();
    if (await promoRows().count() === 0) break;
    await promoRows().first().locator('.banner-delete-button').first().click();
    await page.waitForSelector('.swal2-confirm', { timeout: 6000 }).catch(() => {});
    if (await page.locator('.swal2-confirm').count() > 0) await page.locator('.swal2-confirm').click();
    await page.waitForLoadState('load').catch(() => {});
    await page.waitForTimeout(1800);
}
ok('setup: no leftover promo banners', await promoRows().count() === 0);

// ---- the layout controls only show for the grid types -----------------------
await go(BASE + '/admin/banner/list');
await page.locator('#main-banner-add').click();
await page.locator('form.banner_form').waitFor({ state: 'visible', timeout: 6000 });
await page.waitForTimeout(600);
const layoutVisible = () => page.locator('#banner_layout_group').isVisible();
await setSelect('#banner_type_select', 'Main Banner');
await page.waitForTimeout(400);
ok('admin: layout controls hidden for a plain banner type', !(await layoutVisible()));
await setSelect('#banner_type_select', 'Home Promo Banner');
await page.waitForTimeout(400);
ok('admin: layout controls shown for the promo type', await layoutVisible());

// ---- create the grid --------------------------------------------------------
for (const [index, promo] of PROMOS.entries()) {
    if (index > 0) {
        await go(BASE + '/admin/banner/list');
        await page.locator('#main-banner-add').click();
        await page.locator('form.banner_form').waitFor({ state: 'visible', timeout: 6000 });
        await page.waitForTimeout(600);
        await setSelect('#banner_type_select', 'Home Promo Banner');
    }
    await setSelect('select[name="resource_type"]', 'custom');
    await page.waitForTimeout(400);
    await page.locator('form.banner_form input[name="url"]').fill(`${BASE}/products?data_from=latest`);
    await setSelect('form.banner_form select[name="layout"]', promo.layout);
    await page.locator('form.banner_form input[name="priority"]').fill(String(promo.priority));
    await page.setInputFiles('form.banner_form input[name="image"]', WEB_IMAGE);
    await page.setInputFiles('form.banner_form input[name="mobile_image"]', MOBILE_IMAGE);
    await page.waitForTimeout(600);
    await dismissNewOrderPopup();
    await page.locator('form.banner_form button[type="submit"]').last().click();
    await page.waitForLoadState('load').catch(() => {});
    await page.waitForTimeout(2000);
}
ok('admin: all five promo banners saved', !(await hasServerError(page)));

// publish them all — one per fresh page load, so a toggle never races the reload
await go(BASE + '/admin/banner/list');
await dismissNewOrderPopup();
ok('admin: the list shows the five promo banners', await promoRows().count() === PROMOS.length);

for (let attempt = 0; attempt <= PROMOS.length; attempt++) {
    await go(BASE + '/admin/banner/list');
    await dismissNewOrderPopup();
    const unpublished = promoRows().locator('input[type="checkbox"]:not(:checked)');
    if (await unpublished.count() === 0) break;
    await unpublished.first().dispatchEvent('click');
    const confirmButton = page.locator('.modal.show button[id$="-ok-button"]');
    await confirmButton.first().waitFor({ state: 'visible', timeout: 6000 }).catch(() => {});
    if (await confirmButton.count() > 0) await confirmButton.first().click();
    await page.waitForLoadState('load').catch(() => {});
    await page.waitForTimeout(2000);
}
ok('admin: every promo banner is published',
    await promoRows().locator('input[type="checkbox"]:not(:checked)').count() === 0);

// ---- home page layout -------------------------------------------------------
await go(BASE + '/');
ok('home: renders', !(await hasServerError(page)));
const grid = page.locator('.banner-grid').first();
ok('home: the promo grid rendered', await grid.count() === 1);
ok('home: three tiles (one full, two halves)', await grid.locator('.banner-grid__item:not(.banner-grid__slider)').count() === 3);
ok('home: the two halves are marked half', await grid.locator('.banner-grid__item--half').count() === 2);
ok('home: the sliders share one slot', await grid.locator('.banner-grid__slider').count() === 1);
// owl clones slides to loop, so count only the real ones
ok('home: both slider banners are inside that slot',
    await grid.locator('.banner-grid__slider .owl-item:not(.cloned) .banner-grid__slide').count() === 2);

// the halves must actually sit on one row, and the full one on its own
const geometry = await grid.evaluate((element) => {
    const boxes = [...element.querySelectorAll('.banner-grid__item')].map((item) => {
        const rect = item.getBoundingClientRect();
        return { top: Math.round(rect.top), width: Math.round(rect.width), half: item.classList.contains('banner-grid__item--half') };
    });
    return { boxes, gridWidth: Math.round(element.getBoundingClientRect().width) };
});
const halves = geometry.boxes.filter(box => box.half);
ok('home: the two halves share a row', halves.length === 2 && halves[0].top === halves[1].top);
ok('home: a half is about half the grid', halves.every(box => Math.abs(box.width - geometry.gridWidth / 2) < 24));
const fullTile = geometry.boxes.find(box => !box.half);
ok('home: a full tile spans the grid', Math.abs(fullTile.width - geometry.gridWidth) < 4);

// the slider must have initialised (owl adds its own classes and nav)
await page.waitForTimeout(1500);
ok('home: the slider initialised with nav and dots',
    await grid.locator('.banner-grid-slider.owl-loaded').count() === 1
    && await grid.locator('.banner-grid__slider .owl-dots .owl-dot').count() >= 2);

// ---- app API ----------------------------------------------------------------
const promos = await api('/api/v1/banners/home-promos?guest_id=1');
ok('api: home-promos 200', promos.status === 200);
ok('api: returns the five banners in priority order',
    JSON.stringify((promos.body || []).map(b => b.layout)) === JSON.stringify(PROMOS.map(p => p.layout)));
ok('api: each promo carries both images',
    (promos.body || []).every(b => b.photo_full_url && b.mobile_photo_full_url));
const feed = await api('/api/v1/banners?guest_id=1');
ok('api: the installed apps banner feed excludes promo banners',
    feed.status === 200 && !(feed.body || []).some(b => b.banner_type === 'Home Promo Banner'));

const known = problems.filter(p => !p.includes('Element not found') && !p.includes('google') && !p.includes('maps'));
ok('no console/JS/HTTP problems', known.length === 0);
if (known.length) console.log('  problems:', known.slice(0, 8));
await browser.close();
console.log(failures.length ? `FAILURES: ${failures.length}` : 'ALL CLEAN');
process.exit(failures.length ? 1 : 0);
